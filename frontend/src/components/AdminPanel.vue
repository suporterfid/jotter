<template>
  <div v-if="isOpen" class="admin-modal-overlay" @click.self="close">
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
          <input v-model="newWs.name" placeholder="Workspace Name" required />
          <input v-model="newWs.slug" placeholder="Slug (e.g. dev)" required />
          <input v-model="newWs.vault_path" placeholder="Vault Path" required />
          <button type="submit" :disabled="loading">Create Workspace</button>
        </form>

        <h3>Workspaces List</h3>
        <ul class="admin-list">
          <li v-for="ws in workspaces" :key="ws.id" class="admin-list-item">
            <span><strong>{{ ws.name }}</strong> ({{ ws.slug }})</span>
            <button class="danger-btn" @click="archiveWorkspace(ws.id)" :disabled="loading">Archive</button>
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

const props = defineProps<{ isOpen: boolean }>()
const emit = defineEmits(['close'])

const activeTab = ref<'workspaces' | 'members' | 'users'>('workspaces')
const error = ref('')
const loading = ref(false)

const workspaces = ref<any[]>([])
const selectedWsId = ref<number | null>(null)
const members = ref<any[]>([])
const users = ref<any[]>([])

const newWs = ref({ name: '', slug: '', vault_path: '' })
const newMember = ref({ subject_id: '', role: 'editor' })
const newUser = ref({ name: '', email: '', password: '', is_admin: false })

function close() {
  emit('close')
}

async function fetchWorkspaces() {
  try {
    const res = await fetch('/api/workspaces')
    if (res.ok) {
      const json = await res.json()
      workspaces.value = json.data || []
      if (workspaces.value.length && !selectedWsId.value) {
        selectedWsId.value = workspaces.value[0].id
        fetchMembers()
      }
    }
  } catch (e: any) {
    error.value = e.message
  }
}

async function fetchMembers() {
  if (!selectedWsId.value) return
  try {
    const res = await fetch(`/api/admin/workspaces/${selectedWsId.value}/members`)
    if (res.ok) {
      const json = await res.json()
      members.value = json.data || []
    }
  } catch (e: any) {
    error.value = e.message
  }
}

async function fetchUsers() {
  try {
    const res = await fetch('/api/admin/users')
    if (res.ok) {
      const json = await res.json()
      users.value = json.data || []
    }
  } catch (e: any) {
    error.value = e.message
  }
}

async function createWorkspace() {
  error.value = ''
  loading.value = true
  try {
    const res = await fetch('/api/admin/workspaces', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(newWs.value),
    })
    const json = await res.json()
    if (!res.ok) {
      error.value = json.message || 'Workspace creation failed.'
    } else {
      newWs.value = { name: '', slug: '', vault_path: '' }
      fetchWorkspaces()
    }
  } catch (e: any) {
    error.value = e.message
  } finally {
    loading.value = false
  }
}

async function archiveWorkspace(id: number) {
  if (!confirm('Are you sure you want to archive this workspace? Files will be preserved.')) return
  loading.value = true
  try {
    await fetch(`/api/admin/workspaces/${id}/archive`, { method: 'POST' })
    fetchWorkspaces()
  } catch (e: any) {
    error.value = e.message
  } finally {
    loading.value = false
  }
}

async function grantMember() {
  if (!selectedWsId.value) return
  loading.value = true
  try {
    const res = await fetch(`/api/admin/workspaces/${selectedWsId.value}/members`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(newMember.value),
    })
    if (res.ok) {
      newMember.value = { subject_id: '', role: 'editor' }
      fetchMembers()
    }
  } catch (e: any) {
    error.value = e.message
  } finally {
    loading.value = false
  }
}

async function revokeMember(id: number) {
  if (!selectedWsId.value) return
  loading.value = true
  try {
    await fetch(`/api/admin/workspaces/${selectedWsId.value}/members/${id}`, { method: 'DELETE' })
    fetchMembers()
  } catch (e: any) {
    error.value = e.message
  } finally {
    loading.value = false
  }
}

async function createUser() {
  loading.value = true
  try {
    const res = await fetch('/api/admin/users', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(newUser.value),
    })
    if (res.ok) {
      newUser.value = { name: '', email: '', password: '', is_admin: false }
      fetchUsers()
    }
  } catch (e: any) {
    error.value = e.message
  } finally {
    loading.value = false
  }
}

async function toggleDeactivate(u: any, deactivate: boolean) {
  loading.value = true
  try {
    const endpoint = deactivate ? `/api/admin/users/${u.id}/deactivate` : `/api/admin/users/${u.id}/reactivate`
    await fetch(endpoint, { method: 'POST' })
    fetchUsers()
  } catch (e: any) {
    error.value = e.message
  } finally {
    loading.value = false
  }
}

watch(() => props.isOpen, (val) => {
  if (val) {
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
  background: rgba(0, 0, 0, 0.6);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 1000;
}
.admin-modal-container {
  background: #1e1e2e;
  color: #cdd6f4;
  border-radius: 8px;
  width: 600px;
  max-width: 90%;
  padding: 20px;
}
.admin-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
}
.admin-tabs {
  display: flex;
  gap: 10px;
  margin: 15px 0;
  border-bottom: 1px solid #313244;
}
.tab-btn {
  background: none;
  border: none;
  color: #a6adc8;
  padding: 8px 16px;
  cursor: pointer;
}
.tab-btn.active {
  color: #cba6f7;
  border-bottom: 2px solid #cba6f7;
}
.admin-error-banner {
  background: #f38ba8;
  color: #11111b;
  padding: 10px;
  border-radius: 4px;
  margin-bottom: 15px;
}
.admin-form {
  display: flex;
  flex-direction: column;
  gap: 10px;
  margin-bottom: 20px;
}
.admin-form input, .admin-form select {
  background: #313244;
  border: 1px solid #45475a;
  color: #cdd6f4;
  padding: 8px;
  border-radius: 4px;
}
.admin-list {
  list-style: none;
  padding: 0;
}
.admin-list-item {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 8px 0;
  border-bottom: 1px solid #313244;
}
.danger-btn { background: #f38ba8; color: #11111b; border: none; padding: 4px 8px; border-radius: 4px; cursor: pointer; }
.warning-btn { background: #fab387; color: #11111b; border: none; padding: 4px 8px; border-radius: 4px; cursor: pointer; }
.success-btn { background: #a6e3a1; color: #11111b; border: none; padding: 4px 8px; border-radius: 4px; cursor: pointer; }
</style>
