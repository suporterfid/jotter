<template>
  <div v-if="show" class="login-overlay">
    <div class="login-card">
      <div class="login-header">
        <div class="login-brand">
          <BrandMark :size="28" />
          <h2 data-testid="login-heading">{{ t('login.heading', { name: brand.name }) }}</h2>
        </div>
        <p class="login-subtitle">{{ t('login.subtitle') }}</p>
      </div>

      <div v-if="ssoLoginUrl" class="sso-section">
        <a :href="ssoLoginUrl" class="btn-sso" data-testid="sso-login-link">
          {{ t(authProvider === 'oidc' ? 'login.ssoGenericButton' : 'login.ssoButton') }}
        </a>
        <div class="sso-divider"><span>{{ t('login.or') }}</span></div>
      </div>

      <form v-if="mode === 'register'" @submit.prevent="handleRegister" class="login-form" data-testid="signup-form">
        <h3 class="signup-heading">{{ t('login.signupHeading') }}</h3>
        <p class="login-subtitle">{{ t('login.signupHelp') }}</p>
        <div v-if="errorMessage" class="login-error" data-testid="signup-error">{{ errorMessage }}</div>
        <div v-if="signupDone" class="login-success" role="status" data-testid="signup-done">{{ signupDone }}</div>
        <template v-else>
          <div class="form-group">
            <label for="signup-name">{{ t('login.nameLabel') }}</label>
            <input id="signup-name" v-model="signup.name" data-testid="signup-name" type="text" required maxlength="191" class="form-input" autocomplete="name" />
          </div>
          <div class="form-group">
            <label for="signup-email">{{ t('login.emailLabel') }}</label>
            <input id="signup-email" v-model="signup.email" data-testid="signup-email" type="email" required class="form-input" autocomplete="email" />
          </div>
          <div class="form-group">
            <label for="signup-password">{{ t('login.passwordLabel') }}</label>
            <input id="signup-password" v-model="signup.password" data-testid="signup-password" type="password" required minlength="8" class="form-input" autocomplete="new-password" />
          </div>
          <div class="form-group">
            <label for="signup-password-confirm">{{ t('login.passwordConfirmLabel') }}</label>
            <input id="signup-password-confirm" v-model="signup.passwordConfirmation" data-testid="signup-password-confirm" type="password" required class="form-input" autocomplete="new-password" />
          </div>
          <label v-if="brand.terms_url || brand.privacy_url" class="signup-terms">
            <input v-model="signup.acceptTerms" type="checkbox" data-testid="signup-terms" required />
            <span>
              {{ t('login.acceptTermsBefore') }}
              <a v-if="brand.terms_url" :href="brand.terms_url" target="_blank" rel="noopener">{{ t('login.termsLink') }}</a>
              <template v-if="brand.terms_url && brand.privacy_url"> {{ t('login.and') }} </template>
              <a v-if="brand.privacy_url" :href="brand.privacy_url" target="_blank" rel="noopener">{{ t('login.privacyLink') }}</a>
            </span>
          </label>
          <button type="submit" class="btn-login" data-testid="signup-submit" :disabled="isSubmitting">
            {{ isSubmitting ? t('login.signupSending') : t('login.signupSubmit') }}
          </button>
        </template>
        <button type="button" class="btn-link" data-testid="signup-back" @click="switchMode('login')">{{ t('login.backToSignIn') }}</button>
      </form>

      <form v-else @submit.prevent="handleLogin" class="login-form">
        <div v-if="errorMessage" class="login-error" data-testid="login-error">
          {{ errorMessage }}
        </div>

        <div class="form-group">
          <label for="login-email">{{ t('login.emailLabel') }}</label>
          <input
            id="login-email"
            v-model="email"
            data-testid="login-email"
            type="email"
            :placeholder="t('login.emailPlaceholder')"
            required
            class="form-input"
          />
        </div>

        <div class="form-group">
          <label for="login-password">{{ t('login.passwordLabel') }}</label>
          <input
            id="login-password"
            v-model="password"
            data-testid="login-password"
            type="password"
            :placeholder="t('login.passwordPlaceholder')"
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
          <span v-if="isSubmitting">{{ t('login.signingIn') }}</span>
          <span v-else>{{ t('login.signIn') }}</span>
        </button>
        <button v-if="signupEnabled" type="button" class="btn-link" data-testid="signup-open" @click="switchMode('register')">{{ t('login.createAccount') }}</button>
      </form>
      <BrandFooter />
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { login, getAuthConfig, register } from '../services/api'
import { brand, setBrand } from '../services/brand'
import BrandMark from './BrandMark.vue'
import BrandFooter from './BrandFooter.vue'
import type { AuthConfig } from '../services/api'
import type { AuthUser } from '../services/types'

const { t, locale } = useI18n()

const props = defineProps<{
  show: boolean
}>()

const emit = defineEmits<{
  (e: 'login-success', user: AuthUser): void
}>()

const email = ref('')
const password = ref('')
const isSubmitting = ref(false)
const errorMessage = ref('')
const ssoLoginUrl = ref<string | null>(null)
const authProvider = ref<AuthConfig['provider']>('local')
const signupEnabled = ref(false)
const mode = ref<'login' | 'register'>('login')
const signupDone = ref('')
const signup = ref({ name: '', email: '', password: '', passwordConfirmation: '', acceptTerms: false })

function switchMode(next: 'login' | 'register') {
  mode.value = next
  errorMessage.value = ''
  signupDone.value = ''
}

// Self sign-up with administrator approval (#390).
async function handleRegister() {
  errorMessage.value = ''
  if (signup.value.password !== signup.value.passwordConfirmation) {
    errorMessage.value = t('login.passwordMismatch')
    return
  }
  isSubmitting.value = true
  try {
    signupDone.value = await register({
      name: signup.value.name.trim(),
      email: signup.value.email.trim(),
      password: signup.value.password,
      password_confirmation: signup.value.passwordConfirmation,
      accept_terms: signup.value.acceptTerms,
      locale: locale.value,
    })
    signup.value = { name: '', email: '', password: '', passwordConfirmation: '', acceptTerms: false }
  } catch (err: unknown) {
    const data = (err as { response?: { data?: { message?: string; errors?: Record<string, string[]> } } })?.response?.data
    const firstError = data?.errors ? Object.values(data.errors)[0]?.[0] : undefined
    errorMessage.value = firstError || data?.message || t('login.connectionFailed')
  } finally {
    isSubmitting.value = false
  }
}

watch(
  () => props.show,
  async (visible) => {
    if (!visible) return
    try {
      const config = await getAuthConfig()
      ssoLoginUrl.value = config.sso_login_url
      authProvider.value = config.provider
      signupEnabled.value = config.signup_enabled === true
      if (config.brand) setBrand(config.brand)
    } catch (err) {
      console.error('Failed to load auth config:', err)
    }
  },
  { immediate: true },
)

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
      errorMessage.value = res?.data?.message || t('login.invalidCredentials')
    } else {
      errorMessage.value = t('login.connectionFailed')
    }
  } finally {
    isSubmitting.value = false
  }
}
</script>

<style scoped>
.signup-heading {
  margin: 0;
  font-size: 1.05rem;
}

.signup-terms {
  display: flex;
  gap: var(--space-2);
  align-items: flex-start;
  font-size: 0.875rem;
}

.login-success {
  padding: var(--space-3);
  border-radius: var(--radius-md, 6px);
  color: var(--color-text);
  background: var(--color-bg-surface);
  border: 1px solid var(--color-border);
}

.btn-link {
  align-self: center;
  border: none;
  background: none;
  color: var(--color-accent);
  cursor: pointer;
  font: inherit;
  font-size: 0.875rem;
  text-decoration: underline;
}

/* Solid overlay — no backdrop-filter (fixes WCAG contrast guarantee) */
.login-overlay {
  position: fixed;
  inset: 0;
  background: var(--color-overlay-dark);
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
  box-shadow: var(--shadow-float);
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

.sso-section {
  margin-bottom: var(--space-4);
}

.btn-sso {
  display: flex;
  align-items: center;
  justify-content: center;
  background: var(--color-surface-emphasis);
  color: var(--color-text);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-sm);
  padding: var(--space-3);
  font-size: 1rem;
  font-weight: 600;
  text-decoration: none;
  min-height: 44px;
  transition: border-color var(--duration-fast) var(--ease-standard);
}

.btn-sso:hover {
  border-color: var(--color-action);
}

.sso-divider {
  display: flex;
  align-items: center;
  gap: var(--space-2);
  margin-top: var(--space-4);
  color: var(--color-text-muted);
  font-size: 0.75rem;
  text-transform: uppercase;
}

.sso-divider::before,
.sso-divider::after {
  content: '';
  flex: 1;
  height: 1px;
  background: var(--color-border);
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
