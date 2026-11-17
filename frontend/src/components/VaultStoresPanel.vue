<template>
  <div class="tab-content" data-testid="admin-storage-tab">
    <h3>{{ t('vaultStores.title') }}</h3>
    <p class="admin-note">{{ t('vaultStores.help') }}</p>
    <p class="admin-note vault-warning" data-testid="vault-store-access-warning">{{ t('vaultStores.directAccessWarning') }}</p>

    <GitHubAppPanel v-if="drivers.includes('github')" />

    <div v-if="error" class="admin-error-banner">{{ error }}</div>

    <label class="store-field tenant-picker">
      <span>{{ t('vaultStores.organization') }}</span>
      <select v-model.number="tenantId" data-testid="vault-store-tenant" @change="refresh">
        <option :value="null" disabled>{{ t('adminPanel.selectTenant') }}</option>
        <option v-for="tenant in tenants" :key="tenant.id" :value="tenant.id">{{ tenant.name }}</option>
      </select>
    </label>

    <template v-if="tenantId">
      <h4>{{ t('vaultStores.listTitle') }}</h4>
      <p v-if="notice" class="store-notice" role="status" data-testid="vault-store-notice">{{ notice }}</p>
      <ul class="store-list" data-testid="vault-store-list">
        <li
          v-for="store in stores"
          :key="store.id"
          :class="['store-card', { 'store-card-new': store.id === highlightId }]"
          :data-testid="`vault-store-${store.id}`"
        >
          <div class="store-card-head">
            <strong class="store-name">{{ store.name }}</strong>
            <span :class="['store-status', `store-status-${store.status}`]" :data-testid="`vault-store-status-${store.id}`">
              {{ t(`vaultStores.status.${store.status}`) }}
            </span>
          </div>
          <div class="store-meta">
            {{ driverLabel(store.driver) }}
            · {{ t('vaultStores.workspaceCount', { count: store.workspaces }) }}
            <template v-if="store.last_checked_at"> · {{ t('vaultStores.lastChecked', { date: formatDate(store.last_checked_at) }) }}</template>
          </div>
          <small v-if="store.driver === 's3' && store.settings?.bucket_state" class="admin-note" :data-testid="`vault-store-versioning-${store.id}`">
            {{ t(`vaultStores.s3.versioning.${store.settings.bucket_state.versioning ?? 'unknown'}`) }}
          </small>
          <p
            v-if="testResults[store.id]"
            :class="['store-test-result', testResults[store.id].ok ? 'store-test-ok' : 'store-test-failed']"
            role="status"
            :data-testid="`vault-store-test-result-${store.id}`"
          >
            {{ testResults[store.id].message }}
          </p>
          <p v-else-if="store.last_error" class="store-test-result store-test-failed">{{ store.last_error }}</p>
          <div class="store-actions">
            <button class="primary-btn" :disabled="loading" :data-testid="`vault-store-test-${store.id}`" @click="testStore(store)">
              {{ testingId === store.id ? t('vaultStores.testing') : t('vaultStores.test') }}
            </button>
            <button v-if="store.driver === 'github'" class="secondary-btn" :disabled="loading" :data-testid="`vault-store-webhook-${store.id}`" @click="showWebhook(store)">{{ t('vaultStores.github.webhook') }}</button>
            <button class="warning-btn" :disabled="loading || store.workspaces > 0" @click="deleteStore(store)">{{ t('vaultStores.delete') }}</button>
          </div>
        </li>
        <li v-if="stores.length === 0" class="admin-note">{{ t('vaultStores.empty') }}</li>
      </ul>

      <div v-if="webhook" class="admin-note github-webhook" data-testid="vault-store-webhook-info">
        <template v-if="webhook.mode === 'app'">{{ t('vaultStores.github.webhookApp') }}</template>
        <template v-else>
          {{ t('vaultStores.github.webhookPat') }}
          <br /><strong>URL:</strong> <code>{{ webhook.url }}</code>
          <br /><strong>Secret:</strong> <code data-testid="vault-store-webhook-secret">{{ webhook.secret }}</code>
          <br />{{ t('vaultStores.github.webhookEvents') }}
        </template>
        <button type="button" @click="webhook = null">{{ t('vaultStores.github.close') }}</button>
      </div>

      <form class="admin-form store-form" data-testid="vault-store-form" @submit.prevent="createStore">
        <h4>{{ t('vaultStores.add') }}</h4>
        <label class="store-field">
          <span>{{ t('vaultStores.fields.driver') }}</span>
          <select v-model="form.driver" data-testid="vault-store-driver" required>
            <option v-for="driver in drivers" :key="driver" :value="driver">{{ driverLabel(driver) }}</option>
          </select>
        </label>
        <template v-if="form.driver === 'local'">
          <label class="store-field">
            <span>{{ t('vaultStores.fields.root') }}</span>
            <input v-model="form.settings.root" required />
          </label>
        </template>
        <template v-else-if="form.driver === 's3'">
          <label class="store-field">
            <span>{{ t('vaultStores.fields.endpoint') }}</span>
            <input v-model="form.settings.endpoint" placeholder="https://…" required />
          </label>
          <label class="store-field">
            <span>{{ t('vaultStores.fields.bucket') }}</span>
            <input v-model="form.settings.bucket" required />
          </label>
          <label class="store-field">
            <span>{{ t('vaultStores.fields.region') }}</span>
            <input v-model="form.settings.region" />
          </label>
          <label class="store-field">
            <span>{{ t('vaultStores.fields.prefix') }}</span>
            <input v-model="form.settings.prefix" />
          </label>
          <label class="admin-check"><input v-model="pathStyle" type="checkbox" data-testid="vault-store-path-style" /> {{ t('vaultStores.fields.pathStyle') }}</label>
          <p class="admin-note">{{ t('vaultStores.s3.versioningHelp') }}</p>
          <label class="store-field">
            <span>{{ t('vaultStores.fields.accessKey') }}</span>
            <input v-model="form.credentials.access_key_id" autocomplete="off" required />
          </label>
          <label class="store-field">
            <span>{{ t('vaultStores.fields.secretKey') }}</span>
            <input v-model="form.credentials.secret_access_key" type="password" autocomplete="new-password" required />
          </label>
          <p class="admin-note">{{ t('vaultStores.credentialsWriteOnly') }}</p>
        </template>
        <template v-else-if="form.driver === 'github'">
          <p class="admin-note vault-warning" data-testid="github-access-warning">{{ t('vaultStores.github.accessWarning') }}</p>
          <button v-if="github?.app_configured" type="button" class="secondary-btn" :disabled="loading" data-testid="github-install" @click="installApp">
            {{ t('vaultStores.github.install') }}
          </button>
          <label class="store-field">
            <span>{{ t('vaultStores.fields.connection') }}</span>
            <select v-model="form.settings.installation_id" data-testid="github-installation" @change="loadRepositories">
              <option value="">{{ t('vaultStores.github.patMode') }}</option>
              <option v-for="installation in github?.installations ?? []" :key="installation.installation_id" :value="String(installation.installation_id)">
                {{ t('vaultStores.github.installation', { account: installation.account_login ?? installation.installation_id }) }}
              </option>
            </select>
          </label>
          <label v-if="form.settings.installation_id" class="store-field">
            <span>{{ t('vaultStores.fields.repo') }}</span>
            <select v-model="pickedRepository" data-testid="github-repository" required @change="pickRepository">
              <option value="" disabled>{{ t('vaultStores.github.chooseRepository') }}</option>
              <option v-for="repository in repositories" :key="repository.full_name" :value="repository.full_name" :disabled="!repository.private">
                {{ repository.full_name }}{{ repository.private ? '' : ` (${t('vaultStores.github.public')})` }}
              </option>
            </select>
          </label>
          <template v-else>
            <label class="store-field">
              <span>{{ t('vaultStores.fields.owner') }}</span>
              <input v-model="form.settings.owner" required />
            </label>
            <label class="store-field">
              <span>{{ t('vaultStores.fields.repo') }}</span>
              <input v-model="form.settings.repo" required />
            </label>
          </template>
          <label class="store-field">
            <span>{{ t('vaultStores.fields.branch') }}</span>
            <input v-model="form.settings.branch" placeholder="main" />
          </label>
          <label v-if="!form.settings.installation_id" class="store-field">
            <span>{{ t('vaultStores.fields.token') }}</span>
            <input v-model="form.credentials.token" type="password" autocomplete="new-password" required />
          </label>
          <label class="github-controller">
            <input v-model="controllerConfirmed" type="checkbox" required data-testid="github-controller" />
            {{ t('vaultStores.github.controller') }}
          </label>
        </template>
        <label class="store-field">
          <span>{{ t('vaultStores.name') }}</span>
          <input v-model="form.name" :placeholder="t('vaultStores.namePlaceholder')" data-testid="vault-store-name" required />
        </label>
        <button type="submit" class="primary-btn" :disabled="loading" data-testid="vault-store-submit">{{ t('vaultStores.save') }}</button>
      </form>

      <h4>{{ t('vaultStores.moveTitle') }}</h4>
      <p class="admin-note">{{ t('vaultStores.moveHelp') }}</p>
      <form class="admin-form move-form" data-testid="vault-move-form" @submit.prevent="startMove">
        <select v-model.number="moveWorkspaceId" required @change="fetchMoves">
          <option :value="null" disabled>{{ t('vaultStores.chooseWorkspace') }}</option>
          <option v-for="ws in tenantWorkspaces" :key="ws.id" :value="ws.id">{{ ws.name }}</option>
        </select>
        <select v-model="moveTarget" required>
          <option value="local">{{ t('vaultStores.localFolder') }}</option>
          <option v-for="store in stores" :key="store.id" :value="String(store.id)">{{ store.name }}</option>
        </select>
        <button type="submit" class="primary-btn" :disabled="loading || !moveWorkspaceId" data-testid="vault-move-submit">{{ t('vaultStores.startMove') }}</button>
      </form>

      <ul v-if="moveWarnings.length" class="admin-note vault-warning" data-testid="vault-move-warnings">
        <li v-for="warning in moveWarnings" :key="warning">{{ warning }}</li>
      </ul>
      <ul v-if="moves.length" class="admin-list" data-testid="vault-move-list">
        <li v-for="move in moves" :key="move.id" class="admin-list-item">
          <span>
            #{{ move.id }} · {{ t(`vaultStores.moveStatus.${move.status}`) }}
            · {{ move.files_copied }}/{{ move.files_total }} ({{ move.percent }}%)
            <progress v-if="move.status === 'copying'" :value="move.percent" max="100"></progress>
            <br />
            <small v-if="move.error" class="admin-note">{{ move.error }}</small>
            <small v-else-if="move.retain_until" class="admin-note">{{ t('vaultStores.retainedUntil', { date: formatDate(move.retain_until) }) }}</small>
          </span>
          <button v-if="move.status === 'completed' && move.retain_until" class="warning-btn" :disabled="loading" @click="rollback(move)">
            {{ t('vaultStores.rollback') }}
          </button>
        </li>
      </ul>
    </template>
  </div>
</template>

<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { api } from '../services/api'
import type { Tenant } from '../services/types'
import GitHubAppPanel from './GitHubAppPanel.vue'

interface VaultStoreRow {
  id: number
  name: string
  driver: string
  status: 'untested' | 'ok' | 'failed'
  last_error: string | null
  last_checked_at?: string | null
  workspaces: number
  settings?: { bucket_state?: { versioning: 'Enabled' | 'Suspended' | 'Off' | null } }
}

interface GitHubConfig {
  app_configured: boolean
  app_slug: string | null
  webhook_url: string
  installations: Array<{ installation_id: number; account_login: string | null }>
}

interface GitHubRepository {
  owner: string
  repo: string
  full_name: string
  private: boolean
  default_branch: string
}

interface WebhookInfo {
  mode: 'app' | 'pat'
  url: string
  secret: string | null
}

interface VaultMoveRow {
  id: number
  status: 'copying' | 'completed' | 'failed' | 'rolled_back' | 'finalized'
  files_total: number
  files_copied: number
  percent: number
  error: string | null
  retain_until: string | null
}

const props = defineProps<{ tenants: Tenant[]; workspaces: Array<{ id: number; name: string; tenant_id: number }>; drivers: string[] }>()
const { t, locale } = useI18n()

const tenantId = ref<number | null>(props.tenants.length === 1 ? props.tenants[0].id : null)
const stores = ref<VaultStoreRow[]>([])
const moves = ref<VaultMoveRow[]>([])
const moveWorkspaceId = ref<number | null>(null)
const moveTarget = ref('local')
const error = ref('')
const loading = ref(false)
// Feedback the admin can see: what was just saved or tested, row by row.
const notice = ref('')
const highlightId = ref<number | null>(null)
const testingId = ref<number | null>(null)
const testResults = ref<Record<number, { ok: boolean; message: string }>>({})
let poller: ReturnType<typeof setInterval> | null = null
// GitHub (#439): App installs of this tenant, the chosen installation's repositories, the PAT webhook.
const github = ref<GitHubConfig | null>(null)
const repositories = ref<GitHubRepository[]>([])
const pickedRepository = ref('')
const controllerConfirmed = ref(false)
const webhook = ref<WebhookInfo | null>(null)
// Back from GitHub's install screen: /?storage=github&tenant=…&installation=…
const returning = new URLSearchParams(window.location.search)
if (returning.get('storage') === 'github') {
  const returnedTenant = Number(returning.get('tenant'))
  if (returnedTenant) tenantId.value = returnedTenant
  window.history.replaceState(null, '', window.location.pathname)
}
const returnedInstallation = returning.get('storage') === 'github' ? (returning.get('installation') ?? '') : ''

const emptyForm = () => ({
  name: '',
  driver: props.drivers.find((driver) => driver !== 'local') ?? 'local',
  settings: { installation_id: '' } as Record<string, string>,
  credentials: {} as Record<string, string>,
})
const form = ref(emptyForm())
const pathStyle = ref(false)
const moveWarnings = ref<string[]>([])

const tenantWorkspaces = computed(() => props.workspaces.filter((ws) => ws.tenant_id === tenantId.value))

function driverLabel(driver: string): string {
  return t(`vaultStores.drivers.${driver}`)
}

function formatDate(value: string): string {
  return new Date(value).toLocaleString(locale.value)
}

function message(e: unknown, fallback: string): string {
  const response = (e as { response?: { data?: { message?: string; errors?: Record<string, string[]> } } }).response
  const firstError = response?.data?.errors ? Object.values(response.data.errors)[0]?.[0] : undefined
  return firstError || response?.data?.message || fallback
}

async function refresh() {
  if (!tenantId.value) return
  error.value = ''
  try {
    const response = await api.get<{ data: VaultStoreRow[] }>(`/admin/tenants/${tenantId.value}/vault-stores`)
    stores.value = response.data.data
  } catch (e: unknown) {
    error.value = message(e, t('adminPanel.requestFailed'))
  }
  await fetchGitHub()
}

async function fetchGitHub() {
  if (!tenantId.value || !props.drivers.includes('github')) return
  try {
    const response = await api.get<{ data: GitHubConfig }>(`/admin/tenants/${tenantId.value}/github`)
    github.value = response.data.data
    if (returnedInstallation && github.value.installations.some((i) => String(i.installation_id) === returnedInstallation) && !form.value.settings.installation_id) {
      form.value.driver = 'github'
      form.value.settings.installation_id = returnedInstallation
      await loadRepositories()
    } else if (github.value.installations.length > 0 && !form.value.settings.installation_id && !form.value.credentials.token) {
      // An installed App is the usual way in; the personal token stays one click away.
      form.value.settings.installation_id = String(github.value.installations[0].installation_id)
      await loadRepositories()
    }
  } catch {
    github.value = null
  }
}

async function installApp() {
  if (!tenantId.value) return
  loading.value = true
  error.value = ''
  try {
    const response = await api.post<{ data: { url: string } }>(`/admin/tenants/${tenantId.value}/github/install`)
    window.location.href = response.data.data.url
  } catch (e: unknown) {
    error.value = message(e, t('adminPanel.requestFailed'))
    loading.value = false
  }
}

async function loadRepositories() {
  repositories.value = []
  pickedRepository.value = ''
  const installation = form.value.settings.installation_id
  if (!tenantId.value || !installation) return
  try {
    const response = await api.get<{ data: { repositories: GitHubRepository[] } }>(`/admin/tenants/${tenantId.value}/github/installations/${installation}/repositories`)
    repositories.value = response.data.data.repositories
  } catch (e: unknown) {
    error.value = message(e, t('adminPanel.requestFailed'))
  }
}

function pickRepository() {
  const repository = repositories.value.find((r) => r.full_name === pickedRepository.value)
  if (!repository) return
  form.value.settings.owner = repository.owner
  form.value.settings.repo = repository.repo
  form.value.settings.branch = repository.default_branch
  if (!form.value.name) form.value.name = repository.full_name
}

async function showWebhook(store: VaultStoreRow) {
  error.value = ''
  try {
    const response = await api.get<{ data: WebhookInfo }>(`/admin/vault-stores/${store.id}/github/webhook`)
    webhook.value = response.data.data
  } catch (e: unknown) {
    error.value = message(e, t('adminPanel.requestFailed'))
  }
}

async function createStore() {
  if (!tenantId.value) return
  loading.value = true
  error.value = ''
  try {
    const credentials = Object.fromEntries(Object.entries(form.value.credentials).filter(([, value]) => value))
    const settings: Record<string, string | boolean> = Object.fromEntries(Object.entries(form.value.settings).filter(([, value]) => value))
    if (form.value.driver === 'github') settings.controller_confirmed = controllerConfirmed.value
    if (form.value.driver === 's3') settings.path_style = pathStyle.value
    const response = await api.post<{ data?: { id: number } }>(`/admin/tenants/${tenantId.value}/vault-stores`, {
      name: form.value.name,
      driver: form.value.driver,
      settings,
      ...(Object.keys(credentials).length ? { credentials } : {}),
    })
    // A PAT store needs its repository webhook: show the URL and secret right away.
    const created = response.data?.data
    notice.value = t('vaultStores.saved', { name: form.value.name })
    highlightId.value = created?.id ?? null
    if (form.value.driver === 'github' && !form.value.settings.installation_id && created?.id) {
      await showWebhook({ id: created.id } as VaultStoreRow)
    }
    form.value = emptyForm()
    pathStyle.value = false
    controllerConfirmed.value = false
    repositories.value = []
    pickedRepository.value = ''
    await refresh()
  } catch (e: unknown) {
    error.value = message(e, t('adminPanel.requestFailed'))
  } finally {
    loading.value = false
  }
}

async function testStore(store: VaultStoreRow) {
  loading.value = true
  testingId.value = store.id
  error.value = ''
  notice.value = ''
  try {
    await api.post(`/admin/vault-stores/${store.id}/test`)
    testResults.value = { ...testResults.value, [store.id]: { ok: true, message: t('vaultStores.testOk', { time: new Date().toLocaleTimeString(locale.value) }) } }
  } catch (e: unknown) {
    testResults.value = { ...testResults.value, [store.id]: { ok: false, message: message(e, t('vaultStores.testFailed')) } }
  } finally {
    loading.value = false
    testingId.value = null
    await refresh()
  }
}

async function deleteStore(store: VaultStoreRow) {
  if (!window.confirm(t('vaultStores.deleteConfirm', { name: store.name }))) return
  loading.value = true
  try {
    await api.delete(`/admin/vault-stores/${store.id}`)
    await refresh()
  } catch (e: unknown) {
    error.value = message(e, t('adminPanel.requestFailed'))
  } finally {
    loading.value = false
  }
}

async function fetchMoves() {
  if (!moveWorkspaceId.value) return
  try {
    const response = await api.get<{ data: { moves: VaultMoveRow[] } }>(`/admin/workspaces/${moveWorkspaceId.value}/vault-moves`)
    moves.value = response.data.data.moves
  } catch (e: unknown) {
    error.value = message(e, t('adminPanel.requestFailed'))
  }
  schedulePolling()
}

function schedulePolling() {
  const copying = moves.value.some((move) => move.status === 'copying')
  if (copying && !poller) {
    poller = setInterval(fetchMoves, 5000)
  } else if (!copying && poller) {
    clearInterval(poller)
    poller = null
    refresh()
  }
}

async function startMove() {
  if (!moveWorkspaceId.value) return
  if (!window.confirm(t('vaultStores.moveConfirm'))) return
  loading.value = true
  error.value = ''
  try {
    moveWarnings.value = []
    const response = await api.post<{ warnings?: string[] }>(`/admin/workspaces/${moveWorkspaceId.value}/vault-moves`, {
      to_store_id: moveTarget.value === 'local' ? null : Number(moveTarget.value),
    })
    moveWarnings.value = response.data?.warnings ?? []
    await fetchMoves()
  } catch (e: unknown) {
    error.value = message(e, t('adminPanel.requestFailed'))
  } finally {
    loading.value = false
  }
}

async function rollback(move: VaultMoveRow) {
  if (!window.confirm(t('vaultStores.rollbackConfirm'))) return
  loading.value = true
  error.value = ''
  try {
    await api.post(`/admin/vault-moves/${move.id}/rollback`)
    await fetchMoves()
  } catch (e: unknown) {
    error.value = message(e, t('adminPanel.requestFailed'))
  } finally {
    loading.value = false
  }
}

watch(() => props.tenants, (tenants) => {
  if (!tenantId.value && tenants.length === 1) {
    tenantId.value = tenants[0].id
  }
})
watch(tenantId, () => {
  notice.value = ''
  highlightId.value = null
  testResults.value = {}
  moves.value = []
  moveWorkspaceId.value = null
  refresh()
}, { immediate: true })

onBeforeUnmount(() => {
  if (poller) clearInterval(poller)
})
</script>

<style scoped>
/* AdminPanel's scoped styles stop at this component: style the controls here. */
.tab-content :is(input:not([type="checkbox"]), select) {
  background: var(--color-canvas);
  border: 1px solid var(--color-border);
  color: var(--color-text);
  padding: var(--space-2);
  border-radius: var(--radius-sm);
  font: inherit;
  max-width: 100%;
}
.tab-content button {
  background: transparent;
  border: 1px solid var(--color-border);
  color: var(--color-text);
  padding: var(--space-1) var(--space-3);
  border-radius: var(--radius-sm);
  font: inherit;
  cursor: pointer;
}
.admin-form > button {
  align-self: flex-start;
}
.tab-content button:disabled {
  opacity: 0.5;
  cursor: default;
}
.tab-content button.primary-btn {
  background: var(--color-action-primary);
  border-color: var(--color-action-primary);
  color: var(--color-action-primary-content);
  align-self: flex-start;
}
.tab-content button.primary-btn:hover:not(:disabled) {
  background: var(--color-action-primary-hover);
}
.tab-content button.warning-btn {
  background: transparent;
  border-color: var(--color-danger-border);
  color: var(--color-danger-fg);
}
.tab-content h4 {
  margin: var(--space-4) 0 var(--space-2);
}
.tenant-picker {
  margin: var(--space-3) 0;
}
.admin-form {
  display: flex;
  flex-direction: column;
  gap: var(--space-2);
  margin-bottom: var(--space-4);
}
.move-form {
  flex-direction: row;
  flex-wrap: wrap;
  align-items: center;
}
.store-list {
  list-style: none;
  padding: 0;
  margin: 0 0 var(--space-4);
  display: flex;
  flex-direction: column;
  gap: var(--space-2);
}
.store-card {
  border: 1px solid var(--color-border);
  border-radius: var(--radius-sm);
  padding: var(--space-3);
  display: flex;
  flex-direction: column;
  gap: var(--space-1);
}
.store-card-new {
  border-color: var(--color-action);
}
.store-card-head {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: var(--space-2);
}
.store-name {
  overflow-wrap: anywhere;
}
.store-meta {
  color: var(--color-text-muted);
  font-size: 0.875rem;
}
.store-status {
  font-size: 0.75rem;
  padding: 0 var(--space-2);
  border-radius: 999px;
  border: 1px solid var(--color-border);
  white-space: nowrap;
}
.store-status-ok {
  color: var(--color-success-fg);
  background: var(--color-success-bg);
  border-color: var(--color-success-border);
}
.store-status-failed {
  color: var(--color-danger-fg);
  background: var(--color-danger-bg);
  border-color: var(--color-danger-border);
}
.store-test-result {
  margin: 0;
  font-size: 0.875rem;
}
.store-test-ok {
  color: var(--color-success-fg);
}
.store-test-failed {
  color: var(--color-danger-fg);
  overflow-wrap: anywhere;
}
.store-actions {
  display: flex;
  flex-wrap: wrap;
  gap: var(--space-2);
  margin-top: var(--space-1);
}
.store-notice {
  color: var(--color-success-fg);
  background: var(--color-success-bg);
  border: 1px solid var(--color-success-border);
  border-radius: var(--radius-sm);
  padding: var(--space-2) var(--space-3);
}
.store-form {
  border: 1px solid var(--color-border);
  border-radius: var(--radius-sm);
  padding: var(--space-3);
  gap: var(--space-3);
}
.store-form h4 {
  margin: 0;
}
.store-field {
  display: flex;
  flex-direction: column;
  gap: var(--space-1);
  font-size: 0.875rem;
}
.store-field > span {
  color: var(--color-text-muted);
}
.vault-warning {
  color: var(--color-warning-fg);
}
.github-controller {
  display: flex;
  gap: var(--space-1);
  align-items: flex-start;
}
.github-webhook code {
  word-break: break-all;
}
progress {
  width: 8rem;
  margin-left: var(--space-1);
}
</style>
