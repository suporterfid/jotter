<template>
  <div v-if="isOpen" class="admin-modal-overlay" data-testid="admin-panel" @click.self="close">
    <div class="admin-modal-container">
      <div class="admin-header">
        <h2>{{ t('adminPanel.title') }}</h2>
        <button class="close-btn" @click="close">&times;</button>
      </div>

      <div class="admin-tabs">
        <button
          :class="['tab-btn', { active: activeTab === 'workspaces' }]"
          @click="activeTab = 'workspaces'"
        >
          {{ t('adminPanel.workspaces') }}
        </button>
        <button
          :class="['tab-btn', { active: activeTab === 'members' }]"
          @click="activeTab = 'members'"
        >
          {{ t('adminPanel.members') }}
        </button>
        <button
          :class="['tab-btn', { active: activeTab === 'users' }]"
          @click="activeTab = 'users'"
        >
          {{ t('adminPanel.users') }}
          <span v-if="pendingSignups.length + deletionRequests.length" class="tab-badge" data-testid="admin-pending-badge">{{ pendingSignups.length + deletionRequests.length }}</span>
        </button>
        <button
          :class="['tab-btn', { active: activeTab === 'mcp' }]"
          data-testid="admin-tab-mcp"
          @click="activeTab = 'mcp'"
        >
          {{ t('adminPanel.mcpTokens') }}
        </button>
        <button
          :class="['tab-btn', { active: activeTab === 'apps' }]"
          data-testid="admin-tab-apps"
          @click="activeTab = 'apps'"
        >
          {{ t('adminPanel.connectedApps') }}
        </button>
      </div>

      <div v-if="error" class="admin-error-banner">
        {{ error }}
      </div>

      <!-- Workspaces Tab -->
      <div v-if="activeTab === 'workspaces'" class="tab-content">
        <h3>{{ t('adminPanel.createWorkspace') }}</h3>
        <form @submit.prevent="createWorkspace" class="admin-form">
          <select v-model.number="newWs.tenant_id" data-testid="admin-new-workspace-tenant" required>
            <option :value="null" disabled>{{ t('adminPanel.selectTenant') }}</option>
            <option v-for="tenant in tenants" :key="tenant.id" :value="tenant.id">{{ tenant.name }}</option>
          </select>
          <input v-model="newWs.name" :placeholder="t('adminPanel.workspaceName')" data-testid="admin-new-workspace-name" required />
          <input v-model="newWs.slug" :placeholder="t('adminPanel.slugPlaceholder')" data-testid="admin-new-workspace-slug" required />
          <input v-model="newWs.vault_path" :placeholder="t('adminPanel.vaultPath')" data-testid="admin-new-workspace-vault-path" required />
          <button type="submit" data-testid="admin-new-workspace-submit" :disabled="loading">{{ t('adminPanel.createWorkspaceSubmit') }}</button>
        </form>

        <h3>{{ t('adminPanel.workspacesList') }}</h3>
        <ul class="admin-list">
          <li v-for="ws in workspaces" :key="ws.id" class="admin-list-item" :class="{ 'admin-list-item-column': editingWsId === ws.id }">
            <template v-if="editingWsId === ws.id">
              <div class="admin-form">
                <input v-model="wsEditDraft.name" data-testid="admin-edit-workspace-name" :placeholder="t('adminPanel.workspaceName')" required />
                <input v-model="wsEditDraft.slug" data-testid="admin-edit-workspace-slug" :placeholder="t('adminPanel.slugPlaceholder')" required />
                <label class="admin-check">
                  {{ t('adminPanel.storageQuota') }}
                  <input v-model="wsEditDraft.storage_quota_mb" type="number" min="0" step="1" data-testid="admin-edit-workspace-quota" :placeholder="t('adminPanel.storageQuotaHelp')" />
                </label>
                <p class="admin-form-note">{{ t('adminPanel.storageQuotaHelp') }}</p>
                <p class="admin-form-note">{{ t('adminPanel.vaultPathNote') }}</p>
                <div class="btn-group">
                  <button class="secondary-btn" data-testid="admin-edit-workspace-cancel" type="button" @click="cancelEditWorkspace">{{ t('adminPanel.cancel') }}</button>
                  <button class="success-btn" data-testid="admin-edit-workspace-save" type="button" :disabled="loading" @click="saveEditWorkspace(ws.id)">{{ t('adminPanel.save') }}</button>
                </div>
              </div>
            </template>
            <template v-else>
              <span>
                <strong>{{ ws.name }}</strong> ({{ ws.slug }})
                <small v-if="ws.storage" class="admin-note" :data-testid="`admin-ws-storage-${ws.id}`">
                  · {{ t('adminPanel.storageUsed') }}: {{ formatBytes(ws.storage.used_bytes) }} /
                  {{ ws.storage.limit_bytes === null ? t('adminPanel.storageUnlimited') : formatBytes(ws.storage.limit_bytes) }}
                  <template v-if="ws.storage.percent !== null"> ({{ Math.round(ws.storage.percent) }}%)</template>
                </small>
              </span>
              <div class="btn-group">
                <button class="secondary-btn" data-testid="admin-edit-workspace-btn" @click="startEditWorkspace(ws)">{{ t('adminPanel.edit') }}</button>
                <button class="danger-btn" @click="archiveWorkspace(ws.id)" :disabled="loading">{{ t('adminPanel.archive') }}</button>
              </div>
            </template>
          </li>
        </ul>
      </div>

      <!-- Members Tab -->
      <div v-if="activeTab === 'members'" class="tab-content">
        <h3>{{ t('adminPanel.workspaceMembers') }}</h3>
        <div class="select-group">
          <label>{{ t('adminPanel.workspaceLabel') }}</label>
          <select v-model="selectedWsId" @change="fetchMembers">
            <option v-for="ws in workspaces" :key="ws.id" :value="ws.id">{{ ws.name }}</option>
          </select>
        </div>

        <form @submit.prevent="grantMember" class="admin-form" v-if="selectedWsId">
          <input v-model="newMember.subject_id" :placeholder="t('adminPanel.subjectIdPlaceholder')" required />
          <select v-model="newMember.role">
            <option value="owner">{{ t('adminPanel.roleOwner') }}</option>
            <option value="admin">{{ t('adminPanel.roleAdmin') }}</option>
            <option value="editor">{{ t('adminPanel.roleEditor') }}</option>
            <option value="viewer">{{ t('adminPanel.roleViewer') }}</option>
          </select>
          <button type="submit" :disabled="loading">{{ t('adminPanel.grantAccess') }}</button>
        </form>

        <ul class="admin-list" v-if="selectedWsId">
          <li v-for="m in members" :key="m.id" class="admin-list-item">
            <span><strong>{{ m.subject_id }}</strong> - {{ m.role }}</span>
            <button class="danger-btn" @click="revokeMember(m.id)" :disabled="loading">{{ t('adminPanel.revoke') }}</button>
          </li>
        </ul>
      </div>

      <!-- Users Tab -->
      <div v-if="activeTab === 'users'" class="tab-content">
        <template v-if="pendingSignups.length">
          <h3>{{ t('adminPanel.pendingSignups') }}</h3>
          <p class="admin-note">{{ t('adminPanel.pendingSignupsHelp') }}</p>
          <ul class="admin-list" data-testid="admin-pending-signups">
            <li v-for="u in pendingSignups" :key="u.id" class="admin-list-item" :data-testid="`admin-pending-${u.id}`">
              <span>
                <strong>{{ u.name }}</strong> ({{ u.email }})
                <small v-if="u.signup_requested_at" class="admin-note"> · {{ formatDate(u.signup_requested_at) }}</small>
              </span>
              <div class="btn-group">
                <button class="success-btn" :disabled="loading" :data-testid="`admin-approve-${u.id}`" @click="decideSignup(u, 'approve')">{{ t('adminPanel.approve') }}</button>
                <button class="danger-btn" :disabled="loading" :data-testid="`admin-reject-${u.id}`" @click="decideSignup(u, 'reject')">{{ t('adminPanel.reject') }}</button>
              </div>
            </li>
          </ul>
        </template>

        <template v-if="deletionRequests.length">
          <h3>{{ t('adminPanel.deletionRequests') }}</h3>
          <p class="admin-note">{{ t('adminPanel.deletionRequestsHelp') }}</p>
          <ul class="admin-list" data-testid="admin-deletion-requests">
            <li v-for="u in deletionRequests" :key="u.id" class="admin-list-item" :data-testid="`admin-deletion-request-${u.id}`">
              <span>
                <strong>{{ u.name }}</strong> ({{ u.email }})
                <small class="admin-note"> · {{ formatDate(u.deletion_requested_at) }}</small>
              </span>
              <div class="btn-group">
                <button class="danger-btn" :disabled="loading" :data-testid="`admin-delete-requested-${u.id}`" @click="deleteUser(u)">{{ t('adminPanel.deleteUser') }}</button>
              </div>
            </li>
          </ul>
        </template>

        <h3>{{ t('adminPanel.createLocalUser') }}</h3>
        <form @submit.prevent="createUser" class="admin-form">
          <input v-model="newUser.name" :placeholder="t('adminPanel.fullName')" required />
          <input v-model="newUser.email" type="email" :placeholder="t('adminPanel.email')" required />
          <input v-model="newUser.password" type="password" :placeholder="t('adminPanel.password')" required />
          <label class="checkbox-label">
            <input type="checkbox" v-model="newUser.is_admin" /> {{ t('adminPanel.isAdmin') }}
          </label>
          <button type="submit" :disabled="loading">{{ t('adminPanel.createUser') }}</button>
        </form>

        <h3>{{ t('adminPanel.usersList') }}</h3>
        <ul class="admin-list">
          <li v-for="u in activeOrInactiveUsers" :key="u.id" class="admin-list-item">
            <span><strong>{{ u.name }}</strong> ({{ u.email }}) <em v-if="!u.is_active">{{ t('adminPanel.deactivatedTag') }}</em></span>
            <div class="btn-group">
              <button class="secondary-btn" data-testid="admin-reset-password-btn" :disabled="loading" @click="resetPassword(u)">{{ t('adminPanel.resetPassword') }}</button>
              <button v-if="u.is_active" class="warning-btn" @click="toggleDeactivate(u, true)">{{ t('adminPanel.deactivate') }}</button>
              <button v-else class="success-btn" @click="toggleDeactivate(u, false)">{{ t('adminPanel.reactivate') }}</button>
              <button class="danger-btn" :disabled="loading" :data-testid="`admin-delete-user-${u.id}`" @click="deleteUser(u)">{{ t('adminPanel.deleteUser') }}</button>
            </div>
          </li>
        </ul>
      </div>

      <!-- MCP machine tokens Tab -->
      <div v-if="activeTab === 'mcp'" class="tab-content" data-testid="admin-mcp-tab">
        <h3>{{ t('adminPanel.mcpCreateToken') }}</h3>
        <p class="admin-note">{{ t('adminPanel.mcpTokenHelp') }}</p>
        <form @submit.prevent="createMcpToken" class="admin-form">
          <select v-model.number="newToken.tenant_id" data-testid="admin-mcp-tenant" required>
            <option :value="null" disabled>{{ t('adminPanel.selectTenant') }}</option>
            <option v-for="tn in tenants" :key="tn.id" :value="tn.id">{{ tn.name }} ({{ tn.slug }})</option>
          </select>
          <select v-model.number="newToken.user_id" data-testid="admin-mcp-user" required>
            <option :value="null" disabled>{{ t('adminPanel.mcpSelectUser') }}</option>
            <option v-for="u in users" :key="u.id" :value="u.id">{{ u.name }} ({{ u.email }})</option>
          </select>
          <input v-model="newToken.name" :placeholder="t('adminPanel.mcpTokenName')" data-testid="admin-mcp-name" required />

          <fieldset class="admin-fieldset" data-testid="admin-mcp-workspaces">
            <legend>{{ t('adminPanel.mcpWorkspaces') }}</legend>
            <p class="admin-note">{{ t('adminPanel.mcpWorkspacesHelp') }}</p>
            <label v-for="ws in tokenTenantWorkspaces" :key="ws.id" class="admin-check">
              <input type="checkbox" :value="ws.id" v-model="newToken.workspace_ids" :data-testid="`admin-mcp-ws-${ws.id}`" />
              {{ ws.name }} ({{ ws.slug }})
            </label>
          </fieldset>

          <fieldset class="admin-fieldset" data-testid="admin-mcp-scopes">
            <legend>{{ t('adminPanel.mcpScopes') }}</legend>
            <label v-for="scope in TOKEN_SCOPES" :key="scope" class="admin-check">
              <input type="checkbox" :value="scope" v-model="newToken.scopes" :data-testid="`admin-mcp-scope-${scope}`" />
              <code>{{ scope }}</code> — {{ t(`adminPanel.mcpScopeHelp.${scope.replace(':', '_')}`) }}
            </label>
          </fieldset>

          <fieldset v-if="newToken.scopes.includes('mcp:write')" class="admin-fieldset" data-testid="admin-mcp-write-paths">
            <legend>{{ t('adminPanel.mcpWritePaths') }}</legend>
            <p class="admin-note">{{ t('adminPanel.mcpWritePathsHelp') }}</p>
            <div v-for="(path, index) in newToken.write_paths" :key="index" class="admin-write-path">
              <input v-model="path.glob" :data-testid="`admin-mcp-glob-${index}`" :aria-label="t('adminPanel.mcpWritePathGlob')" />
              <label v-for="op in WRITE_OPS" :key="op" class="admin-check">
                <input type="checkbox" :value="op" v-model="path.ops" /> {{ op }}
              </label>
              <button type="button" class="warning-btn" @click="newToken.write_paths.splice(index, 1)">{{ t('adminPanel.mcpRemove') }}</button>
            </div>
            <button type="button" @click="newToken.write_paths.push({ glob: '', ops: ['create'] })">{{ t('adminPanel.mcpAddWritePath') }}</button>
            <input v-model="newToken.client_slug" :placeholder="t('adminPanel.mcpClientSlug')" data-testid="admin-mcp-client" />
          </fieldset>

          <label class="admin-check">
            {{ t('adminPanel.mcpExpires') }}
            <select v-model="newToken.expires" data-testid="admin-mcp-expires">
              <option v-for="option in EXPIRY_OPTIONS" :key="option" :value="option">{{ t(`adminPanel.mcpExpiry.${option}`) }}</option>
            </select>
          </label>

          <button type="submit" data-testid="admin-mcp-submit" :disabled="loading">{{ t('adminPanel.mcpCreateTokenSubmit') }}</button>
        </form>

        <div v-if="issuedToken" class="admin-issued-token" data-testid="admin-mcp-issued">
          <p class="admin-note admin-note-strong">{{ t('adminPanel.mcpTokenOnce') }}</p>
          <code class="admin-token-value" data-testid="admin-mcp-token-value">{{ issuedToken.token }}</code>
          <McpClientSnippets :token="issuedToken.token" :host="issuedToken.host" :server-name="issuedToken.serverName" />
        </div>

        <h3>{{ t('adminPanel.mcpTokensList') }}</h3>
        <ul class="admin-list">
          <li v-for="tk in machineTokens" :key="tk.id" class="admin-list-item" :data-testid="`admin-mcp-token-${tk.id}`">
            <span>
              <strong>{{ tk.name }}</strong> — {{ tk.user_email }} · {{ tk.tenant_slug }}
              <em v-if="tk.revoked_at">{{ t('adminPanel.mcpRevokedTag') }}</em>
              <br />
              <small class="admin-note" :data-testid="`admin-mcp-token-scope-${tk.id}`">
                {{ (tk.scopes || []).join(' ') }}
                · {{ tk.workspace_ids === null ? t('adminPanel.mcpReachMemberships') : workspaceSlugs(tk.workspace_ids) }}
                <template v-if="tk.client_slug"> · {{ tk.client_slug }}</template>
                · {{ t('adminPanel.mcpLastUsed') }}: {{ tk.last_used_at ? formatDate(tk.last_used_at) : t('adminPanel.mcpNever') }}
                · {{ t('adminPanel.mcpExpires') }}: {{ tk.expires_at ? formatDate(tk.expires_at) : t('adminPanel.mcpNever') }}
              </small>
            </span>
            <button v-if="!tk.revoked_at" class="warning-btn" :disabled="loading" @click="revokeMcpToken(tk)">{{ t('adminPanel.mcpRevoke') }}</button>
          </li>
        </ul>
      </div>

      <div v-if="activeTab === 'apps'" class="tab-content" data-testid="admin-apps-tab">
        <h3>{{ t('adminPanel.connectedApps') }}</h3>
        <p class="admin-note">{{ t('adminPanel.connectedAppsHelp') }}</p>
        <p v-if="grantsLoading && oauthGrants.length === 0" class="admin-note">{{ t('connectedApps.loading') }}</p>
        <p v-else-if="oauthGrants.length === 0" class="admin-note" data-testid="admin-apps-empty">{{ t('connectedApps.empty') }}</p>
        <ul class="admin-list">
          <li v-for="grant in oauthGrants" :key="grant.id" class="admin-list-item" :data-testid="`admin-app-${grant.id}`">
            <span>
              <strong>{{ grant.client_name || t('connectedApps.unnamed') }}</strong>
              — {{ grant.user_email || t('adminPanel.connectedAppsUnknownUser') }}
              <br />
              <small class="admin-note" :data-testid="`admin-app-detail-${grant.id}`">
                {{ grant.redirect_hosts.join(', ') }}
                · {{ grant.workspaces.join(', ') }} · {{ grant.scopes.join(' ') }}
                <template v-if="grant.client_slug"> · {{ grant.client_slug }}</template>
                · {{ t('connectedApps.lastUsed') }}: {{ grant.last_used_at ? formatDate(grant.last_used_at) : t('connectedApps.never') }}
                · {{ t('connectedApps.expires') }}: {{ grant.expires_at ? formatDate(grant.expires_at) : t('connectedApps.never') }}
              </small>
            </span>
            <button class="warning-btn" :disabled="loading" :data-testid="`admin-app-revoke-${grant.id}`" @click="revokeOAuthGrant(grant)">
              {{ t('connectedApps.revoke') }}
            </button>
          </li>
        </ul>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { api, getTenants, adminResetPassword } from '../services/api'
import type { Tenant } from '../services/types'
import McpClientSnippets from './McpClientSnippets.vue'
import { formatBytes } from '../services/formatBytes'

const { t } = useI18n()

const props = defineProps<{ isOpen: boolean }>()
const emit = defineEmits(['close'])

const activeTab = ref<'workspaces' | 'members' | 'users' | 'mcp' | 'apps'>('workspaces')
const error = ref('')
const loading = ref(false)

const tenants = ref<Tenant[]>([])
const workspaces = ref<any[]>([])
const selectedWsId = ref<number | null>(null)
const members = ref<any[]>([])
const users = ref<any[]>([])

const newWs = ref<{ tenant_id: number | null; name: string; slug: string; vault_path: string }>({
  tenant_id: null, name: '', slug: '', vault_path: '',
})
const newMember = ref({ subject_id: '', role: 'editor' })
const newUser = ref({ name: '', email: '', password: '', is_admin: false })

interface MachineTokenRow {
  id: number
  name: string
  tenant_slug: string | null
  user_email: string | null
  scopes?: string[]
  workspace_ids?: number[] | null
  client_slug?: string | null
  created_at: string | null
  expires_at?: string | null
  last_used_at?: string | null
  revoked_at: string | null
}
interface WritePathDraft { glob: string; ops: string[] }
interface TokenDraft {
  tenant_id: number | null
  user_id: number | null
  name: string
  workspace_ids: number[]
  scopes: string[]
  write_paths: WritePathDraft[]
  client_slug: string
  expires: string
}

const TOKEN_SCOPES = ['mcp:read', 'mcp:write', 'api:read', 'api:write'] as const
const WRITE_OPS = ['create', 'append', 'update'] as const
const EXPIRY_OPTIONS = ['never', '30d', '90d', '180d', '1y'] as const

function emptyTokenDraft(tenantId: number | null = null, userId: number | null = null): TokenDraft {
  return { tenant_id: tenantId, user_id: userId, name: '', workspace_ids: [], scopes: ['mcp:read'], write_paths: [], client_slug: '', expires: 'never' }
}

const machineTokens = ref<MachineTokenRow[]>([])
const newToken = ref<TokenDraft>(emptyTokenDraft())

const tokenTenantWorkspaces = computed(() =>
  workspaces.value.filter((ws: { tenant_id: number }) => ws.tenant_id === newToken.value.tenant_id),
)

// Turning on mcp:write pre-fills the brain defaults: a per-client inbox and an
// append-only daily journal (docs/superpowers/plans/2026-11-03-jotter-brain-multiagent.md).
watch(
  () => newToken.value.scopes.includes('mcp:write'),
  (canWrite) => {
    if (canWrite && newToken.value.write_paths.length === 0) {
      newToken.value.write_paths = [
        { glob: 'inbox/{client}/**', ops: ['create', 'append', 'update'] },
        { glob: 'diario/**', ops: ['append'] },
      ]
    }
    if (!canWrite) {
      newToken.value.write_paths = []
    }
  },
)

function workspaceSlugs(ids: number[] | null | undefined): string {
  return (ids || []).map((id) => workspaces.value.find((ws: { id: number }) => ws.id === id)?.slug ?? `#${id}`).join(', ')
}

function formatDate(iso: string): string {
  return new Date(iso).toLocaleString()
}
const issuedToken = ref<{ token: string; host: string; serverName: string } | null>(null)

async function fetchMachineTokens() {
  try {
    const response = await api.get<{ data: MachineTokenRow[] }>('/admin/machine-tokens')
    machineTokens.value = response.data.data
  } catch (e) {
    error.value = extractErrorMessage(e, t('adminPanel.requestFailed'))
  }
}

async function createMcpToken() {
  if (!newToken.value.tenant_id || !newToken.value.user_id) return
  loading.value = true
  error.value = ''
  try {
    const draft = newToken.value
    const payload = {
      tenant_id: draft.tenant_id,
      user_id: draft.user_id,
      name: draft.name,
      scopes: draft.scopes,
      workspace_ids: draft.workspace_ids.length > 0 ? draft.workspace_ids : null,
      write_paths: draft.scopes.includes('mcp:write') ? draft.write_paths : [],
      client_slug: draft.client_slug.trim() || null,
      expires: draft.expires,
    }
    const response = await api.post<{ data: { token: string; mcp_url: string; tenant_slug: string | null } }>('/admin/machine-tokens', payload)
    const data = response.data.data
    const host = data.mcp_url ? data.mcp_url.replace(/\/api\/mcp$/, '') : ''
    issuedToken.value = { token: data.token, host, serverName: data.tenant_slug ? `jotter-${data.tenant_slug}` : 'jotter' }
    newToken.value = emptyTokenDraft(draft.tenant_id, draft.user_id)
    await fetchMachineTokens()
  } catch (e) {
    error.value = extractErrorMessage(e, t('adminPanel.requestFailed'))
  } finally {
    loading.value = false
  }
}

interface OAuthGrantRow {
  id: number
  client_name: string | null
  redirect_hosts: string[]
  user_email: string | null
  workspaces: string[]
  scopes: string[]
  client_slug: string | null
  expires_at: string | null
  last_used_at: string | null
}

// Every person's OAuth connections (Claude web, ChatGPT…); each person also
// sees their own under the sidebar's Connected apps.
const oauthGrants = ref<OAuthGrantRow[]>([])
const grantsLoading = ref(false)

async function fetchOAuthGrants() {
  grantsLoading.value = true
  try {
    const response = await api.get<{ data: OAuthGrantRow[] }>('/admin/oauth-grants')
    oauthGrants.value = response.data.data
  } catch (e) {
    error.value = extractErrorMessage(e, t('connectedApps.failed'))
  } finally {
    grantsLoading.value = false
  }
}

async function revokeOAuthGrant(grant: OAuthGrantRow) {
  const name = grant.client_name || t('connectedApps.unnamed')
  if (!confirm(t('adminPanel.connectedAppsRevokeConfirm', { name, email: grant.user_email ?? '?' }))) return
  loading.value = true
  try {
    await api.delete(`/admin/oauth-grants/${grant.id}`)
    await fetchOAuthGrants()
  } catch (e) {
    error.value = extractErrorMessage(e, t('connectedApps.failed'))
  } finally {
    loading.value = false
  }
}

watch(activeTab, (tab) => {
  if (tab === 'apps' && props.isOpen) fetchOAuthGrants()
})

async function revokeMcpToken(token: MachineTokenRow) {
  if (!confirm(t('adminPanel.mcpRevokeConfirm', { name: token.name }))) return
  loading.value = true
  try {
    await api.delete(`/admin/machine-tokens/${token.id}`)
    await fetchMachineTokens()
  } catch (e) {
    error.value = extractErrorMessage(e, t('adminPanel.requestFailed'))
  } finally {
    loading.value = false
  }
}

const editingWsId = ref<number | null>(null)
const wsEditDraft = ref<{ name: string; slug: string; storage_quota_mb: number | string | null }>({ name: '', slug: '', storage_quota_mb: null })

function close() {
  emit('close')
}

function extractErrorMessage(e: unknown, fallback: string): string {
  const axiosError = e as { response?: { data?: { message?: string } } }
  return axiosError.response?.data?.message || fallback
}

async function fetchTenants() {
  try {
    tenants.value = await getTenants()
    if (tenants.value.length === 1) {
      newWs.value.tenant_id = tenants.value[0].id
    }
  } catch (e: unknown) {
    error.value = extractErrorMessage(e, t('adminPanel.failedLoadTenants'))
  }
}

async function fetchWorkspaces() {
  try {
    const response = await api.get<{ data: any[] }>('/workspaces')
    workspaces.value = response.data.data || []
    if (workspaces.value.length && !selectedWsId.value) {
      selectedWsId.value = workspaces.value[0].id
      fetchMembers()
    }
  } catch (e: unknown) {
    error.value = extractErrorMessage(e, t('adminPanel.failedLoadWorkspaces'))
  }
}

async function fetchMembers() {
  if (!selectedWsId.value) return
  try {
    const response = await api.get<{ data: any[] }>(`/admin/workspaces/${selectedWsId.value}/members`)
    members.value = response.data.data || []
  } catch (e: unknown) {
    error.value = extractErrorMessage(e, t('adminPanel.failedLoadMembers'))
  }
}

// Self sign-up requests waiting for a decision (#390).
const pendingSignups = computed(() => users.value.filter((u: { signup_status?: string | null }) => u.signup_status === 'pending'))
// LGPD: people who asked for their account to be deleted ("Your profile → Your data").
const deletionRequests = computed(() => users.value.filter((u: { deletion_requested_at?: string | null }) => !!u.deletion_requested_at))
const activeOrInactiveUsers = computed(() => users.value.filter((u: { signup_status?: string | null }) => u.signup_status !== 'pending'))

async function decideSignup(u: { id: number; name: string; email: string }, decision: 'approve' | 'reject') {
  if (decision === 'reject' && !confirm(t('adminPanel.rejectConfirm', { name: u.name, email: u.email }))) return
  loading.value = true
  error.value = ''
  try {
    await api.post(`/admin/users/${u.id}/${decision}`)
    await fetchUsers()
  } catch (e: unknown) {
    error.value = extractErrorMessage(e, t('adminPanel.failedUpdateUser'))
  } finally {
    loading.value = false
  }
}

// Account deletion (#390, LGPD): memberships, tokens and grants go; comments
// and history stay in the workspace without the person's name.
async function deleteUser(u: { id: number; name: string; email: string }) {
  if (!confirm(t('adminPanel.deleteUserConfirm', { name: u.name, email: u.email }))) return
  loading.value = true
  error.value = ''
  try {
    await api.delete(`/admin/users/${u.id}`)
    await fetchUsers()
  } catch (e: unknown) {
    error.value = extractErrorMessage(e, t('adminPanel.failedUpdateUser'))
  } finally {
    loading.value = false
  }
}

async function fetchUsers() {
  try {
    const response = await api.get<{ data: any[] }>('/admin/users')
    users.value = response.data.data || []
  } catch (e: unknown) {
    error.value = extractErrorMessage(e, t('adminPanel.failedLoadUsers'))
  }
}

async function createWorkspace() {
  error.value = ''
  if (!newWs.value.tenant_id) {
    error.value = t('adminPanel.selectTenantError')
    return
  }
  loading.value = true
  try {
    await api.post('/admin/workspaces', newWs.value)
    newWs.value = { tenant_id: newWs.value.tenant_id, name: '', slug: '', vault_path: '' }
    await fetchWorkspaces()
  } catch (e: unknown) {
    error.value = extractErrorMessage(e, t('adminPanel.workspaceCreationFailed'))
  } finally {
    loading.value = false
  }
}

function startEditWorkspace(ws: any) {
  editingWsId.value = ws.id
  wsEditDraft.value = { name: ws.name, slug: ws.slug, storage_quota_mb: ws.storage?.quota_mb ?? null }
}

function cancelEditWorkspace() {
  editingWsId.value = null
}

async function saveEditWorkspace(id: number) {
  error.value = ''
  loading.value = true
  try {
    const quota = wsEditDraft.value.storage_quota_mb
    await api.put(`/admin/workspaces/${id}`, {
      name: wsEditDraft.value.name,
      slug: wsEditDraft.value.slug,
      // Empty field = instance default (null); 0 = unlimited.
      storage_quota_mb: quota === '' || quota === null ? null : Number(quota),
    })
    editingWsId.value = null
    await fetchWorkspaces()
  } catch (e: unknown) {
    error.value = extractErrorMessage(e, t('adminPanel.failedUpdateWorkspace'))
  } finally {
    loading.value = false
  }
}

async function archiveWorkspace(id: number) {
  if (!confirm(t('adminPanel.confirmArchive'))) return
  loading.value = true
  try {
    await api.post(`/admin/workspaces/${id}/archive`)
    await fetchWorkspaces()
  } catch (e: unknown) {
    error.value = extractErrorMessage(e, t('adminPanel.failedArchiveWorkspace'))
  } finally {
    loading.value = false
  }
}

async function grantMember() {
  if (!selectedWsId.value) return
  loading.value = true
  try {
    await api.post(`/admin/workspaces/${selectedWsId.value}/members`, newMember.value)
    newMember.value = { subject_id: '', role: 'editor' }
    await fetchMembers()
  } catch (e: unknown) {
    error.value = extractErrorMessage(e, t('adminPanel.failedGrantAccess'))
  } finally {
    loading.value = false
  }
}

async function revokeMember(id: number) {
  if (!selectedWsId.value) return
  loading.value = true
  try {
    await api.delete(`/admin/workspaces/${selectedWsId.value}/members/${id}`)
    await fetchMembers()
  } catch (e: unknown) {
    error.value = extractErrorMessage(e, t('adminPanel.failedRevokeAccess'))
  } finally {
    loading.value = false
  }
}

async function createUser() {
  loading.value = true
  try {
    await api.post('/admin/users', newUser.value)
    newUser.value = { name: '', email: '', password: '', is_admin: false }
    await fetchUsers()
  } catch (e: unknown) {
    error.value = extractErrorMessage(e, t('adminPanel.failedCreateUser'))
  } finally {
    loading.value = false
  }
}

async function resetPassword(u: any) {
  const newPassword = prompt(t('adminPanel.promptNewPassword', { email: u.email }))
  if (!newPassword) return
  loading.value = true
  try {
    await adminResetPassword(u.id, newPassword)
  } catch (e: unknown) {
    error.value = extractErrorMessage(e, t('adminPanel.failedResetPassword'))
  } finally {
    loading.value = false
  }
}

async function toggleDeactivate(u: any, deactivate: boolean) {
  loading.value = true
  try {
    const endpoint = deactivate ? `/admin/users/${u.id}/deactivate` : `/admin/users/${u.id}/reactivate`
    await api.post(endpoint)
    await fetchUsers()
  } catch (e: unknown) {
    error.value = extractErrorMessage(e, t('adminPanel.failedUpdateUser'))
  } finally {
    loading.value = false
  }
}

watch(() => props.isOpen, (val) => {
  if (val) {
    fetchTenants()
    fetchMachineTokens()
    fetchWorkspaces()
    fetchUsers()
  }
})
</script>

<style scoped>
.tab-badge {
  display: inline-block;
  min-width: 1.25rem;
  margin-left: var(--space-1);
  padding: 0 var(--space-1);
  border-radius: 999px;
  font-size: 0.75rem;
  color: var(--color-warning-fg);
  background: var(--color-warning-bg);
}

.admin-modal-overlay {
  position: fixed;
  top: 0;
  left: 0;
  right: 0;
  bottom: 0;
  background: var(--color-overlay);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 1000;
}
.admin-modal-container {
  background: var(--color-surface);
  color: var(--color-text);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-lg);
  width: 600px;
  max-width: 90%;
  max-height: 90vh;
  overflow-y: auto;
  padding: var(--space-6);
  box-shadow: var(--shadow-float);
}
.admin-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
}
.admin-tabs {
  display: flex;
  gap: var(--space-2);
  margin: var(--space-4) 0;
  border-bottom: 1px solid var(--color-border);
  overflow-x: auto;
}
.tab-btn {
  background: none;
  border: none;
  color: var(--color-text-muted);
  padding: var(--space-2) var(--space-4);
  cursor: pointer;
  white-space: nowrap;
}
.tab-btn.active {
  color: var(--color-action);
  border-bottom: 2px solid var(--color-action);
}
.admin-error-banner {
  background: color-mix(in srgb, var(--color-status-danger) 15%, transparent);
  color: var(--color-status-danger);
  border: 1px solid color-mix(in srgb, var(--color-status-danger) 40%, transparent);
  padding: var(--space-2) var(--space-3);
  border-radius: var(--radius-sm);
  margin-bottom: var(--space-4);
}
.admin-form {
  display: flex;
  flex-direction: column;
  gap: var(--space-2);
  margin-bottom: var(--space-4);
}
.admin-form input, .admin-form select {
  background: var(--color-canvas);
  border: 1px solid var(--color-border);
  color: var(--color-text);
  padding: var(--space-2);
  border-radius: var(--radius-sm);
}
.admin-list {
  list-style: none;
  padding: 0;
}
.admin-list-item {
  display: flex;
  flex-wrap: wrap;
  justify-content: space-between;
  align-items: center;
  gap: var(--space-2);
  padding: var(--space-2) 0;
  border-bottom: 1px solid var(--color-border);
}
.admin-list-item-column.admin-list-item {
  flex-direction: column;
  align-items: stretch;
}
.admin-list-item-column .admin-form {
  margin-bottom: 0;
}
.btn-group {
  display: flex;
  gap: var(--space-2);
}
.admin-fieldset {
  border: 1px solid var(--color-border);
  border-radius: var(--radius-sm);
  padding: var(--space-2);
  display: flex;
  flex-direction: column;
  gap: var(--space-1);
}
.admin-fieldset legend {
  padding: 0 var(--space-1);
  color: var(--color-text);
}
.admin-check {
  display: flex;
  align-items: center;
  gap: var(--space-2);
}
.admin-write-path {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: var(--space-2);
}
.admin-write-path input:not([type="checkbox"]) {
  flex: 1 1 12rem;
}
.admin-form-note {
  font-size: 0.75rem;
  color: var(--color-text-muted);
  margin: 0 0 var(--space-2);
}
.danger-btn { background: var(--color-status-danger); color: var(--color-neutral-0); border: none; padding: var(--space-1) var(--space-2); border-radius: var(--radius-sm); cursor: pointer; }
.warning-btn { background: var(--color-status-warning); color: var(--color-text-inverse); border: none; padding: var(--space-1) var(--space-2); border-radius: var(--radius-sm); cursor: pointer; }
.success-btn { background: var(--color-status-success); color: var(--color-neutral-0); border: none; padding: var(--space-1) var(--space-2); border-radius: var(--radius-sm); cursor: pointer; }
.secondary-btn { background: transparent; border: 1px solid var(--color-border); color: var(--color-text); padding: var(--space-1) var(--space-2); border-radius: var(--radius-sm); cursor: pointer; }
</style>

