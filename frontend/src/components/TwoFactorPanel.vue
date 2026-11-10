<template>
  <section v-if="state.available" class="two-factor" data-testid="two-factor">
    <h4>{{ t('twoFactor.heading') }}</h4>
    <p v-if="error" class="two-factor-error" role="alert" data-testid="two-factor-panel-error">{{ error }}</p>

    <!-- Recovery codes, shown once after enabling or renewing. -->
    <template v-if="recoveryCodes.length">
      <p class="two-factor-help">{{ t('twoFactor.codesHelp') }}</p>
      <ol class="two-factor-codes" data-testid="two-factor-codes">
        <li v-for="code in recoveryCodes" :key="code"><code>{{ code }}</code></li>
      </ol>
      <div class="two-factor-actions">
        <a :href="codesFileUrl" download="recovery-codes.txt" class="two-factor-button">{{ t('twoFactor.downloadCodes') }}</a>
        <button type="button" class="two-factor-button" data-testid="two-factor-codes-done" @click="recoveryCodes = []">{{ t('twoFactor.codesSaved') }}</button>
      </div>
    </template>

    <!-- Scanning the QR code and confirming the first code. -->
    <form v-else-if="setup" class="two-factor-form" @submit.prevent="confirm">
      <p class="two-factor-help">{{ t('twoFactor.scanHelp') }}</p>
      <img v-if="qrSrc" :src="qrSrc" class="two-factor-qr" :alt="t('twoFactor.qrAlt')" data-testid="two-factor-qr" width="176" height="176" />
      <p class="two-factor-help">{{ t('twoFactor.manualKey') }} <code class="two-factor-secret" data-testid="two-factor-secret">{{ groupedSecret }}</code></p>
      <label class="two-factor-label" for="two-factor-confirm-code">{{ t('twoFactor.codeLabel') }}</label>
      <input id="two-factor-confirm-code" v-model="code" class="two-factor-input" inputmode="numeric" autocomplete="one-time-code" maxlength="6" data-testid="two-factor-confirm-code" required />
      <div class="two-factor-actions">
        <button type="button" class="two-factor-button" :disabled="busy" @click="setup = null">{{ t('twoFactor.cancel') }}</button>
        <button type="submit" class="two-factor-button two-factor-primary" :disabled="busy || code.trim().length !== 6" data-testid="two-factor-confirm">{{ t('twoFactor.confirm') }}</button>
      </div>
    </form>

    <!-- Password before any change. -->
    <form v-else-if="pending" class="two-factor-form" @submit.prevent="runPending">
      <label class="two-factor-label" for="two-factor-password">{{ t('twoFactor.passwordLabel') }}</label>
      <input id="two-factor-password" v-model="password" type="password" class="two-factor-input" autocomplete="current-password" data-testid="two-factor-password" required />
      <div class="two-factor-actions">
        <button type="button" class="two-factor-button" :disabled="busy" @click="pending = null">{{ t('twoFactor.cancel') }}</button>
        <button type="submit" class="two-factor-button" :class="{ 'two-factor-danger': pending === 'disable' }" :disabled="busy || !password" data-testid="two-factor-password-submit">
          {{ t(`twoFactor.continue.${pending}`) }}
        </button>
      </div>
    </form>

    <template v-else-if="state.enabled">
      <p class="two-factor-status" data-testid="two-factor-on">{{ t('twoFactor.on', { left: state.recovery_codes_left }) }}</p>
      <div class="two-factor-actions">
        <button type="button" class="two-factor-button" data-testid="two-factor-regenerate" @click="ask('regenerate')">{{ t('twoFactor.regenerate') }}</button>
        <button type="button" class="two-factor-button two-factor-danger" data-testid="two-factor-disable" @click="ask('disable')">{{ t('twoFactor.disable') }}</button>
      </div>
    </template>

    <template v-else>
      <p class="two-factor-help">{{ state.required ? t('twoFactor.requiredHelp') : t('twoFactor.offHelp') }}</p>
      <button type="button" class="two-factor-button two-factor-primary" data-testid="two-factor-enable" @click="ask('enable')">{{ t('twoFactor.enable') }}</button>
    </template>
  </section>
</template>

<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import QRCode from 'qrcode'
import { confirmTwoFactor, disableTwoFactor, regenerateRecoveryCodes, startTwoFactor } from '../services/api'
import type { TwoFactorStatus } from '../services/types'

// Block 4b: TOTP two-step verification in "Your profile".
const props = defineProps<{ status?: TwoFactorStatus | null }>()
const emit = defineEmits<{ (e: 'changed', status: TwoFactorStatus): void }>()
const { t } = useI18n()

const fallback: TwoFactorStatus = { available: false, enabled: false, required: false, recovery_codes_left: 0 }
const state = ref<TwoFactorStatus>({ ...(props.status ?? fallback) })
watch(() => props.status, (value) => { state.value = { ...(value ?? fallback) } })

type Action = 'enable' | 'disable' | 'regenerate'
const pending = ref<Action | null>(null)
const password = ref('')
const code = ref('')
const busy = ref(false)
const error = ref('')
const setup = ref<{ secret: string; otpauth_uri: string } | null>(null)
const qrSrc = ref('')
const recoveryCodes = ref<string[]>([])

const groupedSecret = computed(() => setup.value?.secret.match(/.{1,4}/g)?.join(' ') ?? '')
const codesFileUrl = computed(() => `data:text/plain;charset=utf-8,${encodeURIComponent(recoveryCodes.value.join('\n') + '\n')}`)

function ask(action: Action) {
  pending.value = action
  password.value = ''
  error.value = ''
}

function message(e: unknown): string {
  const data = (e as { response?: { data?: { message?: string; errors?: Record<string, string[]> } } })?.response?.data
  return (data?.errors ? Object.values(data.errors)[0]?.[0] : undefined) || data?.message || t('twoFactor.error')
}

function update(status: TwoFactorStatus) {
  state.value = status
  emit('changed', status)
}

async function runPending() {
  const action = pending.value
  if (!action) return
  busy.value = true
  error.value = ''
  try {
    if (action === 'enable') {
      setup.value = await startTwoFactor(password.value)
      code.value = ''
      const svg = await QRCode.toString(setup.value.otpauth_uri, { type: 'svg', margin: 1, errorCorrectionLevel: 'M' })
      qrSrc.value = `data:image/svg+xml;charset=utf-8,${encodeURIComponent(svg)}`
    } else if (action === 'disable') {
      update(await disableTwoFactor(password.value))
    } else {
      const result = await regenerateRecoveryCodes(password.value)
      recoveryCodes.value = result.recovery_codes
      update(result.two_factor)
    }
    pending.value = null
  } catch (e: unknown) {
    error.value = message(e)
  } finally {
    password.value = ''
    busy.value = false
  }
}

async function confirm() {
  busy.value = true
  error.value = ''
  try {
    const result = await confirmTwoFactor(code.value.trim())
    setup.value = null
    qrSrc.value = ''
    recoveryCodes.value = result.recovery_codes
    update(result.two_factor)
  } catch (e: unknown) {
    error.value = message(e)
    code.value = ''
  } finally {
    busy.value = false
  }
}
</script>

<style scoped>
.two-factor {
  margin-top: var(--space-5);
  padding-top: var(--space-4);
  border-top: 1px solid var(--color-border);
  font-size: 0.8125rem;
  color: var(--color-text);
}

.two-factor h4 {
  margin: 0 0 var(--space-2);
  font-size: 0.875rem;
  font-weight: 600;
}

.two-factor-help,
.two-factor-status {
  margin: 0 0 var(--space-3);
  color: var(--color-text-muted);
}

.two-factor-form {
  display: flex;
  flex-direction: column;
  gap: var(--space-2);
}

.two-factor-label {
  font-weight: 500;
}

.two-factor-input {
  padding: var(--space-2);
  font: inherit;
  color: var(--color-text);
  background: var(--color-bg-surface);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-sm);
}

.two-factor-qr {
  align-self: center;
  margin-bottom: var(--space-2);
  border-radius: var(--radius-sm);
}

.two-factor-secret {
  word-break: break-all;
}

.two-factor-codes {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: var(--space-1) var(--space-4);
  margin: 0 0 var(--space-3);
  padding-left: var(--space-5);
}

.two-factor-actions {
  display: flex;
  flex-wrap: wrap;
  gap: var(--space-2);
}

.two-factor-button {
  padding: var(--space-1) var(--space-3);
  font: inherit;
  color: var(--color-text);
  text-decoration: none;
  background: transparent;
  border: 1px solid var(--color-border);
  border-radius: var(--radius-sm);
  cursor: pointer;
}

.two-factor-button:disabled {
  opacity: 0.6;
  cursor: progress;
}

.two-factor-primary {
  color: var(--color-action-primary-content);
  background: var(--color-action-primary);
  border-color: var(--color-action-primary);
}

.two-factor-danger {
  color: var(--color-status-danger);
  border-color: var(--color-status-danger);
}

.two-factor-error {
  margin: 0 0 var(--space-3);
  color: var(--color-status-danger);
}
</style>
