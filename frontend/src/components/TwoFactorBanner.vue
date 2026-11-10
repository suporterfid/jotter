<template>
  <div v-if="visible" class="two-factor-banner" role="status" data-testid="two-factor-banner">
    <span>{{ t('twoFactor.bannerRequired') }}</span>
    <button type="button" class="two-factor-banner-button" data-testid="two-factor-banner-open" @click="$emit('open-profile')">
      {{ t('twoFactor.bannerAction') }}
    </button>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import type { TwoFactorStatus } from '../services/types'

// JOTTER_2FA_REQUIRED_FOR_ADMINS: an administrator without two-step
// verification is sent to "Your profile" before Administration works.
const props = defineProps<{ status?: TwoFactorStatus | null }>()
defineEmits<{ (e: 'open-profile'): void }>()
const { t } = useI18n()

const visible = computed(() => !!props.status?.required && !props.status.enabled)
</script>

<style scoped>
.two-factor-banner {
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

.two-factor-banner-button {
  padding: var(--space-1) var(--space-3);
  font: inherit;
  color: inherit;
  background: transparent;
  border: 1px solid currentColor;
  border-radius: var(--radius-sm);
  cursor: pointer;
}
</style>
