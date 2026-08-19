<template>
  <section
    class="editor-pane"
    :data-pane-id="paneId"
    :data-active-note-id="activeId ?? undefined"
  >
    <TabStrip
      :tabs="tabs"
      :active-id="activeId"
      :show-split-action="true"
      @select-tab="$emit('select-note', $event)"
      @close-tab="$emit('close-tab', $event)"
      @split-tab="$emit('split-note', $event)"
      @drag-tab="$emit('drag-note', $event)"
      @drag-end="$emit('drag-end')"
    />

    <div class="editor-pane-content">
      <NoteEditor
        v-if="note"
        :note="note"
        :all-notes="allNotes"
        :workspace-id="workspaceId"
        :pane-id="paneId"
        :drawer-target="drawerTarget"
        @update-note="handleUpdateNote"
        @export-pdf="handleExportPdf"
        @select-note="handleSelectNote"
        @navigate-wikilink="handleNavigateWikilink"
        @reveal-folder="handleRevealFolder"
      />
      <div v-else class="editor-pane-empty" data-testid="editor-pane-empty">
        {{ t('editorPane.empty') }}
      </div>
    </div>
  </section>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import type { NoteDetail, NoteMeta } from '../services/types'
import type { PaneId } from '../composables/useSplitPanes'
import NoteEditor from './NoteEditor.vue'
import TabStrip from './TabStrip.vue'

const { t } = useI18n()

const props = defineProps<{
  paneId: PaneId
  tabs: { id: number; title: string }[]
  activeId: number | null
  note: NoteDetail | null
  allNotes: NoteMeta[]
  workspaceId?: number
}>()

const emit = defineEmits<{
  (e: 'select-note', noteId: number): void
  (e: 'close-tab', noteId: number): void
  (e: 'split-note', noteId: number): void
  (e: 'update-note', noteId: number, content: string): void
  (e: 'export-pdf'): void
  (e: 'navigate-wikilink', target: string): void
  (e: 'reveal-folder', folderPath: string): void
  (e: 'drag-note', noteId: number): void
  (e: 'drag-end'): void
}>()

const drawerTarget = computed(() => `#app-right-drawer-${props.paneId}`)

function handleUpdateNote(noteId: number, content: string): void {
  emit('update-note', noteId, content)
}

function handleExportPdf(): void {
  emit('export-pdf')
}

function handleSelectNote(noteId: number): void {
  emit('select-note', noteId)
}

function handleNavigateWikilink(target: string): void {
  emit('navigate-wikilink', target)
}

function handleRevealFolder(folderPath: string): void {
  emit('reveal-folder', folderPath)
}
</script>

<style scoped>
.editor-pane {
  display: flex;
  min-inline-size: 0;
  min-block-size: 0;
  overflow: hidden;
  flex: 1 1 0;
}

.editor-pane-content {
  min-inline-size: 0;
  min-block-size: 0;
  overflow: auto;
  flex: 1 1 auto;
}

.editor-pane-empty {
  display: grid;
  min-block-size: 100%;
  place-items: center;
  color: var(--color-text-muted);
  padding: var(--space-6);
}
</style>
