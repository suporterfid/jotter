<template>
  <div class="tab-content" data-testid="admin-storage-tab">
    <h3>{{ t('vaultStores.title') }}</h3>
    <p class="admin-note">{{ t('vaultStores.help') }}</p>
    <p class="admin-note vault-warning" data-testid="vault-store-access-warning">{{ t('vaultStores.directAccessWarning') }}</p>

    <div v-if="error" class="admin-error-banner">{{ error }}</div>

    <select v-model.number="tenantId" data-testid="vault-store-tenant" @change="refresh">
      <option :value="null" disabled>{{ t('adminPanel.selectTenant') }}</option>
      <option v-for="tenant in tenants" :key="tenant.id" :value="tenant.id">{{ tenant.name }}</option>
    </select>

    <template v-if="tenantId">
      <ul class="admin-list" data-testid="vault-store-list">
        <li v-for="store in stores" :key="store.id" class="admin-list-item" :data-testid="`vault-store-${store.id}`">
          <span>
            <strong>{{ store.name }}</strong> — {{ driverLabel(store.driver) }}
            · {{ t(`vaultStores.status.${store.status}`) }}
            · {{ t('vaultStores.workspaceCount', { count: store.workspaces }) }}
            <br />
            <small v-if="store.last_error" class="admin-note">{{ store.last_error }}</small>
            <small v-if="store.driver === 's3' && store.settings?.bucket_state" class="admin-note" :data-testid="`vault-store-versioning-${store.id}`">
              {{ t(`vaultStores.s3.versioning.${store.settings.bucket_state.versioning ?? 'unknown'}`) }}
            </small>
          </span>
          <span>
            <button :disabled="loading" :data-testid="`vault-store-test-${store.id}`" @click="testStore(store)">{{ t('vaultStores.test') }}</button>
            <button v-if="store.driver === 'github'" :disabled="loading" :data-testid="`vault-store-webhook-${store.id}`" @click="showWebhook(store)">{{ t('vaultStores.github.webhook') }}</button>
            <button class="warning-btn" :disabled="loading || store.workspaces > 0" @click="deleteStore(store)">{{ t('vaultStores.delete') }}</button>
          </span>
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

      <h4>{{ t('vaultStores.add') }}</h4>
      <form class="admin-form" data-testid="vault-store-form" @submit.prevent="createStore">
        <input v-model="form.name" :placeholder="t('vaultStores.name')" required />
        <select v-model="form.driver" data-testid="vault-store-driver" required>
          <option v-for="driver in drivers" :key="driver" :value="driver">{{ driverLabel(driver) }}</option>
        </select>
        <template v-if="form.driver === 'local'">
          <input v-model="form.settings.root" :placeholder="t('vaultStores.fields.root')" required />
        </template>
        <template v-else-if="form.driver === 's3'">
          <input v-model="form.settings.endpoint" placeholder="https://…" required />
          <input v-model="form.settings.bucket" :placeholder="t('vaultStores.fields.bucket')" required />
          <input v-model="form.settings.region" :placeholder="t('vaultStores.fields.region')" />
          <input v-model="form.settings.prefix" :placeholder="t('vaultStores.fields.prefix')" />
          <label class="admin-check"><input v-model="pathStyle" type="checkbox" data-testid="vault-store-path-style" /> {{ t('vaultStores.fields.pathStyle') }}</label>
          <p class="admin-note">{{ t('vaultStores.s3.versioningHelp') }}</p>
          <input v-model="form.credentials.access_key_id" :placeholder="t('vaultStores.fields.accessKey')" autocomplete="off" required />
          <input v-model="form.credentials.secret_access_key" type="password" :placeholder="t('vaultStores.fields.secretKey')" autocomplete="new-password" required />
        </template>
        <template v-else-if="form.driver === 'github'">
          <p class="admin-note vault-warning" data-testid="github-access-warning">{{ t('vaultStores.github.accessWarning') }}</p>
          <button v-if="github?.app_configured" type="button" :disabled="loading" data-testid="github-install" @click="installApp">
            {{ t('vaultStores.github.install') }}
          </button>
          <select v-model="form.settings.installation_id" data-testid="github-installation" @change="loadRepositories">
            <option value="">{{ t('vaultStores.github.patMode') }}</option>
            <option v-for="installation in github?.installations ?? []" :key="installation.installation_id" :value="String(installation.installation_id)">
              {{ t('vaultStores.github.installation', { account: installation.account_login ?? installation.installation_id }) }}
            </option>
          </select>
          <select v-if="form.settings.installation_id" v-model="pickedRepository" data-testid="github-repository" required @change="pickRepository">
            <option value="" disabled>{{ t('vaultStores.github.chooseRepository') }}</option>
            <option v-for="repository in repositories" :key="repository.full_name" :value="repository.full_name" :disabled="!repository.private">
              {{ repository.full_name }}{{ repository.private ? '' : ` (${t('vaultStores.github.public')})` }}
            </option>
          </select>
          <template v-else>
            <input v-model="form.settings.owner" :placeholder="t('vaultStores.fields.owner')" required />
            <input v-model="form.settings.repo" :placeholder="t('vaultStores.fields.repo')" required />
          </template>
          <input v-model="form.settings.branch" placeholder="main" />
          <input v-if="!form.settings.installation_id" v-model="form.credentials.token" type="password" :placeholder="t('vaultStores.fields.token')" autocomplete="new-password" required />
          <label class="github-controller">
            <input v-model="controllerConfirmed" type="checkbox" required data-testid="github-controller" />
            {{ t('vaultStores.github.controller') }}
          </label>
        </template>
        <button type="submit" :disabled="loading" data-testid="vault-store-submit">{{ t('vaultStores.save') }}</button>
      </form>
      <p class="admin-note">{{ t('vaultStores.credentialsWriteOnly') }}</p>

      <h4>{{ t('vaultStores.moveTitle') }}</h4>
      <p class="admin-note">{{ t('vaultStores.moveHelp') }}</p>
      <form class="admin-form" data-testid="vault-move-form" @submit.prevent="startMove">
        <select v-model.number="moveWorkspaceId" required @change="fetchMoves">
          <option :value="null" disabled>{{ t('vaultStores.chooseWorkspace') }}</option>
          <option v-for="ws in tenantWorkspaces" :key="ws.id" :value="ws.id">{{ ws.name }}</option>
        </select>
        <select v-model="moveTarget" required>
          <option value="local">{{ t('vaultStores.localFolder') }}</option>
          <option v-for="store in stores" :key="store.id" :value="String(store.id)">{{ store.name }}</option>
        </select>
        <button type="submit" :disabled="loading || !moveWorkspaceId" data-testid="vault-move-submit">{{ t('vaultStores.startMove') }}</button>
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

interface VaultStoreRow {
  id: number
  name: string
  driver: string
  status: 'untested' | 'ok' | 'failed'
  last_error: string | null
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
  error.value = ''
  try {
    await api.post(`/admin/vault-stores/${store.id}/test`)
  } catch (e: unknown) {
    error.value = message(e, t('vaultStores.testFailed'))
  } finally {
    loading.value = false
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
  moves.value = []
  moveWorkspaceId.value = null
  refresh()
}, { immediate: true })

onBeforeUnmount(() => {
  if (poller) clearInterval(poller)
})
</script>

<style scoped>
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
