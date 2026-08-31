<template>
  <div v-if="isOpen" class="modal-overlay" @click.self="emit('close')">
    <section class="modal-card notification-preferences-card" role="dialog" aria-modal="true" data-testid="notification-preferences-modal">
      <div class="modal-header">
        <h2>{{ t('notificationPreferences.heading') }}</h2>
        <button type="button" class="btn-icon" data-testid="notification-preferences-close" :aria-label="t('notificationPreferences.close')" @click="emit('close')">×</button>
      </div>
      <p class="modal-desc">{{ t('notificationPreferences.description') }}</p>
      <p v-if="errorMessage" class="form-error">{{ errorMessage }}</p>
      <div v-if="loading" class="notification-preferences-loading">{{ t('notificationPreferences.loading') }}</div>
      <div v-else class="notification-preferences-list">
        <div v-for="preference in preferences" :key="preference.type" class="notification-preference-row" data-testid="notification-preference-row">
          <div>
            <strong>{{ t(`notificationPreferences.types.${preference.type}`) }}</strong>
            <small v-if="preference.explicit">{{ t('notificationPreferences.customized') }}</small>
          </div>
          <select
            :data-testid="`notification-preference-${preference.type}`"
            :value="preference.mode"
            :disabled="savingType === preference.type"
            @change="handleModeChange(preference.type, ($event.target as HTMLSelectElement).value as NotificationEmailMode)"
          >
            <option value="immediate">{{ t('notificationPreferences.modes.immediate') }}</option>
            <option value="digest">{{ t('notificationPreferences.modes.digest') }}</option>
            <option value="off">{{ t('notificationPreferences.modes.off') }}</option>
          </select>
        </div>
      </div>
      <div class="modal-actions">
        <button type="button" class="btn-secondary" @click="emit('close')">{{ t('notificationPreferences.close') }}</button>
      </div>
    </section>
  </div>
</template>

<script setup lang="ts">
import { ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { getNotificationPreferences, updateNotificationPreference } from '../services/api'
import type { NotificationEmailMode, NotificationPreference, NotificationType } from '../services/types'

const props = withDefaults(defineProps<{
  isOpen: boolean
  unsubscribeType?: NotificationType | null
}>(), {
  unsubscribeType: null,
})

const emit = defineEmits<{ (event: 'close'): void }>()
const { t } = useI18n()
const preferences = ref<NotificationPreference[]>([])
const loading = ref(false)
const savingType = ref<NotificationType | null>(null)
const errorMessage = ref<string | null>(null)
const unsubscribeHandled = ref(false)

watch(() => props.isOpen, (open) => {
  if (open) {
    unsubscribeHandled.value = false
    void loadPreferences()
  }
}, { immediate: true })

async function loadPreferences(): Promise<void> {
  loading.value = true
  errorMessage.value = null
  try {
    preferences.value = await getNotificationPreferences()
    await handleUnsubscribeRequest()
  } catch {
    errorMessage.value = t('notificationPreferences.loadError')
  } finally {
    loading.value = false
  }
}

async function handleModeChange(type: NotificationType, mode: NotificationEmailMode): Promise<void> {
  savingType.value = type
  errorMessage.value = null
  try {
    const updated = await updateNotificationPreference(type, mode)
    const index = preferences.value.findIndex((preference) => preference.type === type)
    if (index !== -1) preferences.value[index] = updated
  } catch {
    errorMessage.value = t('notificationPreferences.saveError')
  } finally {
    savingType.value = null
  }
}

async function handleUnsubscribeRequest(): Promise<void> {
  if (!props.unsubscribeType || unsubscribeHandled.value) return
  unsubscribeHandled.value = true
  const preference = preferences.value.find((item) => item.type === props.unsubscribeType)
  if (!preference || !window.confirm(t('notificationPreferences.confirmUnsubscribe', { type: t(`notificationPreferences.types.${props.unsubscribeType}`) }))) return
  await handleModeChange(props.unsubscribeType, 'off')
}
</script>

<style scoped>
.notification-preferences-card {
  width: min(520px, 92vw);
}

.notification-preferences-list {
  display: grid;
  gap: 0.65rem;
  margin: 1rem 0;
}

.notification-preference-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 1rem;
  padding: 0.75rem;
  border: 1px solid var(--color-border);
  border-radius: 0.6rem;
}

.notification-preference-row small {
  display: block;
  opacity: 0.65;
  margin-top: 0.2rem;
}
</style>
