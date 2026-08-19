<template>
  <section class="note-share-panel" :aria-label="t('noteShare.title')" data-testid="note-share-panel">
    <div class="note-share-heading">
      <h3>{{ t('noteShare.title') }}</h3>
      <span v-if="state.active" class="note-share-status">{{ t('noteShare.active') }}</span>
      <span v-else class="note-share-status">{{ t('noteShare.notShared') }}</span>
    </div>

    <template v-if="state.active">
      <a v-if="state.url" :href="state.url" class="note-share-url" data-testid="share-link">{{ state.url }}</a>
      <p v-else class="note-share-help">{{ t('noteShare.activeWithoutUrl') }}</p>
      <p v-if="state.expires_at" class="note-share-help">{{ t('noteShare.expires', { date: state.expires_at }) }}</p>
      <div class="note-share-actions">
        <button v-if="state.url" type="button" class="btn-attach" data-testid="copy-share-link" @click="copyLink">
          {{ copied ? t('noteShare.copied') : t('noteShare.copy') }}
        </button>
        <button type="button" class="btn-attach" data-testid="revoke-share-link" @click="revoke">
          {{ t('noteShare.revoke') }}
        </button>
      </div>
    </template>

    <template v-else>
      <label class="note-share-field" for="share-expiry">
        <span>{{ t('noteShare.expiry') }}</span>
        <input id="share-expiry" v-model="expiry" type="datetime-local" data-testid="share-expiry" />
      </label>
      <button type="button" class="btn-attach" data-testid="create-share-link" :disabled="loading" @click="create">
        {{ loading ? t('noteShare.creating') : t('noteShare.create') }}
      </button>
    </template>

    <p v-if="error" class="note-share-error" role="alert">{{ error }}</p>
  </section>
</template>

<script setup lang="ts">
import { ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import type { NoteShareState } from '../services/types'

const props = withDefaults(defineProps<{
  state: NoteShareState
  loading?: boolean
}>(), {
  loading: false,
})

const emit = defineEmits<{
  (event: 'create', expiresAt: string | null): void
  (event: 'revoke'): void
}>()

const { t } = useI18n()
const expiry = ref('')
const copied = ref(false)
const error = ref('')

watch(() => props.state, () => {
  copied.value = false
  error.value = ''
  if (! props.state.active) expiry.value = ''
}, { deep: true })

async function copyLink(): Promise<void> {
  if (! props.state.url) return
  try {
    await navigator.clipboard.writeText(props.state.url)
    copied.value = true
  } catch {
    error.value = t('noteShare.copyError')
  }
}

function create(): void {
  emit('create', expiry.value || null)
}

function revoke(): void {
  if (window.confirm(t('noteShare.revokeConfirm'))) emit('revoke')
}
</script>

<style scoped>
.note-share-panel {
  display: flex;
  flex-direction: column;
  gap: var(--space-2);
  padding: var(--space-3) var(--space-4);
  border-top: 1px solid var(--color-border);
  color: var(--color-text);
}

.note-share-heading,
.note-share-actions {
  display: flex;
  align-items: center;
  gap: var(--space-2);
}

.note-share-heading {
  justify-content: space-between;
}

.note-share-heading h3 {
  margin: 0;
  font-size: 0.875rem;
}

.note-share-status,
.note-share-help,
.note-share-error {
  font-size: 0.75rem;
}

.note-share-status,
.note-share-help {
  color: var(--color-text-muted);
}

.note-share-url {
  overflow-wrap: anywhere;
  color: var(--color-action);
  font-size: 0.75rem;
}

.note-share-field {
  display: flex;
  flex-direction: column;
  gap: var(--space-1);
  font-size: 0.75rem;
}

.note-share-field input {
  min-height: 32px;
  padding: var(--space-1) var(--space-2);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-sm);
  background: var(--color-canvas);
  color: var(--color-text);
}

.note-share-error {
  color: var(--color-status-danger);
}
</style>
