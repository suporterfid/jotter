<template>
  <div class="editor-container">
    <div v-if="coverUrl" class="editor-cover-wrapper">
      <img class="editor-cover-image" data-testid="editor-cover-image" :src="coverUrl" alt="" />
      <div class="editor-cover-actions">
        <button type="button" class="btn-cover-action" data-testid="change-cover-btn" @click="isEditingCover = true">{{ t('noteEditor.change') }}</button>
        <button type="button" class="btn-cover-action" data-testid="remove-cover-btn" @click="clearCover">{{ t('noteEditor.remove') }}</button>
      </div>
    </div>
    <CoverImageModal
      v-if="isEditingCover && workspaceId"
      :workspace-id="workspaceId"
      @set-cover="setCover"
      @close="isEditingCover = false"
    />

    <!-- Title area: hovering (or keyboard-focusing) anywhere in this zone
         reveals "Add cover" when the note has none, matching how the cover
         image's own Change/Remove actions already reveal on hover. -->
    <div class="editor-title-zone">
      <button
        v-if="!coverUrl"
        type="button"
        class="add-cover-btn"
        data-testid="add-cover-btn"
        @click="isEditingCover = true"
      >{{ t('noteEditor.addCover') }}</button>

      <!-- Thin utility bar: breadcrumb + quiet actions only. The page
           title used to live here sharing a row with these controls and
           carrying a border-bottom — chrome treatment for what is really
           page content (#257). It now lives in its own block below,
           inside the scrolling canvas. -->
      <header class="editor-bar">
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

      <div class="editor-controls">
        <!-- View Mode Switcher -->
        <div class="view-mode-toggle">
          <button
            class="toggle-btn"
            data-testid="view-mode-edit"
            :class="{ active: viewMode === 'edit' }"
            @click="viewMode = 'edit'"
            :title="t('noteEditor.editorView')"
          >
            {{ t('noteEditor.edit') }}
          </button>
          <button
            class="toggle-btn"
            data-testid="view-mode-split"
            :class="{ active: viewMode === 'split' }"
            @click="viewMode = 'split'"
            :title="t('noteEditor.splitView')"
          >
            {{ t('noteEditor.split') }}
          </button>
          <button
            class="toggle-btn"
            data-testid="view-mode-preview"
            :class="{ active: viewMode === 'preview' }"
            @click="viewMode = 'preview'"
            :title="t('noteEditor.previewView')"
          >
            {{ t('noteEditor.preview') }}
          </button>
          <button
            class="toggle-btn"
            data-testid="view-mode-live"
            :class="{ active: viewMode === 'live' }"
            @click="viewMode = 'live'"
            :title="t('noteEditor.liveView')"
          >
            {{ t('noteEditor.live') }}
          </button>
        </div>

        <div class="stats-toggle-wrapper">
          <button
            type="button"
            class="btn-attach"
            data-testid="stats-toggle-btn"
            :title="t('noteEditor.noteStatistics')"
            :aria-expanded="showStats"
            @click="showStats = !showStats"
          >
            <span>ℹ️</span>
          </button>
          <div v-if="showStats" class="stats-popover" data-testid="stats-popover">
            <span class="stat-item"><strong>{{ wordCount }}</strong> {{ t('noteEditor.words') }}</span>
            <span class="stat-item"><strong>{{ charCount }}</strong> {{ t('noteEditor.chars') }}</span>
            <span class="stat-item"><strong>{{ readingTimeMin }}</strong> {{ t('noteEditor.minRead') }}</span>
          </div>
        </div>

        <button
          class="btn-attach"
          data-testid="history-btn"
          :title="t('noteEditor.versionHistory')"
          @click="openHistory"
        >
          <span>🕘</span>
          <span>{{ t('noteEditor.history') }}</span>
        </button>

        <button
          class="btn-attach"
          data-testid="outline-drawer-btn"
          :title="t('noteEditor.outline')"
          :aria-expanded="isOutlineDrawerOpen"
          @click="isOutlineDrawerOpen = !isOutlineDrawerOpen"
        >
          <span>📑</span>
        </button>

        <button
          class="btn-attach"
          data-testid="local-graph-drawer-btn"
          :title="t('noteEditor.localGraph')"
          :aria-expanded="isLocalGraphDrawerOpen"
          @click="isLocalGraphDrawerOpen = !isLocalGraphDrawerOpen"
        >
          <span>🕸️</span>
        </button>

        <button
          class="btn-attach"
          data-testid="comments-drawer-btn"
          :title="t('noteEditor.comments')"
          :aria-expanded="isCommentsDrawerOpen"
          @click="isCommentsDrawerOpen = !isCommentsDrawerOpen"
        >
          <span>💬</span>
          <span v-if="comments.length">{{ comments.length }}</span>
        </button>

        <button
          class="btn-attach"
          data-testid="checklist-drawer-btn"
          :title="t('noteEditor.checklist')"
          :aria-expanded="isChecklistDrawerOpen"
          @click="isChecklistDrawerOpen = !isChecklistDrawerOpen"
        >
          <span>☑</span>
          <span v-if="checklistItems.length">{{ checklistItems.filter(i => i.done).length }}/{{ checklistItems.length }}</span>
        </button>

        <button
          class="btn-attach"
          data-testid="activity-drawer-btn"
          :title="t('noteEditor.activity')"
          :aria-expanded="isActivityDrawerOpen"
          @click="isActivityDrawerOpen = !isActivityDrawerOpen"
        >
          <span>🕐</span>
        </button>

        <button
          class="btn-attach"
          data-testid="attach-file-btn"
          :disabled="isUploading"
          @click="triggerFileInput"
          :title="t('noteEditor.attachFile')"
        >
          <span>📎</span>
          <span v-if="isUploading">{{ t('noteEditor.uploading') }}</span>
          <span v-else>{{ t('noteEditor.attach') }}</span>
        </button>

        <input 
          ref="fileInputRef" 
          type="file" 
          style="display: none" 
          data-testid="file-upload-input"
          @change="handleFileSelected" 
        />

        <span
          v-if="isSaving || isDirty || showSavedIndicator"
          class="save-status-pill"
          data-testid="save-status-indicator"
          :class="{ dirty: isDirty, saving: isSaving }"
        >
          <span class="dot"></span>
          {{ isSaving ? t('noteEditor.savingStatus') : isDirty ? t('noteEditor.unsavedChanges') : t('noteEditor.saved') }}
        </span>
      </div>
    </header>
    </div>

    <!-- Page Title: real content, not chrome (#257) — icon + editable
         title sit directly in the scrolling canvas, below the cover and
         thin utility bar, matching how a Notion page title behaves.
         Persists to frontmatter.title (excluded from the generic
         Properties list, same as icon/cover) on a short debounce, same
         pattern as the body's own autosave. -->
    <div class="editor-page-title">
      <button
        v-if="!isEditingIcon"
        type="button"
        class="editor-icon-btn"
        :aria-label="noteIcon ? t('noteEditor.changePageIcon') : t('noteEditor.setPageIcon')"
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
      <textarea
        ref="titleTextareaRef"
        v-model="titleDraft"
        class="editor-title-input"
        data-testid="editor-title"
        rows="1"
        :placeholder="t('noteEditor.untitledPlaceholder')"
        @input="handleTitleInput"
        @blur="saveTitle"
        @keydown.enter.prevent="focusContentFromTitle"
      ></textarea>
    </div>

    <!-- Main Editor Content Area -->
    <div
      class="editor-body"
      :class="`view-${viewMode}`"
      @dragover.prevent="handleDragOver"
      @dragenter.prevent="handleDragOver"
    >
      <!-- Editor Area with Autocomplete -->
      <div v-show="viewMode !== 'preview' && viewMode !== 'live'" class="textarea-wrapper">
        <textarea
          ref="textareaRef"
          v-model="editableContent"
          data-testid="markdown-textarea"
          class="markdown-textarea"
          :placeholder="t('noteEditor.markdownPlaceholder')"
          @input="handleInput"
          @keydown="handleKeyDown"
          @mouseup="handleTextSelection"
          @scroll="handleUnhoverWikilink"
        ></textarea>

        <!-- Wikilink Autocomplete Dropdown -->
        <div 
          v-if="showAutocomplete && autocompleteSuggestions.length > 0" 
          class="autocomplete-dropdown"
          :style="autocompleteStyle"
        >
          <div class="autocomplete-header">{{ t('noteEditor.linkToNote') }}</div>
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

      <!-- Live WYSIWYG Area (WY.2, #322) -->
      <div v-if="viewMode === 'live'" class="live-wrapper">
        <NoteEditorWysiwyg
          ref="wysiwygRef"
          v-model:content="editableContent"
          @slash-query="handleWysiwygSlashQuery"
          @comment-trigger="handleWysiwygCommentTrigger"
        />
      </div>

      <!-- Selection-triggered Comment affordance: a small floating button
           appears near where the mouse was released after selecting text,
           matching how Notion/Google Docs anchor commenting to a
           selection instead of only offering a global comment form at
           the bottom of the page. A sibling of both editor surfaces (not
           nested in textarea-wrapper), like SlashMenu below, so it stays
           visible and correctly positioned regardless of viewMode,
           including 'live' (WY.4, #324) where the textarea is v-show'd
           away. Mouse-selection-driven only, not keyboard (shift+arrow)
           selection, on both surfaces. -->
      <button
        v-if="showCommentTrigger"
        type="button"
        class="comment-trigger-btn"
        data-testid="comment-trigger-btn"
        :style="{ top: `${commentTriggerPos.top}px`, left: `${commentTriggerPos.left}px` }"
        @mousedown.prevent="openCommentComposer"
      >
        {{ t('noteEditor.commentTriggerButton') }}
      </button>

      <div
        v-if="showCommentComposer"
        class="comment-composer"
        data-testid="comment-composer"
        :style="{ top: `${commentTriggerPos.top}px`, left: `${commentTriggerPos.left}px` }"
      >
        <textarea
          v-model="commentComposerDraft"
          class="comment-composer-textarea"
          :placeholder="t('noteEditor.commentPlaceholder')"
          data-testid="comment-composer-textarea"
          rows="2"
          autofocus
          @keydown.escape="closeCommentComposer"
        ></textarea>
        <div class="comment-composer-actions">
          <button type="button" class="btn-comment-cancel" data-testid="comment-composer-cancel" @click="closeCommentComposer">{{ t('noteEditor.cancel') }}</button>
          <button
            type="button"
            class="btn-comment-submit"
            data-testid="comment-composer-submit"
            :disabled="!commentComposerDraft.trim()"
            @click="submitSelectionComment"
          >{{ t('noteEditor.comment') }}</button>
        </div>
      </div>

      <!-- Slash-command Menu: a sibling of both editor surfaces (not
           nested in textarea-wrapper) so it stays visible and correctly
           positioned regardless of viewMode, including 'live' (#323) where
           the textarea is v-show'd away. -->
      <SlashMenu
        ref="slashMenuRef"
        :is-open="showSlashMenu"
        :filter-query="slashMenuQuery"
        :style="slashMenuStyle"
        @select="selectSlashBlock"
        @close="closeSlashMenu"
      />

      <!-- Preview Area -->
      <div v-show="viewMode !== 'edit' && viewMode !== 'live'" class="preview-wrapper">
        <MarkdownPreview
          :content="editableContent"
          @navigate-wikilink="$emit('navigate-wikilink', $event)"
          @hover-wikilink="handleHoverWikilink"
          @unhover-wikilink="handleUnhoverWikilink"
          :resolve-embed="resolveEmbed"
        />
      </div>

      <!-- Wikilink Hover Preview Popup (G.2, #287) -->
      <WikilinkPreviewPopup
        v-if="hoveredPreview"
        :rect="hoveredPreview.rect"
        :note="hoveredPreview.resolved?.note ?? null"
        :content="hoveredPreview.resolved?.content ?? null"
        :unresolved-target="hoveredPreview.unresolvedTarget"
      />

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
          <span>{{ t('noteEditor.dropFileHere') }}</span>
        </div>
      </div>
    </div>


    <!-- Properties Panel -->
    <PropertiesPanel
      :properties="note.properties || []"
      :workspace-id="workspaceId"
      @add-property="handleAddProperty"
      @delete-property="handleDeleteProperty"
    />

    <!-- Comments Drawer: teleported to the app shell's right-drawer mount
         point (App.vue) rather than stacked inline below the editor, so
         it slides over the note as an overlay instead of unmounting or
         pushing it — the structural mechanism B.10 asks for, with
         Comments as its first occupant. State/data stay owned here since
         they're note-scoped; only the DOM location moves. -->
    <Teleport to="#app-right-drawer">
      <aside
        v-if="isCommentsDrawerOpen"
        class="comments-drawer"
        data-testid="comments-drawer"
      >
        <div class="comments-drawer-header">
          <h3>{{ t('noteEditor.comments') }}</h3>
          <button
            type="button"
            class="drawer-close-btn"
            data-testid="comments-drawer-close-btn"
            :aria-label="t('noteEditor.closeComments')"
            @click="isCommentsDrawerOpen = false"
          >&times;</button>
        </div>
        <CommentsPanel
          :comments="comments"
          :error-message="commentsError"
          @add-comment="handleAddComment"
          @delete-comment="handleDeleteComment"
        />
      </aside>
    </Teleport>

    <!-- Checklist Drawer: teleported to the same right-drawer mount point as
         Comments. Card-level checklist as a genuinely separate structure
         from the note's own Markdown content (#305) — a note-scoped list of
         checklist_items rows, not derived from `- [ ]` syntax in the body. -->
    <Teleport to="#app-right-drawer">
      <aside
        v-if="isChecklistDrawerOpen"
        class="comments-drawer"
        data-testid="checklist-drawer"
      >
        <div class="comments-drawer-header">
          <h3>{{ t('noteEditor.checklist') }}</h3>
          <button
            type="button"
            class="drawer-close-btn"
            data-testid="checklist-drawer-close-btn"
            :aria-label="t('noteEditor.closeChecklist')"
            @click="isChecklistDrawerOpen = false"
          >&times;</button>
        </div>
        <ChecklistPanel
          :items="checklistItems"
          @add-item="handleAddChecklistItem"
          @toggle-item="handleToggleChecklistItem"
          @delete-item="handleDeleteChecklistItem"
        />
      </aside>
    </Teleport>

    <!-- Activity Drawer: teleported to the same right-drawer mount point.
         Per-card activity feed (#308) — property changes (board moves,
         archive) and checklist changes on this note, sourced from the
         workspace's existing append-only audit log filtered by note_id. -->
    <Teleport to="#app-right-drawer">
      <aside
        v-if="isActivityDrawerOpen"
        class="comments-drawer"
        data-testid="activity-drawer"
      >
        <div class="comments-drawer-header">
          <h3>{{ t('noteEditor.activity') }}</h3>
          <button
            type="button"
            class="drawer-close-btn"
            data-testid="activity-drawer-close-btn"
            :aria-label="t('noteEditor.closeActivity')"
            @click="isActivityDrawerOpen = false"
          >&times;</button>
        </div>
        <ActivityPanel :entries="activityEntries" />
      </aside>
    </Teleport>

    <!-- Outline Drawer: teleported to the same right-drawer mount point as
         Comments (#262), listing the note's headings for quick navigation
         (G.1, #286). -->
    <Teleport to="#app-right-drawer">
      <aside
        v-if="isOutlineDrawerOpen"
        class="outline-drawer"
        data-testid="outline-drawer"
      >
        <div class="outline-drawer-header">
          <h3>{{ t('noteEditor.outline') }}</h3>
          <button
            type="button"
            class="drawer-close-btn"
            data-testid="outline-drawer-close-btn"
            :aria-label="t('noteEditor.closeOutline')"
            @click="isOutlineDrawerOpen = false"
          >&times;</button>
        </div>
        <OutlinePanel :headings="headings" @jump-to-heading="jumpToHeading" />
      </aside>
    </Teleport>

    <!-- Local Graph Drawer: teleported to the same right-drawer mount point as
         Outline/Comments, showing the note's immediate neighbors (backlinks +
         resolved outgoing links) as a small radial graph (G.3, #289). -->
    <Teleport to="#app-right-drawer">
      <aside
        v-if="isLocalGraphDrawerOpen"
        class="local-graph-drawer"
        data-testid="local-graph-drawer"
      >
        <div class="local-graph-drawer-header">
          <h3>{{ t('noteEditor.localGraph') }}</h3>
          <button
            type="button"
            class="drawer-close-btn"
            data-testid="local-graph-drawer-close-btn"
            :aria-label="t('noteEditor.closeLocalGraph')"
            @click="isLocalGraphDrawerOpen = false"
          >&times;</button>
        </div>
        <LocalGraphPanel
          :center-title="note.title"
          :neighbors="localGraphNeighbors"
          @select-neighbor="$emit('select-note', $event)"
        />
      </aside>
    </Teleport>

    <!-- Backlinks Panel: purely derived/read-only (no creation affordance), so it's safe to
         omit entirely when empty, unlike Properties/Comments above which always need to stay
         mounted for their "add" forms. -->
    <BacklinksPanel
      v-if="(note.backlinks || []).length > 0"
      :backlinks="note.backlinks || []"
      @select-note="$emit('select-note', $event)"
    />

    <!-- Outgoing Links Panel: same rationale — derived from note content, no add affordance. -->
    <OutgoingLinksPanel
      v-if="outgoingLinks.length > 0"
      :links="outgoingLinks"
      @select-note="$emit('select-note', $event)"
    />

    <!-- Unlinked Mentions Panel: same rationale — derived from other notes' content. -->
    <UnlinkedMentionsPanel
      v-if="unlinkedMentions.length > 0"
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
import { ref, reactive, watch, computed, nextTick, onUnmounted, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import type { NoteDetail, NoteMeta, NoteRevisionMeta, NoteComment, NoteChecklistItem, NoteActivityEntry, UnlinkedMention, OutgoingLink } from '../services/types'

const { t } = useI18n()
import {
  uploadAttachment,
  getNoteRevisions, getNoteRevision, restoreNoteRevision,
  setNoteProperty, deleteNoteProperty,
  getNoteComments, addNoteComment, deleteNoteComment,
  getChecklistItems, createChecklistItem, updateChecklistItem, deleteChecklistItem,
  getNoteActivity,
  getUnlinkedMentions, getNote, updateNote,
  getOutgoingLinks
} from '../services/api'
import MarkdownPreview from './MarkdownPreview.vue'
import BacklinksPanel from './BacklinksPanel.vue'
import OutgoingLinksPanel from './OutgoingLinksPanel.vue'
import UnlinkedMentionsPanel from './UnlinkedMentionsPanel.vue'
import HistoryPanel from './HistoryPanel.vue'
import PropertiesPanel from './PropertiesPanel.vue'
import ChecklistPanel from './ChecklistPanel.vue'
import ActivityPanel from './ActivityPanel.vue'
import CommentsPanel from './CommentsPanel.vue'
import CoverImageModal from './CoverImageModal.vue'
import SlashMenu from './SlashMenu.vue'
import NoteEditorWysiwyg from './NoteEditorWysiwyg.vue'
import OutlinePanel from './OutlinePanel.vue'
import LocalGraphPanel from './LocalGraphPanel.vue'
import type { LocalGraphNeighbor } from '../services/types'
import { parseHeadings, type HeadingEntry } from '../services/outline'
import WikilinkPreviewPopup from './WikilinkPreviewPopup.vue'
import { resolveWikilinkTarget, parseEmbedTargets } from '../services/wikilinks'
import { renderMarkdown, type EmbedResolution } from '../services/markdown'
import type { BlockDefinition } from '../services/blockRegistry'

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

const coverUrl = computed(() => {
  const cover = props.note.frontmatter?.cover
  return typeof cover === 'string' && cover.trim() !== '' ? cover : null
})

const isEditingCover = ref(false)

async function setCover(url: string) {
  isEditingCover.value = false
  if (!props.workspaceId) return
  try {
    await setNoteProperty(props.workspaceId, props.note.id, 'cover', url)
    emit('select-note', props.note.id)
  } catch (err) {
    console.error('Failed to set cover image:', err)
  }
}

async function clearCover() {
  if (!props.workspaceId) return
  try {
    await deleteNoteProperty(props.workspaceId, props.note.id, 'cover')
    emit('select-note', props.note.id)
  } catch (err) {
    console.error('Failed to clear cover image:', err)
  }
}

// Title is real page content (#257), not read-only chrome: `titleDraft` is
// bound live to an editable field and persisted to frontmatter.title (same
// channel as icon/cover above — excluded from the generic Properties list
// server-side) on a short debounce, mirroring the body's own autosave.
// `note.title` already encodes the server's frontmatter > first-heading >
// filename precedence (MarkdownDocument::resolveTitle), so it's the correct
// initial/reset value without reimplementing that precedence here.
const titleDraft = ref(props.note.title)
const titleTextareaRef = ref<HTMLTextAreaElement | null>(null)
const TITLE_SAVE_DEBOUNCE_MS = 1000
let titleSaveTimer: ReturnType<typeof setTimeout> | null = null

function autosizeTitle() {
  nextTick(() => {
    const el = titleTextareaRef.value
    if (!el) return
    el.style.height = 'auto'
    el.style.height = `${el.scrollHeight}px`
  })
}

function handleTitleInput() {
  if (titleSaveTimer) clearTimeout(titleSaveTimer)
  titleSaveTimer = setTimeout(saveTitle, TITLE_SAVE_DEBOUNCE_MS)
  autosizeTitle()
}

async function saveTitle() {
  if (titleSaveTimer) {
    clearTimeout(titleSaveTimer)
    titleSaveTimer = null
  }
  if (!props.workspaceId) return
  const trimmed = titleDraft.value.trim()
  if (trimmed === props.note.title) return
  try {
    if (trimmed === '') {
      await deleteNoteProperty(props.workspaceId, props.note.id, 'title')
    } else {
      await setNoteProperty(props.workspaceId, props.note.id, 'title', trimmed)
    }
    emit('select-note', props.note.id)
  } catch (err) {
    console.error('Failed to update note title:', err)
  }
}

function focusContentFromTitle() {
  textareaRef.value?.focus()
}

onMounted(autosizeTitle)

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

// 'live' is the default as of WY.5 (#325) — this is the PR that actually
// resolves the Notion-feel gap #263 identified; WY.1-WY.4 were
// prerequisite infrastructure with no default-experience change. Raw
// 'edit' (and 'split'/'preview') stay fully reachable via the toggle as a
// power-user/debug fallback, not removed — see the epic spec §6 for why
// fully retiring the toggle is deferred to a later decision.
const viewMode = ref<'edit' | 'split' | 'preview' | 'live'>('live')
const editableContent = ref(props.note.content)
const isDirty = ref(false)
const isSaving = ref(false)
const showSavedIndicator = ref(false)
const showStats = ref(false)
const AUTOSAVE_DEBOUNCE_MS = 1000
const SAVED_INDICATOR_DURATION_MS = 2000
let autosaveTimer: ReturnType<typeof setTimeout> | null = null
let savedIndicatorTimer: ReturnType<typeof setTimeout> | null = null
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
const checklistItems = ref<NoteChecklistItem[]>([])
const isChecklistDrawerOpen = ref(false)
const activityEntries = ref<NoteActivityEntry[]>([])
const isActivityDrawerOpen = ref(false)
// Deliberately NOT reset on note switch (see the watcher below) — like a
// sidebar, staying open while browsing between notes is the expected
// drawer behavior, not per-note ephemeral UI state.
const isCommentsDrawerOpen = ref(false)
const isOutlineDrawerOpen = ref(false)
const headings = computed<HeadingEntry[]>(() => parseHeadings(editableContent.value))

const isLocalGraphDrawerOpen = ref(false)

const localGraphNeighbors = computed<LocalGraphNeighbor[]>(() => {
  const seen = new Set<number>()
  const neighbors: LocalGraphNeighbor[] = []

  for (const backlink of props.note.backlinks || []) {
    if (seen.has(backlink.id)) continue
    seen.add(backlink.id)
    neighbors.push({ id: backlink.id, title: backlink.title, path: backlink.path, direction: 'backlink' })
  }

  for (const link of outgoingLinks.value) {
    if (!link.resolved || link.id === null) continue
    if (seen.has(link.id)) continue
    seen.add(link.id)
    neighbors.push({ id: link.id, title: link.title ?? link.path ?? '', path: link.path ?? '', direction: 'outgoing' })
  }

  return neighbors
})

function jumpToHeading(heading: HeadingEntry) {
  if (viewMode.value === 'preview') {
    document.getElementById(heading.id)?.scrollIntoView({ behavior: 'smooth', block: 'start' })
    return
  }

  const lines = editableContent.value.split('\n')
  let offset = 0
  for (let i = 0; i < heading.line; i += 1) {
    offset += lines[i].length + 1
  }

  const textarea = textareaRef.value
  if (!textarea) return
  textarea.setSelectionRange(offset, offset)
  textarea.focus()
}

interface HoveredWikilinkPreview {
  rect: DOMRect
  resolved: { note: NoteMeta; content: string | null } | null
  unresolvedTarget: string | null
}

const hoveredPreview = ref<HoveredWikilinkPreview | null>(null)
const noteContentCache = new Map<number, string>()

async function handleHoverWikilink(target: string, rect: DOMRect) {
  const match = resolveWikilinkTarget(target, props.allNotes)

  if (!match) {
    hoveredPreview.value = { rect, resolved: null, unresolvedTarget: target }
    return
  }

  const cached = noteContentCache.get(match.id)
  if (cached !== undefined) {
    hoveredPreview.value = { rect, resolved: { note: match, content: cached }, unresolvedTarget: null }
    return
  }

  hoveredPreview.value = { rect, resolved: { note: match, content: null }, unresolvedTarget: null }
  if (!props.workspaceId) return

  try {
    const detail = await getNote(props.workspaceId, match.id)
    noteContentCache.set(match.id, detail.content)
    // A hover the user has already left must not clobber a newer one.
    if (hoveredPreview.value?.resolved?.note.id === match.id) {
      hoveredPreview.value = { ...hoveredPreview.value, resolved: { note: match, content: detail.content } }
    }
  } catch {
    // Passive affordance — a failed fetch just leaves the popup on its loading state.
  }
}

function handleUnhoverWikilink() {
  hoveredPreview.value = null
}

const embedContentCache = reactive(new Map<number, string>())

watch(editableContent, (content) => {
  const targets = parseEmbedTargets(content)
  targets.forEach(async (target) => {
    const match = resolveWikilinkTarget(target, props.allNotes)
    if (!match || match.id === props.note.id) return
    if (embedContentCache.has(match.id)) return
    if (!props.workspaceId) return

    try {
      const detail = await getNote(props.workspaceId, match.id)
      embedContentCache.set(match.id, detail.content)
    } catch {
      // Passive affordance — a failed fetch just leaves the embed on its loading state.
    }
  })
}, { immediate: true })

function resolveEmbed(target: string): EmbedResolution {
  const match = resolveWikilinkTarget(target, props.allNotes)
  if (!match) return { status: 'unresolved' }
  if (match.id === props.note.id) return { status: 'circular' }

  const content = embedContentCache.get(match.id)
  if (content === undefined) return { status: 'loading' }
  return { status: 'resolved', html: renderMarkdown(content) }
}
const unlinkedMentions = ref<UnlinkedMention[]>([])
const outgoingLinks = ref<OutgoingLink[]>([])

// Selection-triggered comment popover (#261)
const showCommentTrigger = ref(false)
const showCommentComposer = ref(false)
const commentComposerDraft = ref('')
const commentTriggerPos = ref({ top: 0, left: 0 })
const pendingCommentAnchorLine = ref<number | null>(null)

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

// Slash-command menu state, mirroring the wikilink autocomplete above.
const showSlashMenu = ref(false)
const slashMenuQuery = ref('')
const slashMenuStartIndex = ref(-1)
const slashMenuRef = ref<InstanceType<typeof SlashMenu> | null>(null)
const slashMenuStyle = ref({ top: '40px', left: '20px' })
const wysiwygRef = ref<InstanceType<typeof NoteEditorWysiwyg> | null>(null)

/**
 * Re-points the same SlashMenu.vue (#256) at the Live WYSIWYG surface
 * (WY.3, #323): NoteEditorWysiwyg.vue emits this from
 * wysiwygEditor.ts's onSlashQuery, using the same "start of line, or
 * after a space" trigger rule handleInput's textarea version uses below.
 */
function handleWysiwygSlashQuery(state: { query: string; top: number; left: number } | null) {
  if (!state) {
    showSlashMenu.value = false
    return
  }
  showSlashMenu.value = true
  slashMenuQuery.value = state.query
  slashMenuStyle.value = { top: `${state.top}px`, left: `${state.left}px` }
}

// `note` is replaced with a new object reference not just when the user
// switches notes, but also every time our own autosave round-trips
// (handleUpdateNote -> refreshNotesList reloads the same note). Comparing
// ids lets us skip the reset below on that routine same-note reload —
// otherwise it stomps the in-flight "Saved" indicator almost immediately
// after every autosave, and could clobber newer unsaved edits typed
// during the round-trip.
let currentNoteId: number | null = null
watch(() => props.note, (newNote) => {
  if (newNote.id === currentNoteId) return
  currentNoteId = newNote.id
  if (autosaveTimer) {
    clearTimeout(autosaveTimer)
    autosaveTimer = null
  }
  if (savedIndicatorTimer) {
    clearTimeout(savedIndicatorTimer)
    savedIndicatorTimer = null
  }
  showSavedIndicator.value = false
  editableContent.value = newNote.content
  isDirty.value = false
  if (titleSaveTimer) {
    clearTimeout(titleSaveTimer)
    titleSaveTimer = null
  }
  titleDraft.value = newNote.title
  autosizeTitle()
  showAutocomplete.value = false
  showCommentTrigger.value = false
  showCommentComposer.value = false
  showHistory.value = false
  loadComments(newNote.id)
  loadChecklistItems(newNote.id)
  loadActivity(newNote.id)
  loadUnlinkedMentions(newNote.id)
  loadOutgoingLinks(newNote.id)
}, { immediate: true })

async function loadActivity(noteId: number) {
  if (!props.workspaceId) return
  try {
    activityEntries.value = await getNoteActivity(props.workspaceId, noteId)
  } catch (err) {
    console.error('Failed to load activity:', err)
  }
}

async function loadComments(noteId: number) {
  commentsError.value = null
  if (!props.workspaceId) return
  try {
    comments.value = await getNoteComments(props.workspaceId, noteId)
  } catch (err) {
    console.error('Failed to load comments:', err)
  }
}

async function loadChecklistItems(noteId: number) {
  if (!props.workspaceId) return
  try {
    checklistItems.value = await getChecklistItems(props.workspaceId, noteId)
  } catch (err) {
    console.error('Failed to load checklist items:', err)
  }
}

async function handleAddChecklistItem(text: string) {
  if (!props.workspaceId) return
  try {
    const item = await createChecklistItem(props.workspaceId, props.note.id, text)
    checklistItems.value.push(item)
  } catch (err) {
    console.error('Failed to add checklist item:', err)
  }
}

async function handleToggleChecklistItem(itemId: number) {
  if (!props.workspaceId) return
  const item = checklistItems.value.find(i => i.id === itemId)
  if (!item) return
  try {
    const updated = await updateChecklistItem(props.workspaceId, props.note.id, itemId, { done: !item.done })
    const index = checklistItems.value.findIndex(i => i.id === itemId)
    if (index !== -1) checklistItems.value[index] = updated
  } catch (err) {
    console.error('Failed to toggle checklist item:', err)
  }
}

async function handleDeleteChecklistItem(itemId: number) {
  if (!props.workspaceId) return
  try {
    await deleteChecklistItem(props.workspaceId, props.note.id, itemId)
    checklistItems.value = checklistItems.value.filter(i => i.id !== itemId)
  } catch (err) {
    console.error('Failed to delete checklist item:', err)
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

async function handleAddComment(content: string, anchorLine?: number) {
  if (!props.workspaceId) return
  commentsError.value = null
  try {
    const comment = await addNoteComment(props.workspaceId, props.note.id, content, anchorLine)
    comments.value.push(comment)
  } catch (err: any) {
    console.error('Failed to add comment:', err)
    commentsError.value = err.response?.data?.message || 'Failed to add comment.'
  }
}

function handleTextSelection(event: MouseEvent) {
  const el = textareaRef.value
  if (!el) return
  const { selectionStart, selectionEnd } = el
  if (selectionStart === selectionEnd) {
    showCommentTrigger.value = false
    return
  }

  // .comment-trigger-btn/.comment-composer are positioned relative to
  // .editor-body (they're siblings of textarea-wrapper/live-wrapper, not
  // nested in either — see #323's SlashMenu relocation for the same
  // reason), so coordinates are computed relative to that shared ancestor
  // regardless of which surface the selection came from.
  const wrapperEl = (event.currentTarget as HTMLElement).closest('.editor-body')
  if (!wrapperEl) return
  const wrapperRect = wrapperEl.getBoundingClientRect()
  pendingCommentAnchorLine.value = editableContent.value.slice(0, selectionStart).split('\n').length
  commentTriggerPos.value = {
    top: event.clientY - wrapperRect.top + 12,
    left: event.clientX - wrapperRect.left,
  }
  showCommentTrigger.value = true
  showCommentComposer.value = false
}

/**
 * Live mode's equivalent of handleTextSelection above: NoteEditorWysiwyg.vue
 * emits this on mouseup with an exact document-structure anchor line
 * (wysiwygEditor.ts's getSelectionAnchorLine(), WY.4/#324) instead of the
 * textarea's selectionStart character-offset heuristic, or null when the
 * selection is collapsed.
 */
function handleWysiwygCommentTrigger(state: { line: number; top: number; left: number } | null) {
  if (!state) {
    showCommentTrigger.value = false
    return
  }
  pendingCommentAnchorLine.value = state.line
  commentTriggerPos.value = { top: state.top, left: state.left }
  showCommentTrigger.value = true
  showCommentComposer.value = false
}

function openCommentComposer() {
  showCommentTrigger.value = false
  showCommentComposer.value = true
  commentComposerDraft.value = ''
}

function closeCommentComposer() {
  showCommentComposer.value = false
  commentComposerDraft.value = ''
  pendingCommentAnchorLine.value = null
}

async function submitSelectionComment() {
  const content = commentComposerDraft.value.trim()
  if (!content) return
  await handleAddComment(content, pendingCommentAnchorLine.value ?? undefined)
  closeCommentComposer()
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
    if (autosaveTimer) clearTimeout(autosaveTimer)
    autosaveTimer = setTimeout(() => {
      handleSave()
    }, AUTOSAVE_DEBOUNCE_MS)
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

  showCommentTrigger.value = false
  if (showCommentComposer.value) closeCommentComposer()

  const text = editableContent.value
  const cursorPos = el.selectionStart

  // Look backwards for [[
  const textBeforeCursor = text.substring(0, cursorPos)
  const lastDoubleBracket = textBeforeCursor.lastIndexOf('[[')

  if (lastDoubleBracket !== -1) {
    const textAfterBracket = textBeforeCursor.substring(lastDoubleBracket + 2)
    // Check if there is a closing bracket or newline in between
    if (!textAfterBracket.includes(']]') && !textAfterBracket.includes('\n')) {
      showSlashMenu.value = false
      showAutocomplete.value = true
      autocompleteQuery.value = textAfterBracket
      autocompleteStartIndex.value = lastDoubleBracket
      selectedSuggestionIndex.value = 0
      return
    }
  }

  showAutocomplete.value = false

  // Look backwards for a slash-command trigger: "/" at the start of the
  // line or right after a space, mirroring the [[ wikilink trigger above.
  const lastSlash = textBeforeCursor.lastIndexOf('/')
  if (lastSlash !== -1) {
    const charBeforeSlash = textBeforeCursor[lastSlash - 1]
    const isTriggerPosition = lastSlash === 0 || charBeforeSlash === '\n' || charBeforeSlash === ' '
    if (isTriggerPosition) {
      const textAfterSlash = textBeforeCursor.substring(lastSlash + 1)
      // Check the query so far doesn't contain a space or newline (menu
      // would no longer make sense as a live filter at that point)
      if (!textAfterSlash.includes(' ') && !textAfterSlash.includes('\n')) {
        showSlashMenu.value = true
        slashMenuQuery.value = textAfterSlash
        slashMenuStartIndex.value = lastSlash
        return
      }
    }
  }

  showSlashMenu.value = false
}

function handleKeyDown(event: KeyboardEvent) {
  // Ctrl+S is intentionally not reachable while this menu owns keydown
  // handling, consistent with the pre-existing wikilink-autocomplete
  // branch below (which does the same while its own suggestions are
  // shown) — both menus fully consume arrow/Enter/Escape.
  if (showSlashMenu.value) {
    slashMenuRef.value?.handleKeyDown(event)
    return
  }

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

function selectSlashBlock(block: BlockDefinition) {
  if (viewMode.value === 'live') {
    wysiwygRef.value?.insertBlock(block.syntax)
    showSlashMenu.value = false
    return
  }

  const el = textareaRef.value
  if (!el) return

  const text = editableContent.value
  const start = slashMenuStartIndex.value
  const cursorPos = el.selectionStart

  const insertText = block.syntax
  editableContent.value = text.substring(0, start) + insertText + text.substring(cursorPos)
  showSlashMenu.value = false

  nextTick(() => {
    el.focus()
    const newCursorPos = start + insertText.length
    el.setSelectionRange(newCursorPos, newCursorPos)
  })
}

function closeSlashMenu() {
  showSlashMenu.value = false
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
  if (!confirm(t('noteEditor.restoreVersionConfirm'))) return
  try {
    await restoreNoteRevision(props.workspaceId, props.note.id, revisionId)
    showHistory.value = false
    // The note.id watch below (guarding autosave's own same-note reload)
    // otherwise skips refreshing editableContent here too, since a
    // restore keeps the same note id — force it to treat the upcoming
    // prop update as a real content change (WY.4, #324).
    currentNoteId = null
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
  if (autosaveTimer) {
    clearTimeout(autosaveTimer)
    autosaveTimer = null
  }
  if (!isDirty.value) return
  isSaving.value = true
  try {
    emit('update-note', props.note.id, editableContent.value)
    isDirty.value = false
    showSavedIndicator.value = true
    if (savedIndicatorTimer) clearTimeout(savedIndicatorTimer)
    savedIndicatorTimer = setTimeout(() => {
      showSavedIndicator.value = false
    }, SAVED_INDICATOR_DURATION_MS)
  } finally {
    isSaving.value = false
  }
}

onUnmounted(() => {
  if (autosaveTimer) clearTimeout(autosaveTimer)
  if (savedIndicatorTimer) clearTimeout(savedIndicatorTimer)
  if (titleSaveTimer) clearTimeout(titleSaveTimer)
})
</script>

<style scoped>
.editor-container {
  display: flex;
  flex-direction: column;
  flex: 1;
  height: 100%;
  background: var(--color-canvas);
  /* Scroll the whole column (canvas + metadata panels) as one unit instead
     of clipping it: previously `overflow: hidden` here meant the metadata
     panels (siblings after .editor-body) always took their full natural
     height first, and .editor-body's `flex: 1` absorbed whatever was left
     with no way to scroll back to a full canvas view. Panels now live below
     the fold and .editor-body's min-height keeps the canvas from being
     squeezed to near-nothing in the meantime. */
  overflow-y: auto;
}

.editor-bar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: var(--space-2) var(--space-6);
  background: var(--color-canvas);
  border-bottom: 1px solid var(--color-border);
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
  inset-block-start: 0;
  inset-inline-end: 0;
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

.editor-page-title {
  display: flex;
  align-items: center;
  gap: var(--space-2);
  padding: var(--space-2) var(--space-6) 0;
  background: var(--color-canvas);
}

.editor-title-input {
  display: block;
  width: 100%;
  margin: 0;
  padding: var(--space-1) 0;
  border: none;
  /* outline:none omitted — global :focus-visible handles the ring, same
     as .markdown-textarea below. */
  resize: none;
  overflow: hidden;
  background: transparent;
  font-family: inherit;
  font-size: var(--text-h1);
  font-weight: 700;
  line-height: 1.15;
  color: var(--color-text);
}

.editor-title-input::placeholder {
  color: var(--color-text-muted);
}

.editor-title-zone {
  position: relative;
}

.add-cover-btn {
  display: block;
  width: 100%;
  background: transparent;
  border: none;
  border-bottom: 0 solid transparent;
  color: var(--color-text-muted);
  padding: 0 var(--space-2);
  max-height: 0;
  overflow: hidden;
  opacity: 0;
  cursor: pointer;
  font-size: 0.8125rem;
  text-align: center;
  transition: max-height var(--duration-fast) var(--ease-standard),
              padding var(--duration-fast) var(--ease-standard),
              opacity var(--duration-fast) var(--ease-standard),
              border-color var(--duration-fast) var(--ease-standard),
              border-width var(--duration-fast) var(--ease-standard),
              background-color var(--duration-fast) var(--ease-standard),
              color var(--duration-fast) var(--ease-standard);
}

.editor-title-zone:hover .add-cover-btn,
.editor-title-zone:focus-within .add-cover-btn {
  max-height: 40px;
  padding: var(--space-2);
  opacity: 1;
  border-bottom: 1px solid var(--color-border);
}

.add-cover-btn:hover {
  background: var(--color-hover);
  color: var(--color-action);
}

.editor-cover-wrapper {
  position: relative;
  width: 100%;
  height: 200px;
  overflow: hidden;
}

.editor-cover-image {
  width: 100%;
  height: 100%;
  object-fit: cover;
  display: block;
}

.editor-cover-actions {
  position: absolute;
  inset-block-end: var(--space-3);
  inset-inline-end: var(--space-3);
  display: flex;
  gap: var(--space-2);
  opacity: 0;
  transition: opacity var(--duration-fast) var(--ease-standard);
}

.editor-cover-wrapper:hover .editor-cover-actions {
  opacity: 1;
}

.btn-cover-action {
  background: var(--color-overlay);
  color: var(--color-text-inverse);
  border: none;
  border-radius: var(--radius-sm);
  padding: var(--space-1) var(--space-3);
  cursor: pointer;
  font-size: 0.8125rem;
  transition: background-color var(--duration-fast) var(--ease-standard);
}

.btn-cover-action:hover {
  background: color-mix(in srgb, var(--color-overlay) 80%, black);
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

.editor-body {
  flex: 1;
  /* Safety floor, not the primary sizing mechanism: .editor-container's own
     ancestor chain (.main-content -> .app-layout) is height:100% all the
     way up to a 100vh root, so flex:1 already resolves to a real pixel
     budget on its own. This just stops the panels below from squeezing
     that budget under a usable minimum on tall panel stacks. Revisit this
     constant if/when the full properties/drawer rework (#256/#258/#262)
     changes how much space the panels need below the fold. */
  min-height: 50vh;
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

.editor-body.view-live .live-wrapper {
  width: 100%;
}

.textarea-wrapper {
  position: relative;
  height: 100%;
  border-inline-end: 1px solid var(--color-border);
}

.markdown-textarea {
  /* Centered reading column (Notion-style ~640-760px page), not full pane
     width — see docs/visual-identity.md §11, previously an open item. */
  width: 100%;
  max-width: 720px;
  margin: 0 auto;
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

.live-wrapper {
  height: 100%;
  overflow: hidden;
  display: flex;
  background: var(--color-canvas);
}

.autocomplete-dropdown {
  position: absolute;
  top: 3.5rem;
  /* Stays near the left edge of the centered 760px reading column
     (#255) instead of the wrapper's full-width edge; falls back to the
     pre-#255 fixed 2rem once the column stops being centered (viewport
     narrower than the column + its margins). */
  left: max(2rem, calc(50% - 348px));
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

.comment-trigger-btn {
  position: absolute;
  transform: translate(-50%, 0);
  z-index: 50;
  background: var(--color-surface);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-pill);
  padding: var(--space-1) var(--space-3);
  color: var(--color-text);
  font-size: 0.8125rem;
  box-shadow: var(--shadow-float);
  cursor: pointer;
  white-space: nowrap;
}

.comment-trigger-btn:hover {
  background: var(--color-hover);
}

.comment-composer {
  position: absolute;
  transform: translate(-50%, 0);
  z-index: 50;
  display: flex;
  flex-direction: column;
  gap: var(--space-2);
  width: min(280px, 90vw);
  background: var(--color-surface);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-md);
  padding: var(--space-2);
  box-shadow: var(--shadow-float);
}

.comment-composer-textarea {
  background: var(--color-canvas);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-sm);
  padding: var(--space-2);
  color: var(--color-text);
  font-size: 0.8125rem;
  font-family: inherit;
  resize: vertical;
}

.comment-composer-actions {
  display: flex;
  justify-content: flex-end;
  gap: var(--space-2);
}

.btn-comment-cancel {
  background: transparent;
  border: none;
  color: var(--color-text-muted);
  padding: var(--space-1) var(--space-2);
  border-radius: var(--radius-sm);
  cursor: pointer;
  font-size: 0.8125rem;
}

.btn-comment-cancel:hover {
  background: var(--color-hover);
}

.btn-comment-submit {
  background: var(--color-action);
  color: var(--color-neutral-0);
  border: none;
  padding: var(--space-1) var(--space-3);
  border-radius: var(--radius-sm);
  cursor: pointer;
  font-size: 0.8125rem;
  transition: background-color var(--duration-fast) var(--ease-standard);
}

.btn-comment-submit:hover:not(:disabled) {
  background: var(--color-action-hover);
}

.btn-comment-submit:disabled {
  opacity: 0.5;
  cursor: not-allowed;
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

.save-status-pill {
  display: inline-flex;
  align-items: center;
  gap: var(--space-1);
  font-size: 0.75rem;
  color: var(--color-status-success);
}

.save-status-pill .dot {
  width: 6px;
  height: 6px;
  border-radius: 50%;
  background: var(--color-status-success);
}

.save-status-pill.dirty {
  color: var(--color-status-warning);
}

.save-status-pill.dirty .dot {
  background: var(--color-status-warning);
}

.save-status-pill.saving {
  color: var(--color-action);
}

.save-status-pill.saving .dot {
  background: var(--color-action);
  animation: pulse 1s infinite;
}

@keyframes pulse {
  0%, 100% { opacity: 1; }
  50% { opacity: 0.3; }
}

.stats-toggle-wrapper {
  position: relative;
}

.stats-popover {
  position: absolute;
  inset-block-start: 100%;
  inset-inline-end: 0;
  margin-top: var(--space-1);
  display: flex;
  flex-direction: column;
  gap: var(--space-1);
  padding: var(--space-2) var(--space-3);
  background: var(--color-surface);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-sm);
  box-shadow: var(--shadow-float);
  font-size: 0.75rem;
  color: var(--color-text-muted);
  white-space: nowrap;
  z-index: 10;
}

.stat-item strong {
  color: var(--color-text);
}

.comments-drawer {
  position: fixed;
  inset-block-start: 0;
  inset-inline-end: 0;
  height: 100vh;
  width: min(360px, 100vw);
  background: var(--color-surface);
  border-inline-start: 1px solid var(--color-border);
  box-shadow: var(--shadow-float);
  /* Above App.vue's mobile sidebar backdrop (z-index: 30) so the drawer
     stays reachable if it's opened while the mobile sidebar is up. */
  z-index: 40;
  display: flex;
  flex-direction: column;
  overflow-y: auto;
}

@media (max-width: 480px) {
  .comments-drawer {
    width: 100vw;
  }
}

.comments-drawer-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: var(--space-3) var(--space-4);
  border-bottom: 1px solid var(--color-border);
}

.comments-drawer-header h3 {
  margin: 0;
  font-size: 0.9375rem;
  font-weight: 600;
  color: var(--color-text);
}

.drawer-close-btn {
  background: transparent;
  border: none;
  color: var(--color-text-muted);
  font-size: 1.25rem;
  line-height: 1;
  cursor: pointer;
  padding: var(--space-1);
  border-radius: var(--radius-sm);
}

.drawer-close-btn:hover {
  color: var(--color-text);
  background: var(--color-hover);
}

.outline-drawer {
  position: fixed;
  inset-block-start: 0;
  inset-inline-end: 0;
  height: 100vh;
  width: min(360px, 100vw);
  background: var(--color-surface);
  border-inline-start: 1px solid var(--color-border);
  box-shadow: var(--shadow-float);
  z-index: 40;
  display: flex;
  flex-direction: column;
  overflow-y: auto;
}

@media (max-width: 480px) {
  .outline-drawer {
    width: 100vw;
  }
}

.outline-drawer-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: var(--space-3) var(--space-4);
  border-bottom: 1px solid var(--color-border);
}

.outline-drawer-header h3 {
  margin: 0;
  font-size: 0.9375rem;
  font-weight: 600;
  color: var(--color-text);
}

.local-graph-drawer {
  position: fixed;
  inset-block-start: 0;
  inset-inline-end: 0;
  height: 100vh;
  width: min(360px, 100vw);
  background: var(--color-surface);
  border-inline-start: 1px solid var(--color-border);
  box-shadow: var(--shadow-float);
  z-index: 40;
  display: flex;
  flex-direction: column;
  overflow-y: auto;
}

@media (max-width: 480px) {
  .local-graph-drawer {
    width: 100vw;
  }
}

.local-graph-drawer-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: var(--space-3) var(--space-4);
  border-bottom: 1px solid var(--color-border);
}

.local-graph-drawer-header h3 {
  margin: 0;
  font-size: 0.9375rem;
  font-weight: 600;
  color: var(--color-text);
}
</style>
