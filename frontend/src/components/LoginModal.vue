<template>
  <div v-if="show" class="login-overlay">
    <div class="login-card">
      <div class="login-header">
        <div class="login-brand">
          <svg class="brand-icon" viewBox="0 0 24 24" width="28" height="28" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
            <polyline points="14 2 14 8 20 8"></polyline>
            <line x1="16" y1="13" x2="8" y2="13"></line>
            <line x1="16" y1="17" x2="8" y2="17"></line>
            <polyline points="10 9 9 9 8 9"></polyline>
          </svg>
          <h2>Jotter Sign In</h2>
        </div>
        <p class="login-subtitle">Enter your administrator credentials to access your notes vault.</p>
      </div>

      <form @submit.prevent="handleLogin" class="login-form">
        <div v-if="errorMessage" class="login-error" data-testid="login-error">
          {{ errorMessage }}
        </div>

        <div class="form-group">
          <label for="login-email">Email Address</label>
          <input
            id="login-email"
            v-model="email"
            data-testid="login-email"
            type="email"
            placeholder="admin@example.com"
            required
            class="form-input"
          />
        </div>

        <div class="form-group">
          <label for="login-password">Password</label>
          <input
            id="login-password"
            v-model="password"
            data-testid="login-password"
            type="password"
            placeholder="••••••••••••"
            required
            class="form-input"
          />
        </div>

        <button
          type="submit"
          data-testid="login-submit"
          class="btn-login"
          :disabled="isSubmitting || !email.trim() || !password"
        >
          <span v-if="isSubmitting">Signing In...</span>
          <span v-else>Sign In</span>
        </button>
      </form>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref } from 'vue'
import { login } from '../services/api'
import type { AuthUser } from '../services/types'

defineProps<{
  show: boolean
}>()

const emit = defineEmits<{
  (e: 'login-success', user: AuthUser): void
}>()

const email = ref('')
const password = ref('')
const isSubmitting = ref(false)
const errorMessage = ref('')

async function handleLogin() {
  if (!email.value.trim() || !password.value) return
  isSubmitting.value = true
  errorMessage.value = ''

  try {
    const user = await login(email.value.trim(), password.value)
    email.value = ''
    password.value = ''
    emit('login-success', user)
  } catch (err: unknown) {
    if (err && typeof err === 'object' && 'response' in err) {
      const res = (err as { response?: { data?: { message?: string } } }).response
      errorMessage.value = res?.data?.message || 'Invalid email or password.'
    } else {
      errorMessage.value = 'Failed to connect to authentication service.'
    }
  } finally {
    isSubmitting.value = false
  }
}
</script>

<style scoped>
/* Solid overlay — no backdrop-filter (fixes WCAG contrast guarantee) */
.login-overlay {
  position: fixed;
  inset: 0;
  background: rgb(0 0 0 / 85%);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 1000;
}

.login-card {
  background: var(--color-surface);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-lg);
  width: 100%;
  max-width: 400px;
  padding: var(--space-8);
  box-shadow: 0 12px 32px rgb(0 0 0 / 32%);
}

.login-header {
  text-align: center;
  margin-bottom: var(--space-6);
}

.login-brand {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: var(--space-2);
  color: var(--color-action);
  margin-bottom: var(--space-2);
}

.login-brand h2 {
  font-size: 1.35rem;
  font-weight: 700;
  color: var(--color-text);
}

.login-subtitle {
  font-size: 0.875rem;
  color: var(--color-text-muted);
}

.login-form {
  display: flex;
  flex-direction: column;
  gap: var(--space-4);
}

.login-error {
  background: color-mix(in srgb, var(--color-status-danger) 15%, transparent);
  border: 1px solid color-mix(in srgb, var(--color-status-danger) 40%, transparent);
  color: var(--color-status-danger);
  padding: var(--space-3);
  border-radius: var(--radius-sm);
  font-size: 0.875rem;
  text-align: center;
}

.form-group {
  display: flex;
  flex-direction: column;
  gap: var(--space-1);
}

.form-group label {
  font-size: 0.875rem;
  font-weight: 600;
  color: var(--color-text);
}

.form-input {
  background: var(--color-canvas);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-sm);
  padding: var(--space-2) var(--space-3);
  color: var(--color-text);
  font-size: 1rem;
  transition: border-color var(--duration-fast) var(--ease-standard);
  min-height: 44px;
}

.form-input:focus {
  border-color: var(--color-action);
}

.btn-login {
  background: var(--color-action);
  color: var(--color-neutral-0);
  border: none;
  border-radius: var(--radius-sm);
  padding: var(--space-3);
  font-size: 1rem;
  font-weight: 600;
  cursor: pointer;
  transition: background-color var(--duration-fast) var(--ease-standard);
  margin-top: var(--space-2);
  min-height: 44px;
}

.btn-login:hover:not(:disabled) {
  background: var(--color-action-hover);
}

.btn-login:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}
</style>
