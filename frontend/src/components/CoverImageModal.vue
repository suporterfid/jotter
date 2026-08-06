<template>
  <div class="modal-overlay" @click.self="$emit('close')">
    <div class="modal-card">
      <h3>{{ t('coverImageModal.heading') }}</h3>
      <div class="cover-tabs">
        <button
          type="button"
          class="cover-tab-btn"
          data-testid="cover-upload-tab-btn"
          :class="{ active: activeTab === 'upload' }"
          @click="activeTab = 'upload'"
        >{{ t('coverImageModal.upload') }}</button>
        <button
          type="button"
          class="cover-tab-btn"
          data-testid="cover-url-tab-btn"
          :class="{ active: activeTab === 'url' }"
          @click="activeTab = 'url'"
        >{{ t('coverImageModal.url') }}</button>
      </div>

      <div v-if="activeTab === 'upload'">
        <p class="modal-desc">{{ t('coverImageModal.uploadDesc') }}</p>
        <input
          type="file"
          accept="image/*"
          class="modal-input"
          data-testid="cover-upload-input"
          @change="handleFileSelected"
        />
      </div>

      <div v-else>
        <p class="modal-desc">{{ t('coverImageModal.urlDesc') }}</p>
        <input
          v-model="urlDraft"
          type="url"
          class="modal-input"
          data-testid="cover-url-input"
          :placeholder="t('coverImageModal.urlPlaceholder')"
        />
        <div class="modal-actions">
          <button type="button" class="btn-secondary" @click="$emit('close')">{{ t('coverImageModal.cancel') }}</button>
          <button
            type="button"
            class="btn-primary"
            data-testid="cover-url-submit-btn"
            :disabled="urlDraft.trim() === ''"
            @click="$emit('set-cover', urlDraft.trim())"
          >{{ t('coverImageModal.setCover') }}</button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { uploadAttachment } from '../services/api'

const { t } = useI18n()

const props = defineProps<{
  workspaceId: number
}>()

const emit = defineEmits<{
  (e: 'set-cover', url: string): void
  (e: 'close'): void
}>()

const activeTab = ref<'upload' | 'url'>('upload')
const urlDraft = ref('')

async function handleFileSelected(event: Event) {
  const input = event.target as HTMLInputElement
  const file = input.files?.[0]
  if (!file) return
  try {
    const attachment = await uploadAttachment(props.workspaceId, file)
    emit('set-cover', attachment.url)
  } catch (err) {
    console.error('Cover image upload failed:', err)
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
  max-width: 90vw;
  box-shadow: var(--shadow-float);
}

.modal-card h3 {
  margin: 0 0 var(--space-4);
  color: var(--color-text);
  font-size: 1.125rem;
  font-weight: 600;
}

.cover-tabs {
  display: flex;
  gap: var(--space-2);
  margin-bottom: var(--space-4);
}

.cover-tab-btn {
  flex: 1;
  background: transparent;
  border: 1px solid var(--color-border);
  border-radius: var(--radius-sm);
  padding: var(--space-2);
  color: var(--color-text-muted);
  cursor: pointer;
  font-size: 0.875rem;
  transition: border-color var(--duration-fast) var(--ease-standard),
              color var(--duration-fast) var(--ease-standard);
}

.cover-tab-btn.active {
  border-color: var(--color-action);
  color: var(--color-action);
}

.modal-desc {
  font-size: 0.875rem;
  color: var(--color-text-muted);
  margin-bottom: var(--space-4);
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
  gap: var(--space-3);
}

.btn-secondary {
  background: transparent;
  border: 1px solid var(--color-border);
  color: var(--color-text-muted);
  padding: var(--space-2) var(--space-4);
  border-radius: var(--radius-sm);
  cursor: pointer;
  min-height: 36px;
  font-size: 0.875rem;
  transition: border-color var(--duration-fast) var(--ease-standard),
              color var(--duration-fast) var(--ease-standard);
}

.btn-secondary:hover {
  border-color: var(--color-border-strong);
  color: var(--color-text);
}

.btn-primary {
  background: var(--color-action);
  color: var(--color-neutral-0);
  border: none;
  padding: var(--space-2) var(--space-4);
  border-radius: var(--radius-sm);
  cursor: pointer;
  font-weight: 500;
  font-size: 0.875rem;
  min-height: 36px;
  transition: background-color var(--duration-fast) var(--ease-standard);
}

.btn-primary:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}
</style>
