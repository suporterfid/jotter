<template>
  <div class="modal-overlay" data-testid="change-password-modal" @click.self="$emit('close')">
    <div class="modal-card">
      <h3>{{ t('changePassword.heading') }}</h3>

      <p v-if="error" class="modal-error" data-testid="change-password-error" role="alert">{{ error }}</p>
      <p v-if="success" class="modal-success" data-testid="change-password-success" role="status">{{ t('changePassword.success') }}</p>

      <form @submit.prevent="submit">
        <input
          v-model="currentPassword"
          type="password"
          class="modal-input"
          :placeholder="t('changePassword.currentPlaceholder')"
          data-testid="change-password-current"
          required
        />
        <input
          v-model="newPassword"
          type="password"
          class="modal-input"
          :placeholder="t('changePassword.newPlaceholder')"
          data-testid="change-password-new"
          required
        />
        <input
          v-model="confirmPassword"
          type="password"
          class="modal-input"
          :placeholder="t('changePassword.confirmPlaceholder')"
          data-testid="change-password-confirm"
          required
        />
        <div class="modal-actions">
          <button type="button" class="btn-secondary" @click="$emit('close')">{{ t('changePassword.cancel') }}</button>
          <button
            type="submit"
            class="btn-primary"
            data-testid="change-password-submit"
            :disabled="loading"
          >{{ loading ? t('changePassword.saving') : t('changePassword.submit') }}</button>
        </div>
      </form>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, onUnmounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { changePassword } from '../services/api'

const { t } = useI18n()

const emit = defineEmits<{
  (e: 'close'): void
}>()

const currentPassword = ref('')
const newPassword = ref('')
const confirmPassword = ref('')
const error = ref('')
const success = ref(false)
const loading = ref(false)

const AUTO_CLOSE_DELAY_MS = 1500
let autoCloseTimer: ReturnType<typeof setTimeout> | null = null

onUnmounted(() => {
  if (autoCloseTimer) clearTimeout(autoCloseTimer)
})

async function submit() {
  error.value = ''
  success.value = false

  if (newPassword.value !== confirmPassword.value) {
    error.value = t('changePassword.mismatchError')
    return
  }

  loading.value = true
  try {
    await changePassword(currentPassword.value, newPassword.value)
    success.value = true
    currentPassword.value = ''
    newPassword.value = ''
    confirmPassword.value = ''
    autoCloseTimer = setTimeout(() => emit('close'), AUTO_CLOSE_DELAY_MS)
  } catch (e: unknown) {
    const axiosError = e as { response?: { data?: { message?: string } } }
    error.value = axiosError.response?.data?.message || t('changePassword.genericError')
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
</style>
