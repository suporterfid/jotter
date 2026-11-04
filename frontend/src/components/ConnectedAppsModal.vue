<template>
  <div class="modal-overlay" data-testid="connected-apps-modal" @click.self="$emit('close')">
    <div class="modal-card" role="dialog" aria-modal="true" :aria-label="t('connectedApps.heading')">
      <h3>{{ t('connectedApps.heading') }}</h3>
      <p class="modal-help">{{ t('connectedApps.help') }}</p>

      <p v-if="error" class="modal-error" role="alert">{{ error }}</p>
      <p v-if="loading && grants.length === 0" class="modal-help">{{ t('connectedApps.loading') }}</p>
      <p v-else-if="grants.length === 0" class="modal-help" data-testid="connected-apps-empty">{{ t('connectedApps.empty') }}</p>

      <ul class="grant-list">
        <li v-for="grant in grants" :key="grant.id" class="grant-item" :data-testid="`connected-app-${grant.id}`">
          <div>
            <strong>{{ grant.client_name || t('connectedApps.unnamed') }}</strong>
            <span class="grant-meta">{{ grant.redirect_hosts.join(', ') }}</span>
            <span class="grant-meta">
              {{ grant.workspaces.join(', ') }} · {{ grant.scopes.join(' ') }}
              · {{ t('connectedApps.lastUsed') }}: {{ grant.last_used_at ? formatDate(grant.last_used_at) : t('connectedApps.never') }}
              · {{ t('connectedApps.expires') }}: {{ grant.expires_at ? formatDate(grant.expires_at) : t('connectedApps.never') }}
            </span>
          </div>
          <button type="button" class="btn-danger" :disabled="loading" :data-testid="`connected-app-revoke-${grant.id}`" @click="revoke(grant)">
            {{ t('connectedApps.revoke') }}
          </button>
        </li>
      </ul>

      <div class="modal-actions">
        <button type="button" class="btn-secondary" @click="$emit('close')">{{ t('connectedApps.close') }}</button>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { api } from '../services/api'

interface ConnectedApp {
  id: number
  client_name: string | null
  redirect_hosts: string[]
  workspaces: string[]
  scopes: string[]
  last_used_at: string | null
  expires_at: string | null
}

defineEmits<{ (e: 'close'): void }>()
const { t } = useI18n()
const grants = ref<ConnectedApp[]>([])
const loading = ref(false)
const error = ref('')

function formatDate(iso: string): string {
  return new Date(iso).toLocaleString()
}

async function load() {
  loading.value = true
  error.value = ''
  try {
    const response = await api.get<{ data: ConnectedApp[] }>('/user/oauth-grants')
    grants.value = response.data.data
  } catch {
    error.value = t('connectedApps.failed')
  } finally {
    loading.value = false
  }
}

async function revoke(grant: ConnectedApp) {
  if (!confirm(t('connectedApps.revokeConfirm', { name: grant.client_name || t('connectedApps.unnamed') }))) return
  loading.value = true
  try {
    await api.delete(`/user/oauth-grants/${grant.id}`)
    grants.value = grants.value.filter((item) => item.id !== grant.id)
  } catch {
    error.value = t('connectedApps.failed')
  } finally {
    loading.value = false
  }
}

onMounted(load)
</script>

<style scoped>
.modal-overlay {
  position: fixed;
  inset: 0;
  background: var(--color-overlay);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 100;
}
.modal-card {
  background: var(--color-surface);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-lg);
  padding: var(--space-6);
  width: 520px;
  max-width: 90vw;
  max-height: 90vh;
  overflow-y: auto;
  box-shadow: var(--shadow-float);
}
.modal-card h3 {
  margin: 0 0 var(--space-2);
  color: var(--color-text);
  font-size: 1.125rem;
  font-weight: 600;
}
.modal-help {
  color: var(--color-text-muted);
  font-size: 0.8125rem;
  margin: 0 0 var(--space-4);
}
.modal-error {
  color: var(--color-status-danger);
  font-size: 0.8125rem;
  margin: 0 0 var(--space-4);
}
.grant-list {
  list-style: none;
  margin: 0 0 var(--space-4);
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: var(--space-3);
}
.grant-item {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: var(--space-3);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-sm);
  padding: var(--space-3);
  color: var(--color-text);
}
.grant-meta {
  display: block;
  color: var(--color-text-muted);
  font-size: 0.75rem;
}
.modal-actions {
  display: flex;
  justify-content: flex-end;
}
.btn-secondary {
  background: transparent;
  border: 1px solid var(--color-border);
  color: var(--color-text);
  padding: var(--space-2) var(--space-4);
  border-radius: var(--radius-sm);
  cursor: pointer;
}
.btn-danger {
  background: var(--color-status-danger);
  color: var(--color-neutral-0);
  border: none;
  padding: var(--space-1) var(--space-3);
  border-radius: var(--radius-sm);
  cursor: pointer;
  white-space: nowrap;
}
</style>
