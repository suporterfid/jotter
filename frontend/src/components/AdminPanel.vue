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
        </button>
        <button
          :class="['tab-btn', { active: activeTab === 'mcp' }]"
          data-testid="admin-tab-mcp"
          @click="activeTab = 'mcp'"
        >
          {{ t('adminPanel.mcpTokens') }}
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
                <p class="admin-form-note">{{ t('adminPanel.vaultPathNote') }}</p>
                <div class="btn-group">
                  <button class="secondary-btn" data-testid="admin-edit-workspace-cancel" type="button" @click="cancelEditWorkspace">{{ t('adminPanel.cancel') }}</button>
                  <button class="success-btn" data-testid="admin-edit-workspace-save" type="button" :disabled="loading" @click="saveEditWorkspace(ws.id)">{{ t('adminPanel.save') }}</button>
                </div>
              </div>
            </template>
            <template v-else>
              <span><strong>{{ ws.name }}</strong> ({{ ws.slug }})</span>
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
          <li v-for="u in users" :key="u.id" class="admin-list-item">
            <span><strong>{{ u.name }}</strong> ({{ u.email }}) <em v-if="!u.is_active">{{ t('adminPanel.deactivatedTag') }}</em></span>
            <div class="btn-group">
              <button class="secondary-btn" data-testid="admin-reset-password-btn" :disabled="loading" @click="resetPassword(u)">{{ t('adminPanel.resetPassword') }}</button>
              <button v-if="u.is_active" class="warning-btn" @click="toggleDeactivate(u, true)">{{ t('adminPanel.deactivate') }}</button>
              <button v-else class="success-btn" @click="toggleDeactivate(u, false)">{{ t('adminPanel.reactivate') }}</button>
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
            </span>
            <button v-if="!tk.revoked_at" class="warning-btn" :disabled="loading" @click="revokeMcpToken(tk)">{{ t('adminPanel.mcpRevoke') }}</button>
          </li>
        </ul>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { api, getTenants, adminResetPassword } from '../services/api'
import type { Tenant } from '../services/types'
import McpClientSnippets from './McpClientSnippets.vue'

const { t } = useI18n()

const props = defineProps<{ isOpen: boolean }>()
const emit = defineEmits(['close'])

const activeTab = ref<'workspaces' | 'members' | 'users' | 'mcp'>('workspaces')
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
  created_at: string | null
  revoked_at: string | null
}
const machineTokens = ref<MachineTokenRow[]>([])
const newToken = ref<{ tenant_id: number | null; user_id: number | null; name: string }>({ tenant_id: null, user_id: null, name: '' })
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
    const response = await api.post<{ data: { token: string; mcp_url: string; tenant_slug: string | null } }>('/admin/machine-tokens', newToken.value)
    const data = response.data.data
    const host = data.mcp_url ? data.mcp_url.replace(/\/api\/mcp$/, '') : ''
    issuedToken.value = { token: data.token, host, serverName: data.tenant_slug ? `jotter-${data.tenant_slug}` : 'jotter' }
    newToken.value = { ...newToken.value, name: '' }
    await fetchMachineTokens()
  } catch (e) {
    error.value = extractErrorMessage(e, t('adminPanel.requestFailed'))
  } finally {
    loading.value = false
  }
}

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
const wsEditDraft = ref({ name: '', slug: '' })

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
  wsEditDraft.value = { name: ws.name, slug: ws.slug }
}

function cancelEditWorkspace() {
  editingWsId.value = null
}

async function saveEditWorkspace(id: number) {
  error.value = ''
  loading.value = true
  try {
    await api.put(`/admin/workspaces/${id}`, wsEditDraft.value)
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

