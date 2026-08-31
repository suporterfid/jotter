<template>
  <section class="note-review-panel" data-testid="note-review-panel">
    <div class="note-review-header">
      <h3>{{ t('noteReview.title') }}</h3>
    </div>

    <p v-if="loading" class="note-review-muted">{{ t('noteReview.loading') }}</p>
    <p v-else-if="error" class="note-review-error" role="alert">{{ t('noteReview.error') }}</p>
    <template v-else-if="review">
      <div class="note-review-state" data-testid="review-state">
        {{ stateLabel(review.state) }}
      </div>
      <p v-if="review.stale" class="note-review-stale" data-testid="review-stale">
        {{ t('noteReview.stale') }}
      </p>

      <div class="note-review-reviewer">
        <strong>{{ t('noteReview.reviewer') }}:</strong>
        <span v-if="review.reviewer">{{ review.reviewer.name }} ({{ review.reviewer.email }})</span>
        <span v-else class="note-review-muted">—</span>
      </div>

      <div v-if="review.can_assign" class="note-review-assignment">
        <input
          v-model="reviewerIdDraft"
          type="number"
          min="1"
          :placeholder="t('noteReview.reviewerIdPlaceholder')"
          data-testid="reviewer-id"
        />
        <button type="button" class="btn-review-secondary" :disabled="busy" data-testid="review-assign" @click="assignReviewer">
          {{ t('noteReview.assign') }}
        </button>
      </div>

      <div class="note-review-actions">
        <button v-if="review.can_submit" type="button" class="btn-review-primary" :disabled="busy" data-testid="review-submit" @click="submit">
          {{ t('noteReview.submit') }}
        </button>
        <button v-if="review.can_approve" type="button" class="btn-review-primary" :disabled="busy" data-testid="review-approve" @click="approve">
          {{ t('noteReview.approve') }}
        </button>
      </div>

      <div v-if="review.can_request_changes" class="note-review-request">
        <textarea v-model="reason" :placeholder="t('noteReview.reasonPlaceholder')" data-testid="review-reason" />
        <button type="button" class="btn-review-secondary" :disabled="busy || reason.trim() === ''" data-testid="review-request-changes" @click="requestChanges">
          {{ t('noteReview.sendRequest') }}
        </button>
      </div>
    </template>
  </section>
</template>

<script setup lang="ts">
import { ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import type { NoteReviewState, NoteReviewSummary } from '../services/types'
import {
  getNoteReview,
  assignNoteReviewer,
  submitNoteReview,
  approveNoteReview,
  requestNoteChanges as requestNoteChangesApi,
} from '../services/api'

const { t } = useI18n()

const props = defineProps<{
  workspaceId: number
  noteId: number
  review?: NoteReviewSummary | null
}>()

const emit = defineEmits<{
  (event: 'updated', review: NoteReviewSummary): void
}>()

const review = ref<NoteReviewSummary | null>(props.review ?? null)
const reviewerIdDraft = ref('')
const reason = ref('')
const loading = ref(false)
const busy = ref(false)
const error = ref(false)

const stateLabels: Record<NoteReviewState, string> = {
  draft: 'noteReview.stateDraft',
  in_review: 'noteReview.stateInReview',
  changes_requested: 'noteReview.stateChangesRequested',
  approved: 'noteReview.stateApproved',
}

function stateLabel(state: NoteReviewState): string {
  return t(stateLabels[state])
}

async function load(): Promise<void> {
  loading.value = true
  error.value = false
  try {
    review.value = await getNoteReview(props.workspaceId, props.noteId)
    reviewerIdDraft.value = review.value.reviewer ? String(review.value.reviewer.id) : ''
  } catch {
    error.value = true
  } finally {
    loading.value = false
  }
}

async function update(action: () => Promise<NoteReviewSummary>): Promise<void> {
  busy.value = true
  error.value = false
  try {
    review.value = await action()
    emit('updated', review.value)
    reason.value = ''
  } catch {
    error.value = true
  } finally {
    busy.value = false
  }
}

async function assignReviewer(): Promise<void> {
  const parsed = reviewerIdDraft.value.trim() === '' ? null : Number(reviewerIdDraft.value)
  if (parsed !== null && (!Number.isInteger(parsed) || parsed < 1)) return
  await update(() => assignNoteReviewer(props.workspaceId, props.noteId, parsed))
}

async function submit(): Promise<void> {
  await update(() => submitNoteReview(props.workspaceId, props.noteId))
}

async function approve(): Promise<void> {
  await update(() => approveNoteReview(props.workspaceId, props.noteId))
}

async function requestChanges(): Promise<void> {
  const value = reason.value.trim()
  if (!value) return
  await update(() => requestNoteChangesApi(props.workspaceId, props.noteId, value))
}

watch(() => [props.workspaceId, props.noteId], () => void load(), { immediate: true })
watch(() => props.review, (value) => {
  if (value) review.value = value
})
</script>

<style scoped>
.note-review-panel {
  display: flex;
  flex-direction: column;
  gap: var(--space-3);
  padding: var(--space-3);
  color: var(--color-text);
}

.note-review-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
}

.note-review-header h3 {
  margin: 0;
  font-size: 1rem;
}

.note-review-state {
  width: fit-content;
  padding: var(--space-1) var(--space-2);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-pill);
  font-size: 0.8125rem;
  font-weight: 600;
}

.note-review-reviewer,
.note-review-assignment,
.note-review-actions,
.note-review-request {
  display: flex;
  gap: var(--space-2);
  align-items: center;
}

.note-review-assignment,
.note-review-request {
  align-items: stretch;
}

.note-review-assignment input,
.note-review-request textarea {
  min-width: 0;
  flex: 1;
  padding: var(--space-2);
  background: var(--color-canvas);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-sm);
  color: var(--color-text);
  font: inherit;
}

.note-review-request {
  flex-direction: column;
}

.note-review-request textarea {
  min-height: 5rem;
  resize: vertical;
}

.btn-review-primary,
.btn-review-secondary {
  padding: var(--space-2) var(--space-3);
  border-radius: var(--radius-sm);
  cursor: pointer;
  font: inherit;
}

.btn-review-primary {
  border: 1px solid var(--color-action);
  background: var(--color-action);
  color: var(--color-neutral-0);
}

.btn-review-secondary {
  border: 1px solid var(--color-border);
  background: transparent;
  color: var(--color-text);
}

.btn-review-primary:disabled,
.btn-review-secondary:disabled {
  cursor: not-allowed;
  opacity: 0.6;
}

.note-review-stale,
.note-review-error {
  margin: 0;
  color: var(--color-status-danger);
  font-size: 0.8125rem;
}

.note-review-muted {
  margin: 0;
  color: var(--color-text-muted);
  font-size: 0.8125rem;
}
</style>
