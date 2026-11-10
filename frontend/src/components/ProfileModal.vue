<template>
  <div class="modal-overlay" data-testid="profile-modal" @click.self="$emit('close')">
    <div class="modal-card" role="dialog" aria-modal="true" :aria-label="t('profile.heading')">
      <h3>{{ t('profile.heading') }}</h3>

      <p v-if="error" class="modal-error" data-testid="profile-error" role="alert">{{ error }}</p>
      <p v-if="success" class="modal-success" data-testid="profile-success" role="status">{{ t('profile.success') }}</p>

      <form @submit.prevent="submit">
        <label class="modal-label" for="profile-name">{{ t('profile.nameLabel') }}</label>
        <input
          id="profile-name"
          v-model="name"
          type="text"
          class="modal-input"
          maxlength="191"
          autocomplete="name"
          data-testid="profile-name"
          required
        />
        <p class="modal-help">{{ t('profile.emailHelp', { email: email }) }}</p>
        <div class="modal-actions">
          <button type="button" class="btn-secondary" @click="$emit('close')">{{ t('profile.cancel') }}</button>
          <button type="submit" class="btn-primary" data-testid="profile-submit" :disabled="loading || !name.trim()">
            {{ loading ? t('profile.saving') : t('profile.submit') }}
          </button>
        </div>
      </form>

      <MyDataPanel :deletion-requested-at="deletionRequestedAt" @deletion-changed="$emit('deletion-changed', $event)" />
    </div>
  </div>
</template>

<script setup lang="ts">
import { onUnmounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { updateProfile } from '../services/api'
import MyDataPanel from './MyDataPanel.vue'

// The person corrects their own name (#390, LGPD right to correction).
const props = defineProps<{ currentName: string; email: string; deletionRequestedAt?: string | null }>()
const emit = defineEmits<{
  (e: 'close'): void
  (e: 'updated', name: string): void
  (e: 'deletion-changed', requestedAt: string | null): void
}>()
const { t } = useI18n()

const name = ref(props.currentName)
const error = ref('')
const success = ref(false)
const loading = ref(false)
let autoCloseTimer: ReturnType<typeof setTimeout> | null = null

onUnmounted(() => {
  if (autoCloseTimer) clearTimeout(autoCloseTimer)
})

async function submit() {
  error.value = ''
  success.value = false
  loading.value = true
  try {
    const saved = await updateProfile(name.value.trim())
    name.value = saved
    success.value = true
    emit('updated', saved)
    autoCloseTimer = setTimeout(() => emit('close'), 1500)
  } catch (e: unknown) {
    const data = (e as { response?: { data?: { message?: string; errors?: Record<string, string[]> } } })?.response?.data
    error.value = (data?.errors ? Object.values(data.errors)[0]?.[0] : undefined) || data?.message || t('profile.genericError')
  } finally {
    loading.value = false
  }
}
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
  width: 360px;
  box-shadow: var(--shadow-float);
}

.modal-card h3 {
  margin: 0 0 var(--space-4);
  color: var(--color-text);
  font-size: 1.125rem;
  font-weight: 600;
}

.modal-error {
  background: color-mix(in srgb, var(--color-status-danger) 15%, transparent);
  color: var(--color-status-danger);
  border: 1px solid color-mix(in srgb, var(--color-status-danger) 40%, transparent);
  padding: var(--space-2) var(--space-3);
  border-radius: var(--radius-sm);
  margin-bottom: var(--space-4);
  font-size: 0.8125rem;
}

.modal-success {
  background: color-mix(in srgb, var(--color-status-success) 15%, transparent);
  color: var(--color-status-success);
  border: 1px solid color-mix(in srgb, var(--color-status-success) 40%, transparent);
  padding: var(--space-2) var(--space-3);
  border-radius: var(--radius-sm);
  margin-bottom: var(--space-4);
  font-size: 0.8125rem;
}

.modal-input {
  width: 100%;
  background: var(--color-canvas);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-sm);
  padding: var(--space-2) var(--space-3);
  color: var(--color-text);
  margin-bottom: var(--space-4);
  font-size: 0.875rem;
  transition: border-color var(--duration-fast) var(--ease-standard);
}

.modal-input:focus {
  border-color: var(--color-action);
}

.modal-actions {
  display: flex;
  justify-content: flex-end;
  gap: var(--space-2);
}

.btn-secondary {
  background: transparent;
  border: 1px solid var(--color-border);
  color: var(--color-text);
  padding: var(--space-2) var(--space-4);
  border-radius: var(--radius-sm);
  cursor: pointer;
  font-size: 0.875rem;
}

.btn-primary {
  background: var(--color-action);
  color: var(--color-neutral-0);
  border: none;
  padding: var(--space-2) var(--space-4);
  border-radius: var(--radius-sm);
  cursor: pointer;
  font-size: 0.875rem;
  transition: background-color var(--duration-fast) var(--ease-standard);
}

.btn-primary:hover:not(:disabled) {
  background: var(--color-action-hover);
}

.btn-primary:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}

.modal-label {
  display: block;
  margin-bottom: var(--space-1);
  font-size: 0.875rem;
}

.modal-help {
  margin: var(--space-1) 0 var(--space-3);
  font-size: 0.8125rem;
  color: var(--color-text-muted);
}
</style>
