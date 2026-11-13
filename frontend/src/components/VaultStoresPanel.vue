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
          </span>
          <span>
            <button :disabled="loading" :data-testid="`vault-store-test-${store.id}`" @click="testStore(store)">{{ t('vaultStores.test') }}</button>
            <button class="warning-btn" :disabled="loading || store.workspaces > 0" @click="deleteStore(store)">{{ t('vaultStores.delete') }}</button>
          </span>
        </li>
        <li v-if="stores.length === 0" class="admin-note">{{ t('vaultStores.empty') }}</li>
      </ul>

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
          <input v-model="form.credentials.access_key_id" :placeholder="t('vaultStores.fields.accessKey')" autocomplete="off" required />
          <input v-model="form.credentials.secret_access_key" type="password" :placeholder="t('vaultStores.fields.secretKey')" autocomplete="new-password" required />
        </template>
        <template v-else-if="form.driver === 'github'">
          <input v-model="form.settings.owner" :placeholder="t('vaultStores.fields.owner')" required />
          <input v-model="form.settings.repo" :placeholder="t('vaultStores.fields.repo')" required />
          <input v-model="form.settings.branch" placeholder="main" />
          <input v-model="form.credentials.token" type="password" :placeholder="t('vaultStores.fields.token')" autocomplete="new-password" />
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

const emptyForm = () => ({
  name: '',
  driver: props.drivers.find((driver) => driver !== 'local') ?? 'local',
  settings: {} as Record<string, string>,
  credentials: {} as Record<string, string>,
})
const form = ref(emptyForm())

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
}

async function createStore() {
  if (!tenantId.value) return
  loading.value = true
  error.value = ''
  try {
    const credentials = Object.fromEntries(Object.entries(form.value.credentials).filter(([, value]) => value))
    await api.post(`/admin/tenants/${tenantId.value}/vault-stores`, {
      name: form.value.name,
      driver: form.value.driver,
      settings: Object.fromEntries(Object.entries(form.value.settings).filter(([, value]) => value)),
      ...(Object.keys(credentials).length ? { credentials } : {}),
    })
    form.value = emptyForm()
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
    await api.post(`/admin/workspaces/${moveWorkspaceId.value}/vault-moves`, {
      to_store_id: moveTarget.value === 'local' ? null : Number(moveTarget.value),
    })
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
progress {
  width: 8rem;
  margin-left: var(--space-1);
}
</style>
