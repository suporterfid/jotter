<template>
  <div v-if="isOpen" class="admin-modal-overlay" data-testid="admin-panel" @click.self="close">
    <div class="admin-modal-container">
      <div class="admin-header">
        <h2>Administration</h2>
        <button class="close-btn" @click="close">&times;</button>
      </div>

      <div class="admin-tabs">
        <button
          :class="['tab-btn', { active: activeTab === 'workspaces' }]"
          @click="activeTab = 'workspaces'"
        >
          Workspaces
        </button>
        <button
          :class="['tab-btn', { active: activeTab === 'members' }]"
          @click="activeTab = 'members'"
        >
          Members
        </button>
        <button
          :class="['tab-btn', { active: activeTab === 'users' }]"
          @click="activeTab = 'users'"
        >
          Users
        </button>
      </div>

      <div v-if="error" class="admin-error-banner">
        {{ error }}
      </div>

      <!-- Workspaces Tab -->
      <div v-if="activeTab === 'workspaces'" class="tab-content">
        <h3>Create Workspace</h3>
        <form @submit.prevent="createWorkspace" class="admin-form">
          <select v-model.number="newWs.tenant_id" data-testid="admin-new-workspace-tenant" required>
            <option :value="null" disabled>Select a tenant…</option>
            <option v-for="tenant in tenants" :key="tenant.id" :value="tenant.id">{{ tenant.name }}</option>
          </select>
          <input v-model="newWs.name" placeholder="Workspace Name" data-testid="admin-new-workspace-name" required />
          <input v-model="newWs.slug" placeholder="Slug (e.g. dev)" data-testid="admin-new-workspace-slug" required />
          <input v-model="newWs.vault_path" placeholder="Vault Path" data-testid="admin-new-workspace-vault-path" required />
          <button type="submit" data-testid="admin-new-workspace-submit" :disabled="loading">Create Workspace</button>
        </form>

        <h3>Workspaces List</h3>
        <ul class="admin-list">
          <li v-for="ws in workspaces" :key="ws.id" class="admin-list-item" :class="{ 'admin-list-item-column': editingWsId === ws.id }">
            <template v-if="editingWsId === ws.id">
              <div class="admin-form">
                <input v-model="wsEditDraft.name" data-testid="admin-edit-workspace-name" placeholder="Workspace Name" required />
                <input v-model="wsEditDraft.slug" data-testid="admin-edit-workspace-slug" placeholder="Slug" required />
                <p class="admin-form-note">Vault path changes aren't offered here — moving the underlying files is handled separately.</p>
                <div class="btn-group">
                  <button class="secondary-btn" data-testid="admin-edit-workspace-cancel" type="button" @click="cancelEditWorkspace">Cancel</button>
                  <button class="success-btn" data-testid="admin-edit-workspace-save" type="button" :disabled="loading" @click="saveEditWorkspace(ws.id)">Save</button>
                </div>
              </div>
            </template>
            <template v-else>
              <span><strong>{{ ws.name }}</strong> ({{ ws.slug }})</span>
              <div class="btn-group">
                <button class="secondary-btn" data-testid="admin-edit-workspace-btn" @click="startEditWorkspace(ws)">Edit</button>
                <button class="danger-btn" @click="archiveWorkspace(ws.id)" :disabled="loading">Archive</button>
              </div>
            </template>
          </li>
        </ul>
      </div>

      <!-- Members Tab -->
      <div v-if="activeTab === 'members'" class="tab-content">
        <h3>Workspace Members</h3>
        <div class="select-group">
          <label>Workspace:</label>
          <select v-model="selectedWsId" @change="fetchMembers">
            <option v-for="ws in workspaces" :key="ws.id" :value="ws.id">{{ ws.name }}</option>
          </select>
        </div>

        <form @submit.prevent="grantMember" class="admin-form" v-if="selectedWsId">
          <input v-model="newMember.subject_id" placeholder="Subject ID / Email" required />
          <select v-model="newMember.role">
            <option value="owner">Owner</option>
            <option value="admin">Admin</option>
            <option value="editor">Editor</option>
            <option value="viewer">Viewer</option>
          </select>
          <button type="submit" :disabled="loading">Grant Access</button>
        </form>

        <ul class="admin-list" v-if="selectedWsId">
          <li v-for="m in members" :key="m.id" class="admin-list-item">
            <span><strong>{{ m.subject_id }}</strong> - {{ m.role }}</span>
            <button class="danger-btn" @click="revokeMember(m.id)" :disabled="loading">Revoke</button>
          </li>
        </ul>
      </div>

      <!-- Users Tab -->
      <div v-if="activeTab === 'users'" class="tab-content">
        <h3>Create Local User</h3>
        <form @submit.prevent="createUser" class="admin-form">
          <input v-model="newUser.name" placeholder="Full Name" required />
          <input v-model="newUser.email" type="email" placeholder="Email" required />
          <input v-model="newUser.password" type="password" placeholder="Password" required />
          <label class="checkbox-label">
            <input type="checkbox" v-model="newUser.is_admin" /> Is Admin
          </label>
          <button type="submit" :disabled="loading">Create User</button>
        </form>

        <h3>Users List</h3>
        <ul class="admin-list">
          <li v-for="u in users" :key="u.id" class="admin-list-item">
            <span><strong>{{ u.name }}</strong> ({{ u.email }}) <em v-if="!u.is_active">[Deactivated]</em></span>
            <div class="btn-group">
              <button class="secondary-btn" data-testid="admin-reset-password-btn" :disabled="loading" @click="resetPassword(u)">Reset Password</button>
              <button v-if="u.is_active" class="warning-btn" @click="toggleDeactivate(u, true)">Deactivate</button>
              <button v-else class="success-btn" @click="toggleDeactivate(u, false)">Reactivate</button>
            </div>
          </li>
        </ul>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, watch } from 'vue'
import { api, getTenants, adminResetPassword } from '../services/api'
import type { Tenant } from '../services/types'

const props = defineProps<{ isOpen: boolean }>()
const emit = defineEmits(['close'])

const activeTab = ref<'workspaces' | 'members' | 'users'>('workspaces')
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
    error.value = extractErrorMessage(e, 'Failed to load tenants.')
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
    error.value = extractErrorMessage(e, 'Failed to load workspaces.')
  }
}

async function fetchMembers() {
  if (!selectedWsId.value) return
  try {
    const response = await api.get<{ data: any[] }>(`/admin/workspaces/${selectedWsId.value}/members`)
    members.value = response.data.data || []
  } catch (e: unknown) {
    error.value = extractErrorMessage(e, 'Failed to load members.')
  }
}

async function fetchUsers() {
  try {
    const response = await api.get<{ data: any[] }>('/admin/users')
    users.value = response.data.data || []
  } catch (e: unknown) {
    error.value = extractErrorMessage(e, 'Failed to load users.')
  }
}

async function createWorkspace() {
  error.value = ''
  if (!newWs.value.tenant_id) {
    error.value = 'Select a tenant.'
    return
  }
  loading.value = true
  try {
    await api.post('/admin/workspaces', newWs.value)
    newWs.value = { tenant_id: newWs.value.tenant_id, name: '', slug: '', vault_path: '' }
    await fetchWorkspaces()
  } catch (e: unknown) {
    error.value = extractErrorMessage(e, 'Workspace creation failed.')
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
    error.value = extractErrorMessage(e, 'Failed to update workspace.')
  } finally {
    loading.value = false
  }
}

async function archiveWorkspace(id: number) {
  if (!confirm('Are you sure you want to archive this workspace? Files will be preserved.')) return
  loading.value = true
  try {
    await api.post(`/admin/workspaces/${id}/archive`)
    await fetchWorkspaces()
  } catch (e: unknown) {
    error.value = extractErrorMessage(e, 'Failed to archive workspace.')
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
    error.value = extractErrorMessage(e, 'Failed to grant access.')
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
    error.value = extractErrorMessage(e, 'Failed to revoke access.')
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
    error.value = extractErrorMessage(e, 'Failed to create user.')
  } finally {
    loading.value = false
  }
}

async function resetPassword(u: any) {
  const newPassword = prompt(`New password for ${u.email}:`)
  if (!newPassword) return
  loading.value = true
  try {
    await adminResetPassword(u.id, newPassword)
  } catch (e: unknown) {
    error.value = extractErrorMessage(e, 'Failed to reset password.')
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
    error.value = extractErrorMessage(e, 'Failed to update user.')
  } finally {
    loading.value = false
  }
}

watch(() => props.isOpen, (val) => {
  if (val) {
    fetchTenants()
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

