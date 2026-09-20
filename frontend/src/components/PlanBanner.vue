<template>
  <div
    v-if="message"
    class="plan-banner"
    :class="{ 'plan-banner--read-only': readOnly }"
    role="status"
    data-testid="plan-banner"
  >
    <span>{{ message }}</span>
    <a
      v-if="supportUrl"
      :href="supportUrl"
      target="_blank"
      rel="noopener"
      class="plan-banner-link"
      data-testid="plan-banner-support"
    >
      {{ supportLabel }}
    </a>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { brand } from '../services/brand'
import type { TenantPlan } from '../services/types'

const { t } = useI18n()

const props = defineProps<{ plan?: TenantPlan | null; planLimit?: boolean }>()

const readOnly = computed(() => !!props.plan && props.plan.status !== 'self_hosted' && props.plan.read_only)

const message = computed<string | null>(() => {
  if (props.planLimit) return t('plan.planLimit')
  const plan = props.plan
  if (!plan || plan.status === 'self_hosted') return null
  if (plan.read_only) return t('plan.readOnly')
  if (plan.status === 'trial' && plan.trial_days_left !== null) {
    return plan.trial_days_left <= 1 ? t('plan.trialEndsToday') : t('plan.trialEndsIn', { days: plan.trial_days_left })
  }
  return null
})

const showSupport = computed(() => readOnly.value || !!props.planLimit)
const supportUrl = computed(() => (showSupport.value && brand.support_url) ? brand.support_url : null)

const supportLabel = computed(() => (props.planLimit ? t('plan.contactSupport') : t('brand.support')))
</script>

<style scoped>
.plan-banner {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: var(--space-3);
  padding: var(--space-1) var(--space-4);
  font-size: 0.8125rem;
  color: var(--color-warning-fg);
  background: var(--color-warning-bg);
  border-bottom: 1px solid var(--color-warning-border);
}

.plan-banner--read-only {
  color: var(--color-text);
  background: var(--color-bg-surface);
  border-bottom-color: var(--color-status-danger);
}

.plan-banner-link {
  color: inherit;
  text-decoration: underline;
}
</style>
