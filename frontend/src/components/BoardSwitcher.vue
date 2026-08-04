<template>
  <div class="board-switcher">
    <select
      class="board-switcher-select"
      data-testid="board-switcher-select"
      :value="activeBoardId ?? ''"
      aria-label="Switch board"
      @change="handleSelect"
    >
      <option value="">Unsaved view</option>
      <option v-for="board in boards" :key="board.id" :value="board.id">{{ board.name }}</option>
    </select>

    <form
      v-if="isCreating"
      class="board-name-form"
      data-testid="board-new-form"
      @submit.prevent="submitCreate"
    >
      <input
        v-model="draftName"
        type="text"
        placeholder="Board name"
        aria-label="New board name"
        data-testid="board-new-input"
        class="board-name-input"
        autofocus
      />
      <button type="submit" class="btn-board-confirm">Create</button>
      <button type="button" class="btn-board-cancel" @click="isCreating = false">Cancel</button>
    </form>
    <button v-else type="button" class="btn-board-action" data-testid="board-new-button" @click="startCreate">
      + New board
    </button>

    <template v-if="activeBoardId !== null">
      <form
        v-if="isRenaming"
        class="board-name-form"
        data-testid="board-rename-form"
        @submit.prevent="submitRename"
      >
        <input
          v-model="draftName"
          type="text"
          placeholder="Board name"
          aria-label="Rename board"
          data-testid="board-rename-input"
          class="board-name-input"
          autofocus
        />
        <button type="submit" class="btn-board-confirm">Save</button>
        <button type="button" class="btn-board-cancel" @click="isRenaming = false">Cancel</button>
      </form>
      <button v-else type="button" class="btn-board-action" data-testid="board-rename-button" @click="startRename">
        Rename
      </button>
      <button
        type="button"
        class="btn-board-action btn-board-delete"
        data-testid="board-delete-button"
        @click="$emit('delete-board', activeBoardId)"
      >
        Delete
      </button>
    </template>
  </div>
</template>

<script setup lang="ts">
import { ref } from 'vue'
import type { Board } from '../services/types'

const props = defineProps<{
  boards: Board[]
  activeBoardId: number | null
}>()

const emit = defineEmits<{
  (e: 'select-board', boardId: number | null): void
  (e: 'create-board', name: string): void
  (e: 'rename-board', boardId: number, name: string): void
  (e: 'delete-board', boardId: number): void
}>()

const isCreating = ref(false)
const isRenaming = ref(false)
const draftName = ref('')

function handleSelect(event: Event) {
  const value = (event.target as HTMLSelectElement).value
  emit('select-board', value === '' ? null : Number(value))
}

function startCreate() {
  isCreating.value = true
  draftName.value = ''
}

function submitCreate() {
  const name = draftName.value.trim()
  if (!name) return
  emit('create-board', name)
  isCreating.value = false
  draftName.value = ''
}

function startRename() {
  const current = props.boards.find(b => b.id === props.activeBoardId)
  draftName.value = current?.name ?? ''
  isRenaming.value = true
}

function submitRename() {
  const name = draftName.value.trim()
  if (!name || props.activeBoardId === null) return
  emit('rename-board', props.activeBoardId, name)
  isRenaming.value = false
  draftName.value = ''
}
</script>

<style scoped>
.board-switcher {
  display: flex;
  align-items: center;
  gap: var(--space-2);
  flex-wrap: wrap;
  margin-bottom: var(--space-2);
}

.board-switcher-select {
  background: var(--color-canvas);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-sm);
  padding: var(--space-1) var(--space-2);
  color: var(--color-text);
  font-size: 0.8125rem;
  min-height: 32px;
}

.board-name-form {
  display: flex;
  align-items: center;
  gap: var(--space-1);
}

.board-name-input {
  background: var(--color-canvas);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-sm);
  padding: var(--space-1) var(--space-2);
  color: var(--color-text);
  font-size: 0.8125rem;
  min-height: 32px;
}

.btn-board-action,
.btn-board-confirm,
.btn-board-cancel {
  background: var(--color-surface-emphasis);
  color: var(--color-text-muted);
  border: none;
  padding: var(--space-1) var(--space-3);
  border-radius: var(--radius-sm);
  cursor: pointer;
  font-size: 0.8125rem;
  min-height: 32px;
}

.btn-board-confirm {
  background: var(--color-action);
  color: var(--color-neutral-0);
}

.btn-board-delete:hover {
  background: var(--color-danger, #d33);
  color: var(--color-neutral-0);
}
</style>
