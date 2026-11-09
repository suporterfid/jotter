<template>
  <div v-if="required" class="terms-banner" role="status" data-testid="terms-banner">
    <span>
      {{ `${t('terms.updated')} ` }}
      <a v-if="brand.terms_url" :href="brand.terms_url" target="_blank" rel="noopener" class="terms-banner-link">{{ t('login.termsLink') }}</a>
      <template v-if="brand.terms_url && brand.privacy_url">{{ ` ${t('login.and')} ` }}</template>
      <a v-if="brand.privacy_url" :href="brand.privacy_url" target="_blank" rel="noopener" class="terms-banner-link">{{ t('login.privacyLink') }}</a>
    </span>
    <button
      type="button"
      class="terms-banner-button"
      :disabled="saving"
      data-testid="terms-accept"
      @click="accept"
    >
      {{ t('terms.accept') }}
    </button>
    <span v-if="error" class="terms-banner-error" role="alert">{{ t('terms.error') }}</span>
  </div>
</template>

<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { acceptTerms } from '../services/api'
import { brand } from '../services/brand'
import type { TermsStatus } from '../services/types'

const { t } = useI18n()

const props = defineProps<{ terms?: TermsStatus | null }>()
const emit = defineEmits<{ accepted: [terms: TermsStatus] }>()

const saving = ref(false)
const error = ref(false)

const required = computed(() => !!props.terms?.acceptance_required)

async function accept() {
  saving.value = true
  error.value = false
  try {
    emit('accepted', await acceptTerms())
  } catch {
    error.value = true
  } finally {
    saving.value = false
  }
}
</script>

<style scoped>
.terms-banner {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: center;
  gap: var(--space-3);
  padding: var(--space-1) var(--space-4);
  font-size: 0.8125rem;
  color: var(--color-warning-fg);
  background: var(--color-warning-bg);
  border-bottom: 1px solid var(--color-warning-border);
}

.terms-banner-link {
  color: inherit;
  text-decoration: underline;
}

.terms-banner-button {
  padding: var(--space-1) var(--space-3);
  font: inherit;
  color: inherit;
  background: transparent;
  border: 1px solid currentColor;
  border-radius: var(--radius-sm);
  cursor: pointer;
}

.terms-banner-button:disabled {
  opacity: 0.6;
  cursor: progress;
}

.terms-banner-error {
  color: var(--color-status-danger);
}
</style>
