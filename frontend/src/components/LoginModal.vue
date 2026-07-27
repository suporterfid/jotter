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
.login-overlay {
  position: fixed;
  top: 0;
  left: 0;
  right: 0;
  bottom: 0;
  background: rgba(9, 9, 11, 0.85);
  backdrop-filter: blur(8px);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 1000;
}

.login-card {
  background: #18181b;
  border: 1px solid #27272a;
  border-radius: 12px;
  width: 100%;
  max-width: 400px;
  padding: 2rem;
  box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.5);
}

.login-header {
  text-align: center;
  margin-bottom: 1.5rem;
}

.login-brand {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 0.5rem;
  color: #818cf8;
  margin-bottom: 0.5rem;
}

.login-brand h2 {
  font-size: 1.35rem;
  font-weight: 700;
  color: #f4f4f5;
}

.login-subtitle {
  font-size: 0.85rem;
  color: #a1a1aa;
}

.login-form {
  display: flex;
  flex-direction: column;
  gap: 1.25rem;
}

.login-error {
  background: rgba(239, 68, 68, 0.15);
  border: 1px solid rgba(239, 68, 68, 0.3);
  color: #fca5a5;
  padding: 0.75rem;
  border-radius: 6px;
  font-size: 0.85rem;
  text-align: center;
}

.form-group {
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
}

.form-group label {
  font-size: 0.8rem;
  font-weight: 600;
  color: #d4d4d8;
}

.form-input {
  background: #09090b;
  border: 1px solid #27272a;
  border-radius: 6px;
  padding: 0.65rem 0.85rem;
  color: #f4f4f5;
  font-size: 0.9rem;
  outline: none;
  transition: border-color 0.2s;
}

.form-input:focus {
  border-color: #6366f1;
}

.btn-login {
  background: #6366f1;
  color: #ffffff;
  border: none;
  border-radius: 6px;
  padding: 0.75rem;
  font-size: 0.95rem;
  font-weight: 600;
  cursor: pointer;
  transition: background-color 0.2s;
  margin-top: 0.5rem;
}

.btn-login:hover:not(:disabled) {
  background: #4f46e5;
}

.btn-login:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}
</style>
