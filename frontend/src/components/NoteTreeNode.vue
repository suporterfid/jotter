<template>
  <div
    v-if="node.type === 'folder'"
    class="tree-folder"
    data-item-type="folder"
    :data-item-path="node.fullPath"
  >
    <div class="folder-row-wrapper">
      <button
        type="button"
        class="folder-row"
        :style="{ paddingLeft: `${depth * 14 + 8}px` }"
        :aria-expanded="expanded"
        @click="expanded = !expanded"
      >
        <svg
          class="chevron"
          :class="{ collapsed: !expanded }"
          viewBox="0 0 24 24"
          width="12"
          height="12"
          fill="none"
          stroke="currentColor"
          stroke-width="2.5"
        >
          <polyline points="9 6 15 12 9 18"></polyline>
        </svg>
        <svg class="folder-icon" viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2">
          <path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7z"></path>
        </svg>
        <span class="folder-name">{{ node.name }}</span>
        <span class="folder-count">{{ noteCount }}</span>
      </button>
      <button
        type="button"
        class="btn-create-in-folder"
        data-testid="folder-create-note-btn"
        title="Create note in this folder"
        aria-label="Create note in this folder"
        @click.stop="createNoteInFolder"
      >
        <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2">
          <line x1="12" y1="5" x2="12" y2="19"></line>
          <line x1="5" y1="12" x2="19" y2="12"></line>
        </svg>
      </button>
    </div>
    <div v-show="expanded" class="folder-children" ref="childrenListRef" :data-folder-path="node.fullPath">
      <NoteTreeNode
        v-for="child in node.children"
        :key="child.type === 'folder' ? `f:${child.fullPath}` : `n:${child.note.id}`"
        :node="child"
        :selected-note-id="selectedNoteId"
        :depth="depth + 1"
        :reveal-path="revealPath"
        @select-note="$emit('select-note', $event)"
        @delete-note="$emit('delete-note', $event)"
        @create-note-in-folder="$emit('create-note-in-folder', $event)"
      />
    </div>
  </div>

  <div
    v-else
    class="note-item"
    :class="{ active: selectedNoteId === node.note.id }"
    :style="{ paddingLeft: `${depth * 14 + 8}px` }"
    data-item-type="note"
    :data-item-id="node.note.id"
    :data-item-note-path="node.note.path"
    @click="$emit('select-note', node.note.id)"
  >
    <span v-if="noteIcon" class="note-icon" data-testid="note-icon-emoji">{{ noteIcon }}</span>
    <svg v-else class="note-icon note-icon-fallback" data-testid="note-icon-fallback" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.5">
      <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
      <polyline points="14 2 14 8 20 8"></polyline>
    </svg>
    <div class="note-info">
      <span class="note-title">{{ node.note.title || node.note.path }}</span>
      <span class="note-path">{{ node.note.path }}</span>
    </div>
    <button
      class="btn-delete"
      title="Delete note"
      @click.stop="$emit('delete-note', node.note.id)"
    >
      <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2">
        <polyline points="3 6 5 6 21 6"></polyline>
        <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path>
      </svg>
    </button>
  </div>
</template>

<script setup lang="ts">
import { computed, ref, inject, onMounted, onBeforeUnmount, watch, useTemplateRef, type Ref } from 'vue'
import type { NoteMeta } from '../services/types'
import {
  createNoteTreeSortable,
  type NoteTreeSortableCallbacks,
  type NoteTreeSortableHandle,
} from '../composables/useNoteTreeSortable'

export interface TreeFolder {
  type: 'folder'
  name: string
  fullPath: string
  children: TreeNode[]
}

export interface TreeFile {
  type: 'file'
  note: NoteMeta
}

export type TreeNode = TreeFolder | TreeFile

const props = defineProps<{
  node: TreeNode
  selectedNoteId: number | null
  depth: number
  revealPath?: string | null
}>()

const emit = defineEmits<{
  (e: 'select-note', noteId: number): void
  (e: 'delete-note', noteId: number): void
  (e: 'create-note-in-folder', folderPath: string): void
}>()

const expanded = ref(true)

function isRevealTarget(revealPath: string, fullPath: string): boolean {
  return revealPath === fullPath || revealPath.startsWith(`${fullPath}/`)
}

watch(
  () => props.revealPath,
  (revealPath) => {
    if (props.node.type === 'folder' && revealPath && isRevealTarget(revealPath, props.node.fullPath)) {
      expanded.value = true
    }
  },
)

function countNotes(node: TreeNode): number {
  if (node.type === 'file') return 1
  return node.children.reduce((sum, child) => sum + countNotes(child), 0)
}

const noteCount = computed(() => (props.node.type === 'folder' ? countNotes(props.node) : 0))

const noteIcon = computed(() => {
  if (props.node.type !== 'file') return null
  const icon = props.node.note.frontmatter?.icon
  return typeof icon === 'string' && icon.trim() !== '' ? icon : null
})

function createNoteInFolder() {
  expanded.value = true
  if (props.node.type === 'folder') {
    emit('create-note-in-folder', props.node.fullPath)
  }
}

const dragCallbacks = inject<NoteTreeSortableCallbacks>('noteTreeDragCallbacks')
const isManualMode = inject<Ref<boolean>>('noteTreeManualMode')

const childrenListRef = useTemplateRef<HTMLElement>('childrenListRef')
let childrenSortable: NoteTreeSortableHandle | null = null

onMounted(() => {
  if (!childrenListRef.value || !dragCallbacks || !isManualMode) return
  childrenSortable = createNoteTreeSortable(childrenListRef.value, !isManualMode.value, dragCallbacks)
})

watch(isManualMode ?? ref(false), (manual) => {
  childrenSortable?.setDisabled(!manual)
})

onBeforeUnmount(() => {
  childrenSortable?.destroy()
})
</script>

<style scoped>
.folder-row-wrapper {
  display: flex;
  align-items: center;
}

.folder-row {
  flex: 1;
  min-width: 0;
  display: flex;
  align-items: center;
  gap: var(--space-1);
  background: transparent;
  border: none;
  color: var(--color-text-muted);
  padding: var(--space-2) var(--space-2) var(--space-2) 0;
  border-radius: var(--radius-sm);
  cursor: pointer;
  font-size: 0.8125rem;
  font-weight: 600;
  min-height: 32px;
  text-align: left;
  transition: background-color var(--duration-fast) var(--ease-standard),
              color var(--duration-fast) var(--ease-standard);
}

.folder-row:hover {
  background: var(--color-hover);
  color: var(--color-text);
}

.chevron {
  flex-shrink: 0;
  transition: transform var(--duration-fast) var(--ease-standard);
}

.chevron.collapsed {
  transform: rotate(-90deg);
}

.folder-icon {
  flex-shrink: 0;
  color: var(--color-action);
}

.folder-name {
  flex: 1;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.folder-count {
  background: var(--color-surface-emphasis);
  color: var(--color-text-muted);
  padding: 0.05rem 0.375rem;
  border-radius: var(--radius-sm);
  font-size: 0.6875rem;
  font-weight: 500;
}

.btn-create-in-folder {
  background: transparent;
  border: none;
  color: var(--color-text-muted);
  padding: var(--space-1);
  border-radius: var(--radius-sm);
  cursor: pointer;
  opacity: 0;
  flex-shrink: 0;
  margin-right: var(--space-1);
  transition: color var(--duration-fast) var(--ease-standard),
              background-color var(--duration-fast) var(--ease-standard),
              opacity var(--duration-fast) var(--ease-standard);
  min-width: 28px;
  min-height: 28px;
  display: flex;
  align-items: center;
  justify-content: center;
}

.folder-row-wrapper:hover .btn-create-in-folder {
  opacity: 1;
}

@media (max-width: 768px) {
  .btn-create-in-folder {
    opacity: 1;
    min-width: 44px;
    min-height: 44px;
  }
}

.btn-create-in-folder:hover {
  color: var(--color-action);
  background: var(--color-hover);
}

.note-item {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding-top: var(--space-2);
  padding-bottom: var(--space-2);
  padding-right: var(--space-3);
  border-radius: var(--radius-sm);
  cursor: pointer;
  transition: background-color var(--duration-fast) var(--ease-standard),
              color var(--duration-fast) var(--ease-standard);
  color: var(--color-text-muted);
}

.note-item:hover {
  background: var(--color-hover);
  color: var(--color-text);
}

.note-item.active {
  background: color-mix(in srgb, var(--color-action) 20%, transparent);
  color: var(--color-action);
  font-weight: 600;
}

.note-icon {
  flex-shrink: 0;
  width: 16px;
  height: 16px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 0.875rem;
  line-height: 1;
}

.note-icon-fallback {
  color: var(--color-text-muted);
}

.note-info {
  display: flex;
  flex-direction: column;
  overflow: hidden;
}

.note-title {
  font-size: 0.875rem;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.note-path {
  font-size: 0.75rem;
  color: var(--color-text-muted);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.btn-delete {
  background: transparent;
  border: none;
  color: var(--color-text-muted);
  padding: var(--space-1);
  border-radius: var(--radius-sm);
  cursor: pointer;
  opacity: 0;
  flex-shrink: 0;
  transition: color var(--duration-fast) var(--ease-standard),
              background-color var(--duration-fast) var(--ease-standard),
              opacity var(--duration-fast) var(--ease-standard);
  min-width: 28px;
  min-height: 28px;
  display: flex;
  align-items: center;
  justify-content: center;
}

.note-item:hover .btn-delete {
  opacity: 1;
}

/* Touch devices have no hover state — the action must be visible and a
   full 44x44 touch target, not a 28px icon hidden behind a hover it can
   never receive. */
@media (max-width: 768px) {
  .btn-delete {
    opacity: 1;
    min-width: 44px;
    min-height: 44px;
  }
}

.btn-delete:hover {
  color: var(--color-status-danger);
  background: color-mix(in srgb, var(--color-status-danger) 12%, transparent);
}

.note-tree-ghost {
  background: var(--color-hover);
  opacity: 0.6;
}

.folder-row-highlight .folder-row {
  background: var(--color-hover);
}
</style>
