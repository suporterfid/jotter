<template>
  <section class="my-data" data-testid="my-data">
    <h4>{{ t('myData.heading') }}</h4>
    <p class="my-data-help">{{ t('myData.help') }}</p>
    <a :href="USER_DATA_EXPORT_URL" class="my-data-link" download data-testid="my-data-download">{{ t('myData.download') }}</a>

    <p v-if="error" class="my-data-error" role="alert" data-testid="my-data-error">{{ error }}</p>

    <template v-if="requestedAt">
      <p class="my-data-status" role="status" data-testid="my-data-requested">
        {{ t('myData.requested', { date: formatted }) }}
      </p>
      <button type="button" class="my-data-button" :disabled="busy" data-testid="my-data-cancel" @click="cancel">
        {{ t('myData.cancel') }}
      </button>
    </template>
    <template v-else-if="confirming">
      <p class="my-data-status">{{ t('myData.confirm') }}</p>
      <div class="my-data-actions">
        <button type="button" class="my-data-button" :disabled="busy" @click="confirming = false">{{ t('myData.keep') }}</button>
        <button type="button" class="my-data-button my-data-danger" :disabled="busy" data-testid="my-data-confirm" @click="request">
          {{ t('myData.confirmButton') }}
        </button>
      </div>
    </template>
    <button v-else type="button" class="my-data-button my-data-danger" data-testid="my-data-request" @click="confirming = true">
      {{ t('myData.request') }}
    </button>
  </section>
</template>

<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { USER_DATA_EXPORT_URL, cancelAccountDeletion, requestAccountDeletion } from '../services/api'

// LGPD art. 18: download one's data and ask for the account to be deleted.
// An administrator carries out the deletion; the request can be withdrawn.
const props = defineProps<{ deletionRequestedAt?: string | null }>()
const emit = defineEmits<{ (e: 'deletion-changed', requestedAt: string | null): void }>()
const { t, locale } = useI18n()

const requestedAt = ref<string | null>(props.deletionRequestedAt ?? null)
watch(() => props.deletionRequestedAt, (value) => { requestedAt.value = value ?? null })

const confirming = ref(false)
const busy = ref(false)
const error = ref('')

const formatted = computed(() => requestedAt.value ? new Date(requestedAt.value).toLocaleDateString(locale.value) : '')

async function run(action: () => Promise<string | null>) {
  busy.value = true
  error.value = ''
  try {
    requestedAt.value = await action()
    confirming.value = false
    emit('deletion-changed', requestedAt.value)
  } catch (e: unknown) {
    error.value = (e as { response?: { data?: { message?: string } } })?.response?.data?.message || t('myData.error')
  } finally {
    busy.value = false
  }
}

const request = () => run(requestAccountDeletion)
const cancel = () => run(cancelAccountDeletion)
</script>

<style scoped>
.my-data {
  margin-top: var(--space-5);
  padding-top: var(--space-4);
  border-top: 1px solid var(--color-border);
  font-size: 0.8125rem;
  color: var(--color-text);
}

.my-data h4 {
  margin: 0 0 var(--space-2);
  font-size: 0.875rem;
  font-weight: 600;
}

.my-data-help,
.my-data-status {
  margin: 0 0 var(--space-3);
  color: var(--color-text-muted);
}

.my-data-link {
  display: inline-block;
  margin-bottom: var(--space-3);
  color: var(--color-text-link);
}

.my-data-actions {
  display: flex;
  gap: var(--space-2);
}

.my-data-button {
  padding: var(--space-1) var(--space-3);
  font: inherit;
  color: var(--color-text);
  background: transparent;
  border: 1px solid var(--color-border);
  border-radius: var(--radius-sm);
  cursor: pointer;
}

.my-data-button:disabled {
  opacity: 0.6;
  cursor: progress;
}

.my-data-danger {
  color: var(--color-status-danger);
  border-color: var(--color-status-danger);
}

.my-data-error {
  margin: 0 0 var(--space-3);
  color: var(--color-status-danger);
}
</style>
