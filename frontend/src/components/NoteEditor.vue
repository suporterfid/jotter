<template>
  <div class="editor-container">
    <!-- Top Action Bar -->
    <header class="editor-bar">
      <div class="note-meta-info">
        <div class="editor-title-row">
          <button
            v-if="!isEditingIcon"
            type="button"
            class="editor-icon-btn"
            :aria-label="noteIcon ? 'Change page icon' : 'Set page icon'"
            data-testid="editor-icon-btn"
            @click="startEditingIcon"
          >
            <span v-if="noteIcon" data-testid="editor-icon-emoji">{{ noteIcon }}</span>
            <svg v-else data-testid="editor-icon-fallback" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.5">
              <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
              <polyline points="14 2 14 8 20 8"></polyline>
            </svg>
            <span v-if="noteIcon" class="editor-icon-clear" data-testid="editor-icon-clear" @click.stop="clearIcon">&times;</span>
          </button>
          <input
            v-else
            v-model="iconDraft"
            type="text"
            class="editor-icon-input"
            data-testid="editor-icon-input"
            autofocus
            @keydown.enter="confirmEditingIcon"
            @keydown.escape="cancelEditingIcon"
            @blur="confirmEditingIcon"
          />
          <h2 class="editor-title" data-testid="editor-title">{{ note.title || note.path }}</h2>
        </div>
        <span class="editor-path" data-testid="editor-path">
          <template v-for="folder in breadcrumbSegments.folders" :key="folder.path">
            <button
              type="button"
              class="editor-path-segment"
              data-testid="editor-path-segment"
              @click="emit('reveal-folder', folder.path)"
            >{{ folder.name }}</button>
            <span class="editor-path-separator">/</span>
          </template>
          <span data-testid="editor-path-filename">{{ breadcrumbSegments.fileName }}</span>
        </span>
      </div>

      <div class="editor-controls">
        <!-- View Mode Switcher -->
        <div class="view-mode-toggle">
          <button 
            class="toggle-btn" 
            :class="{ active: viewMode === 'edit' }"
            @click="viewMode = 'edit'"
            title="Editor View"
          >
            Edit
          </button>
          <button 
            class="toggle-btn" 
            :class="{ active: viewMode === 'split' }"
            @click="viewMode = 'split'"
            title="Split View"
          >
            Split
          </button>
          <button 
            class="toggle-btn" 
            :class="{ active: viewMode === 'preview' }"
            @click="viewMode = 'preview'"
            title="Preview View"
          >
            Preview
          </button>
        </div>

        <button
          class="btn-attach"
          data-testid="history-btn"
          title="Version History"
          @click="openHistory"
        >
          <span>🕘</span>
          <span>History</span>
        </button>

        <button
          class="btn-attach"
          data-testid="attach-file-btn"
          :disabled="isUploading"
          @click="triggerFileInput"
          title="Attach File to Note"
        >
          <span>📎</span>
          <span v-if="isUploading">Uploading...</span>
          <span v-else>Attach</span>
        </button>

        <input 
          ref="fileInputRef" 
          type="file" 
          style="display: none" 
          data-testid="file-upload-input"
          @change="handleFileSelected" 
        />

        <button 
          class="btn-save"
          data-testid="save-note-btn"
          :disabled="!isDirty && !isSaving"
          @click="handleSave"
        >
          <span v-if="isSaving">Saving...</span>
          <span v-else>{{ isDirty ? 'Save Changes' : 'Saved' }}</span>
        </button>
      </div>
    </header>

    <!-- Main Editor Content Area -->
    <div 
      class="editor-body" 
      :class="`view-${viewMode}`"
      @dragover.prevent="handleDragOver"
      @dragenter.prevent="handleDragOver"
    >
      <!-- Editor Area with Autocomplete -->
      <div v-show="viewMode !== 'preview'" class="textarea-wrapper">
        <textarea
          ref="textareaRef"
          v-model="editableContent"
          data-testid="markdown-textarea"
          class="markdown-textarea"
          placeholder="Write Markdown here... Type [[ to reference another note."
          @input="handleInput"
          @keydown="handleKeyDown"
        ></textarea>

        <!-- Wikilink Autocomplete Dropdown -->
        <div 
          v-if="showAutocomplete && autocompleteSuggestions.length > 0" 
          class="autocomplete-dropdown"
          :style="autocompleteStyle"
        >
          <div class="autocomplete-header">Link to Note</div>
          <div 
            v-for="(suggestion, idx) in autocompleteSuggestions" 
            :key="suggestion.id"
            class="autocomplete-item"
            :class="{ active: idx === selectedSuggestionIndex }"
            @mousedown.prevent="selectSuggestion(suggestion)"
          >
            <span class="suggestion-title">{{ suggestion.title || suggestion.path }}</span>
            <span class="suggestion-path">{{ suggestion.path }}</span>
          </div>
        </div>
      </div>

      <!-- Preview Area -->
      <div v-show="viewMode !== 'edit'" class="preview-wrapper">
        <MarkdownPreview 
          :content="editableContent" 
          @navigate-wikilink="$emit('navigate-wikilink', $event)"
        />
      </div>

      <!-- Drag & Drop Upload Overlay -->
      <div 
        v-if="isDraggingOver" 
        class="drag-upload-overlay"
        @dragleave="handleDragLeave"
        @drop.prevent="handleDrop"
      >
        <div class="drag-upload-box">
          <svg viewBox="0 0 24 24" width="48" height="48" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
            <polyline points="17 8 12 3 7 8"></polyline>
            <line x1="12" y1="3" x2="12" y2="15"></line>
          </svg>
          <span>Drop file here to attach to note</span>
        </div>
      </div>
    </div>

    <!-- Live Status Bar -->
    <footer class="editor-status-bar">
      <div class="status-left">
        <span class="status-pill" :class="{ dirty: isDirty, saving: isSaving }">
          <span class="dot"></span>
          {{ isSaving ? 'Saving...' : isDirty ? 'Unsaved changes' : 'Saved to vault' }}
        </span>
      </div>
      <div class="status-right">
        <span class="stat-item"><strong>{{ wordCount }}</strong> words</span>
        <span class="stat-item"><strong>{{ charCount }}</strong> chars</span>
        <span class="stat-item"><strong>{{ readingTimeMin }}</strong> min read</span>
      </div>
    </footer>

    <!-- Properties Panel -->
    <PropertiesPanel
      :properties="note.properties || []"
      @add-property="handleAddProperty"
      @delete-property="handleDeleteProperty"
    />

    <!-- Comments Panel -->
    <CommentsPanel
      :comments="comments"
      :error-message="commentsError"
      @add-comment="handleAddComment"
      @delete-comment="handleDeleteComment"
    />

    <!-- Backlinks Panel -->
    <BacklinksPanel
      :backlinks="note.backlinks || []"
      @select-note="$emit('select-note', $event)"
    />

    <!-- Outgoing Links Panel -->
    <OutgoingLinksPanel
      :links="outgoingLinks"
      @select-note="$emit('select-note', $event)"
    />

    <!-- Unlinked Mentions Panel -->
    <UnlinkedMentionsPanel
      :mentions="unlinkedMentions"
      @select-note="$emit('select-note', $event)"
      @convert-to-link="handleConvertToLink"
    />

    <!-- Version History Panel -->
    <HistoryPanel
      v-if="showHistory"
      :revisions="revisions"
      :loading="revisionsLoading"
      :selected-revision-id="selectedRevisionId"
      :preview-content="revisionPreviewContent"
      :preview-loading="revisionPreviewLoading"
      @close="showHistory = false"
      @select-revision="handleSelectRevision"
      @restore-revision="handleRestoreRevision"
    />
  </div>
</template>

<script setup lang="ts">
import { ref, watch, computed, nextTick } from 'vue'
import type { NoteDetail, NoteMeta, NoteRevisionMeta, NoteComment, UnlinkedMention, OutgoingLink } from '../services/types'
import {
  uploadAttachment,
  getNoteRevisions, getNoteRevision, restoreNoteRevision,
  setNoteProperty, deleteNoteProperty,
  getNoteComments, addNoteComment, deleteNoteComment,
  getUnlinkedMentions, getNote, updateNote,
  getOutgoingLinks
} from '../services/api'
import MarkdownPreview from './MarkdownPreview.vue'
import BacklinksPanel from './BacklinksPanel.vue'
import OutgoingLinksPanel from './OutgoingLinksPanel.vue'
import UnlinkedMentionsPanel from './UnlinkedMentionsPanel.vue'
import HistoryPanel from './HistoryPanel.vue'
import PropertiesPanel from './PropertiesPanel.vue'
import CommentsPanel from './CommentsPanel.vue'

const props = defineProps<{
  note: NoteDetail
  allNotes: NoteMeta[]
  workspaceId?: number
}>()

const emit = defineEmits<{
  (e: 'update-note', noteId: number, content: string): void
  (e: 'select-note', noteId: number): void
  (e: 'navigate-wikilink', target: string): void
  (e: 'reveal-folder', folderPath: string): void
}>()

const isEditingIcon = ref(false)
const iconDraft = ref('')

const noteIcon = computed(() => {
  const icon = props.note.frontmatter?.icon
  return typeof icon === 'string' && icon.trim() !== '' ? icon : null
})

const breadcrumbSegments = computed(() => {
  const parts = props.note.path.split('/')
  const fileName = parts[parts.length - 1]
  const folders = parts.slice(0, -1)
  return {
    folders: folders.map((name, index) => ({
      name,
      path: folders.slice(0, index + 1).join('/'),
    })),
    fileName,
  }
})

function startEditingIcon() {
  iconDraft.value = noteIcon.value ?? ''
  isEditingIcon.value = true
}

function cancelEditingIcon() {
  isEditingIcon.value = false
  iconDraft.value = ''
}

async function confirmEditingIcon() {
  // Unmounting the icon input (which happens at the end of this very
  // function, via isEditingIcon.value = false) fires a native blur event
  // in a real browser, re-invoking this handler through @blur. Guard
  // against that re-entrant call — jsdom-based unit tests never trigger
  // it, so this only surfaces in a real browser (found via manual/e2e
  // verification, not the component test suite).
  if (!isEditingIcon.value) return

  const trimmed = iconDraft.value.trim()
  if (!props.workspaceId) {
    cancelEditingIcon()
    return
  }
  try {
    if (trimmed === '') {
      await deleteNoteProperty(props.workspaceId, props.note.id, 'icon')
    } else {
      await setNoteProperty(props.workspaceId, props.note.id, 'icon', trimmed)
    }
    emit('select-note', props.note.id)
  } catch (err) {
    console.error('Failed to update page icon:', err)
  }
  isEditingIcon.value = false
  iconDraft.value = ''
}

async function clearIcon() {
  if (!props.workspaceId) return
  try {
    await deleteNoteProperty(props.workspaceId, props.note.id, 'icon')
    emit('select-note', props.note.id)
  } catch (err) {
    console.error('Failed to clear page icon:', err)
  }
}

const viewMode = ref<'edit' | 'split' | 'preview'>('split')
const editableContent = ref(props.note.content)
const isDirty = ref(false)
const isSaving = ref(false)
const isUploading = ref(false)
const isDraggingOver = ref(false)
const textareaRef = ref<HTMLTextAreaElement | null>(null)
const fileInputRef = ref<HTMLInputElement | null>(null)

const showHistory = ref(false)
const revisions = ref<NoteRevisionMeta[]>([])
const revisionsLoading = ref(false)
const selectedRevisionId = ref<number | null>(null)
const revisionPreviewContent = ref<string | null>(null)
const revisionPreviewLoading = ref(false)

const comments = ref<NoteComment[]>([])
const commentsError = ref<string | null>(null)
const unlinkedMentions = ref<UnlinkedMention[]>([])
const outgoingLinks = ref<OutgoingLink[]>([])

// Live Statistics
const charCount = computed(() => editableContent.value.length)
const wordCount = computed(() => {
  const text = editableContent.value.trim()
  return text ? text.split(/\s+/).filter(Boolean).length : 0
})
const readingTimeMin = computed(() => Math.ceil(wordCount.value / 200))

function handleDragOver(e: DragEvent) {
  e.preventDefault()
  isDraggingOver.value = true
}

function handleDragLeave(e: DragEvent) {
  e.preventDefault()
  isDraggingOver.value = false
}

async function processFile(file: File) {
  if (!props.workspaceId) return
  isUploading.value = true
  try {
    const attachment = await uploadAttachment(props.workspaceId, file)
    insertAttachmentMarkdown(attachment)
  } catch (err: unknown) {
    console.error('Attachment upload failed:', err)
  } finally {
    isUploading.value = false
  }
}

async function handleDrop(e: DragEvent) {
  e.preventDefault()
  isDraggingOver.value = false
  if (!e.dataTransfer?.files || e.dataTransfer.files.length === 0) return
  const file = e.dataTransfer.files[0]
  if (file) {
    await processFile(file)
  }
}

// Autocomplete state
const showAutocomplete = ref(false)
const autocompleteQuery = ref('')
const autocompleteStartIndex = ref(-1)
const selectedSuggestionIndex = ref(0)
const autocompleteStyle = ref({ top: '40px', left: '20px' })

watch(() => props.note, (newNote) => {
  editableContent.value = newNote.content
  isDirty.value = false
  showAutocomplete.value = false
  showHistory.value = false
  loadComments(newNote.id)
  loadUnlinkedMentions(newNote.id)
  loadOutgoingLinks(newNote.id)
}, { immediate: true })

async function loadComments(noteId: number) {
  commentsError.value = null
  if (!props.workspaceId) return
  try {
    comments.value = await getNoteComments(props.workspaceId, noteId)
  } catch (err) {
    console.error('Failed to load comments:', err)
  }
}

async function loadOutgoingLinks(noteId: number) {
  if (!props.workspaceId) return
  try {
    outgoingLinks.value = await getOutgoingLinks(props.workspaceId, noteId)
  } catch (err) {
    console.error('Failed to load outgoing links:', err)
  }
}

async function loadUnlinkedMentions(noteId: number) {
  if (!props.workspaceId) return
  try {
    unlinkedMentions.value = await getUnlinkedMentions(props.workspaceId, noteId)
  } catch (err) {
    console.error('Failed to load unlinked mentions:', err)
  }
}

async function handleConvertToLink(mention: UnlinkedMention) {
  if (!props.workspaceId) return
  try {
    const mentioningNote = await getNote(props.workspaceId, mention.id)
    const escapedPhrase = mention.matched_phrase.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')
    const pattern = new RegExp(escapedPhrase, 'i')
    const rewritten = mentioningNote.content.replace(pattern, `[[${props.note.title}]]`)

    if (rewritten !== mentioningNote.content) {
      await updateNote(props.workspaceId, mention.id, rewritten)
    }

    await loadUnlinkedMentions(props.note.id)
  } catch (err) {
    console.error('Failed to convert mention to link:', err)
  }
}

async function handleAddComment(content: string) {
  if (!props.workspaceId) return
  commentsError.value = null
  try {
    const comment = await addNoteComment(props.workspaceId, props.note.id, content)
    comments.value.push(comment)
  } catch (err: any) {
    console.error('Failed to add comment:', err)
    commentsError.value = err.response?.data?.message || 'Failed to add comment.'
  }
}

async function handleDeleteComment(commentId: number) {
  if (!props.workspaceId) return
  commentsError.value = null
  try {
    await deleteNoteComment(props.workspaceId, props.note.id, commentId)
    comments.value = comments.value.filter(c => c.id !== commentId)
  } catch (err: any) {
    console.error('Failed to delete comment:', err)
    commentsError.value = err.response?.data?.message || 'You can only delete your own comments.'
  }
}

watch(editableContent, (newVal) => {
  if (newVal !== props.note.content) {
    isDirty.value = true
  }
})

const autocompleteSuggestions = computed(() => {
  if (!autocompleteQuery.value.trim()) return props.allNotes.slice(0, 8)
  const q = autocompleteQuery.value.toLowerCase()
  return props.allNotes
    .filter(n => n.title.toLowerCase().includes(q) || n.path.toLowerCase().includes(q))
    .slice(0, 8)
})

function triggerFileInput() {
  fileInputRef.value?.click()
}

async function handleFileSelected(event: Event) {
  const target = event.target as HTMLInputElement
  const file = target.files?.[0]
  if (file) {
    await processFile(file)
    if (target) target.value = ''
  }
}

function insertAttachmentMarkdown(attachment: { path: string; mime: string; url: string }) {
  const el = textareaRef.value
  const filename = attachment.path.split('/').pop() || 'file'
  const isImage = attachment.mime.startsWith('image/')
  const markdownSnippet = isImage 
    ? `![${filename}](${attachment.url})` 
    : `[${filename}](${attachment.url})`

  if (!el) {
    editableContent.value += `\n${markdownSnippet}\n`
    return
  }

  const start = el.selectionStart
  const end = el.selectionEnd
  const current = editableContent.value

  editableContent.value = current.substring(0, start) + markdownSnippet + current.substring(end)
  nextTick(() => {
    el.focus()
    const newPos = start + markdownSnippet.length
    el.setSelectionRange(newPos, newPos)
  })
}

function handleInput() {
  const el = textareaRef.value
  if (!el) return

  const text = editableContent.value
  const cursorPos = el.selectionStart

  // Look backwards for [[
  const textBeforeCursor = text.substring(0, cursorPos)
  const lastDoubleBracket = textBeforeCursor.lastIndexOf('[[')

  if (lastDoubleBracket !== -1) {
    const textAfterBracket = textBeforeCursor.substring(lastDoubleBracket + 2)
    // Check if there is a closing bracket or newline in between
    if (!textAfterBracket.includes(']]') && !textAfterBracket.includes('\n')) {
      showAutocomplete.value = true
      autocompleteQuery.value = textAfterBracket
      autocompleteStartIndex.value = lastDoubleBracket
      selectedSuggestionIndex.value = 0
      return
    }
  }

  showAutocomplete.value = false
}

function handleKeyDown(event: KeyboardEvent) {
  if (!showAutocomplete.value || autocompleteSuggestions.value.length === 0) {
    // Ctrl+S / Cmd+S save shortcut
    if ((event.ctrlKey || event.metaKey) && event.key === 's') {
      event.preventDefault()
      handleSave()
    }
    return
  }

  if (event.key === 'ArrowDown') {
    event.preventDefault()
    selectedSuggestionIndex.value = (selectedSuggestionIndex.value + 1) % autocompleteSuggestions.value.length
  } else if (event.key === 'ArrowUp') {
    event.preventDefault()
    selectedSuggestionIndex.value = (selectedSuggestionIndex.value - 1 + autocompleteSuggestions.value.length) % autocompleteSuggestions.value.length
  } else if (event.key === 'Enter' || event.key === 'Tab') {
    event.preventDefault()
    const selected = autocompleteSuggestions.value[selectedSuggestionIndex.value]
    if (selected) {
      selectSuggestion(selected)
    }
  } else if (event.key === 'Escape') {
    showAutocomplete.value = false
  }
}

function selectSuggestion(suggestion: NoteMeta) {
  const el = textareaRef.value
  if (!el) return

  const text = editableContent.value
  const start = autocompleteStartIndex.value
  const cursorPos = el.selectionStart

  const insertText = `[[${suggestion.title || suggestion.path}]]`
  editableContent.value = text.substring(0, start) + insertText + text.substring(cursorPos)
  showAutocomplete.value = false

  nextTick(() => {
    el.focus()
    const newCursorPos = start + insertText.length
    el.setSelectionRange(newCursorPos, newCursorPos)
  })
}

async function openHistory() {
  showHistory.value = true
  selectedRevisionId.value = null
  revisionPreviewContent.value = null
  if (!props.workspaceId) return
  revisionsLoading.value = true
  try {
    revisions.value = await getNoteRevisions(props.workspaceId, props.note.id)
  } catch (err) {
    console.error('Failed to load revisions:', err)
  } finally {
    revisionsLoading.value = false
  }
}

async function handleSelectRevision(revisionId: number) {
  selectedRevisionId.value = revisionId
  revisionPreviewContent.value = null
  if (!props.workspaceId) return
  revisionPreviewLoading.value = true
  try {
    const revision = await getNoteRevision(props.workspaceId, props.note.id, revisionId)
    revisionPreviewContent.value = revision.content
  } catch (err) {
    console.error('Failed to load revision:', err)
  } finally {
    revisionPreviewLoading.value = false
  }
}

async function handleRestoreRevision(revisionId: number) {
  if (!props.workspaceId) return
  if (!confirm('Restore this version? Any unsaved changes in the editor will be lost.')) return
  try {
    await restoreNoteRevision(props.workspaceId, props.note.id, revisionId)
    showHistory.value = false
    emit('select-note', props.note.id)
  } catch (err) {
    console.error('Failed to restore revision:', err)
  }
}

async function handleAddProperty(name: string, value: unknown) {
  if (!props.workspaceId) return
  try {
    await setNoteProperty(props.workspaceId, props.note.id, name, value)
    emit('select-note', props.note.id)
  } catch (err) {
    console.error('Failed to set property:', err)
  }
}

async function handleDeleteProperty(name: string) {
  if (!props.workspaceId) return
  try {
    await deleteNoteProperty(props.workspaceId, props.note.id, name)
    emit('select-note', props.note.id)
  } catch (err) {
    console.error('Failed to delete property:', err)
  }
}

async function handleSave() {
  if (!isDirty.value) return
  isSaving.value = true
  try {
    emit('update-note', props.note.id, editableContent.value)
    isDirty.value = false
  } finally {
    isSaving.value = false
  }
}
</script>

<style scoped>
.editor-container {
  display: flex;
  flex-direction: column;
  flex: 1;
  height: 100%;
  background: var(--color-canvas);
  overflow: hidden;
}

.editor-bar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: var(--space-3) var(--space-6);
  background: var(--color-canvas);
  border-bottom: 1px solid var(--color-border);
}

.note-meta-info {
  display: flex;
  flex-direction: column;
}

.editor-title-row {
  display: flex;
  align-items: center;
  gap: var(--space-2);
}

.editor-icon-btn {
  position: relative;
  flex-shrink: 0;
  min-width: 44px;
  min-height: 44px;
  display: flex;
  align-items: center;
  justify-content: center;
  background: transparent;
  border: none;
  border-radius: var(--radius-sm);
  color: var(--color-text-muted);
  font-size: 1.25rem;
  cursor: pointer;
  transition: background-color var(--duration-fast) var(--ease-standard);
}

.editor-icon-btn:hover {
  background: var(--color-hover);
}

.editor-icon-clear {
  position: absolute;
  top: 0;
  right: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  width: 16px;
  height: 16px;
  border-radius: var(--radius-pill);
  background: var(--color-surface-emphasis);
  color: var(--color-text-muted);
  font-size: 0.75rem;
  line-height: 1;
  opacity: 0;
  transition: opacity var(--duration-fast) var(--ease-standard);
}

.editor-icon-btn:hover .editor-icon-clear {
  opacity: 1;
}

.editor-icon-input {
  width: 44px;
  min-height: 44px;
  text-align: center;
  font-size: 1.25rem;
  background: var(--color-surface);
  border: 1px solid var(--color-border-strong);
  border-radius: var(--radius-sm);
  color: var(--color-text);
}

.editor-title {
  margin: 0;
  font-size: var(--text-h1);
  font-weight: 700;
  line-height: 1.15;
  color: var(--color-text);
}

.editor-path {
  font-size: 0.75rem;
  color: var(--color-text-muted);
}

.editor-path-segment {
  background: transparent;
  border: none;
  padding: 0;
  color: var(--color-text-muted);
  font-size: inherit;
  cursor: pointer;
  text-decoration: underline;
  text-decoration-color: transparent;
  transition: color var(--duration-fast) var(--ease-standard),
              text-decoration-color var(--duration-fast) var(--ease-standard);
}

.editor-path-segment:hover {
  color: var(--color-action);
  text-decoration-color: var(--color-action);
}

.editor-path-separator {
  margin: 0 0.2em;
  color: var(--color-text-muted);
}

.editor-controls {
  display: flex;
  align-items: center;
  gap: var(--space-4);
}

.view-mode-toggle {
  display: flex;
  background: var(--color-canvas);
  padding: var(--space-1);
  border-radius: var(--radius-sm);
  border: 1px solid var(--color-border);
}

.toggle-btn {
  background: transparent;
  border: none;
  color: var(--color-text-muted);
  padding: var(--space-1) var(--space-2);
  border-radius: var(--radius-sm);
  font-size: 0.75rem;
  font-weight: 500;
  cursor: pointer;
  transition: background-color var(--duration-fast) var(--ease-standard),
              color var(--duration-fast) var(--ease-standard);
  min-height: 28px;
}

.toggle-btn.active {
  background: var(--color-action);
  color: var(--color-neutral-0);
}

.btn-attach {
  display: flex;
  align-items: center;
  gap: var(--space-2);
  background: transparent;
  border: 1px solid var(--color-border);
  color: var(--color-text);
  padding: var(--space-1) var(--space-3);
  border-radius: var(--radius-sm);
  font-size: 0.875rem;
  font-weight: 500;
  cursor: pointer;
  transition: border-color var(--duration-fast) var(--ease-standard);
  min-height: 36px;
}

.btn-attach:hover:not(:disabled) {
  border-color: var(--color-action);
}

.btn-attach:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}

.btn-save {
  background: var(--color-action);
  color: var(--color-neutral-0);
  border: none;
  padding: var(--space-1) var(--space-4);
  border-radius: var(--radius-sm);
  font-size: 0.875rem;
  font-weight: 600;
  cursor: pointer;
  transition: background-color var(--duration-fast) var(--ease-standard);
  min-height: 36px;
}

.btn-save:hover:not(:disabled) {
  background: var(--color-action-hover);
}

.btn-save:disabled {
  background: var(--color-surface-emphasis);
  color: var(--color-text-muted);
  cursor: default;
}

.editor-body {
  flex: 1;
  display: flex;
  overflow: hidden;
  position: relative;
}

.editor-body.view-split .textarea-wrapper,
.editor-body.view-split .preview-wrapper {
  width: 50%;
}

.editor-body.view-edit .textarea-wrapper {
  width: 100%;
}

.editor-body.view-preview .preview-wrapper {
  width: 100%;
}

.textarea-wrapper {
  position: relative;
  height: 100%;
  border-right: 1px solid var(--color-border);
}

.markdown-textarea {
  width: 100%;
  height: 100%;
  background: var(--color-canvas);
  color: var(--color-text);
  font-family: var(--font-mono);
  font-size: 0.9375rem;
  line-height: 1.6;
  padding: var(--space-4);
  border: none;
  resize: none;
  /* outline:none removed — global :focus-visible handles ring */
}

.preview-wrapper {
  height: 100%;
  overflow-y: auto;
  background: var(--color-canvas);
}

.autocomplete-dropdown {
  position: absolute;
  top: 3.5rem;
  left: 2rem;
  background: var(--color-surface);
  border: 1px solid var(--color-action);
  border-radius: var(--radius-md);
  box-shadow: var(--shadow-float);
  z-index: 50;
  min-width: 240px;
  overflow: hidden;
}

.autocomplete-header {
  font-size: 0.75rem;
  font-weight: 600;
  color: var(--color-text-muted);
  padding: var(--space-2) var(--space-3);
  background: var(--color-surface-emphasis);
  text-transform: uppercase;
  letter-spacing: 0.05em;
}

.autocomplete-item {
  display: flex;
  flex-direction: column;
  padding: var(--space-2) var(--space-3);
  cursor: pointer;
  border-bottom: 1px solid var(--color-border);
}

.autocomplete-item:last-child {
  border-bottom: none;
}

.autocomplete-item.active, .autocomplete-item:hover {
  background: color-mix(in srgb, var(--color-action) 20%, transparent);
}

.suggestion-title {
  font-size: 0.875rem;
  font-weight: 500;
  color: var(--color-text);
}

.suggestion-path {
  font-size: 0.75rem;
  color: var(--color-text-muted);
}

/* Solid drag-upload overlay — no backdrop-filter */
.drag-upload-overlay {
  position: absolute;
  inset: 0;
  background: var(--color-overlay-dark);
  z-index: 100;
  display: flex;
  align-items: center;
  justify-content: center;
  border: 2px dashed var(--color-action);
}

.drag-upload-box {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: var(--space-4);
  color: var(--color-action);
  font-weight: 600;
  font-size: 1.1rem;
}

.editor-status-bar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: var(--space-1) var(--space-4);
  background: var(--color-surface);
  border-top: 1px solid var(--color-border);
  font-size: 0.75rem;
  color: var(--color-text-muted);
}

.status-left, .status-right {
  display: flex;
  align-items: center;
  gap: var(--space-4);
}

.status-pill {
  display: inline-flex;
  align-items: center;
  gap: var(--space-1);
  font-size: 0.75rem;
  color: var(--color-status-success);
}

.status-pill .dot {
  width: 6px;
  height: 6px;
  border-radius: 50%;
  background: var(--color-status-success);
}

.status-pill.dirty {
  color: var(--color-status-warning);
}

.status-pill.dirty .dot {
  background: var(--color-status-warning);
}

.status-pill.saving {
  color: var(--color-action);
}

.status-pill.saving .dot {
  background: var(--color-action);
  animation: pulse 1s infinite;
}

@keyframes pulse {
  0%, 100% { opacity: 1; }
  50% { opacity: 0.3; }
}

.stat-item strong {
  color: var(--color-text);
}
</style>
