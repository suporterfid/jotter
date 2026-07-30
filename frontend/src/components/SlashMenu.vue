<template>
  <div
    v-if="isOpen"
    class="slash-menu-overlay"
    @click.self="close"
  >
    <div
      class="slash-menu-container"
      role="menu"
      aria-label="Slash commands"
    >
      <div class="slash-menu-header">Insert Block</div>
      <ul class="slash-menu-list">
        <li
          v-for="(block, index) in filteredBlocks"
          :key="block.key"
          :class="['slash-menu-item', { selected: index === selectedIndex }]"
          role="menuitem"
          @click="select(block.def)"
          @mouseenter="selectedIndex = index"
        >
          <span class="slash-icon">{{ block.def.slash_menu.icon }}</span>
          <div class="slash-label-group">
            <span class="slash-label">{{ block.def.slash_menu.label }}</span>
            <span class="slash-syntax"><code>{{ block.def.syntax.slice(0, 30) }}</code></span>
          </div>
        </li>
        <li v-if="filteredBlocks.length === 0" class="slash-no-results">
          No matching blocks
        </li>
      </ul>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import type { BlockDefinition } from '../services/blockRegistry'
import { blockDefinitions } from '../services/blockRegistry'

const props = defineProps<{
  isOpen: boolean
  filterQuery: string
}>()

const emit = defineEmits<{
  (e: 'select', block: BlockDefinition): void
  (e: 'close'): void
}>()

const selectedIndex = ref(0)

const filteredBlocks = computed(() => {
  const q = props.filterQuery.toLowerCase().trim()
  return Object.entries(blockDefinitions)
    .filter(([_, def]) => {
      if (!q) return true
      return def.name.toLowerCase().includes(q) || def.slash_menu.label.toLowerCase().includes(q)
    })
    .map(([key, def]) => ({ key, def }))
})

watch(() => props.filterQuery, () => {
  selectedIndex.value = 0
})

function close() {
  emit('close')
}

function select(block: BlockDefinition) {
  emit('select', block)
}

function handleKeyDown(e: KeyboardEvent) {
  if (!props.isOpen) return

  if (e.key === 'ArrowDown') {
    e.preventDefault()
    if (filteredBlocks.value.length > 0) {
      selectedIndex.value = (selectedIndex.value + 1) % filteredBlocks.value.length
    }
  } else if (e.key === 'ArrowUp') {
    e.preventDefault()
    if (filteredBlocks.value.length > 0) {
      selectedIndex.value = (selectedIndex.value - 1 + filteredBlocks.value.length) % filteredBlocks.value.length
    }
  } else if (e.key === 'Enter') {
    e.preventDefault()
    if (filteredBlocks.value[selectedIndex.value]) {
      select(filteredBlocks.value[selectedIndex.value].def)
    }
  } else if (e.key === 'Escape') {
    e.preventDefault()
    close()
  }
}

defineExpose({ handleKeyDown })
</script>

<style scoped>
.slash-menu-overlay {
  position: absolute;
  z-index: 1000;
}
.slash-menu-container {
  background: var(--color-surface);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-md);
  width: min(90vw, 260px);
  box-shadow: var(--shadow-float);
  color: var(--color-text);
  overflow: hidden;
}
.slash-menu-header {
  font-size: 0.75rem;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  color: var(--color-text-muted);
  padding: var(--space-2) var(--space-3);
  border-bottom: 1px solid var(--color-border);
  background: var(--color-surface-emphasis);
}
.slash-menu-list {
  list-style: none;
  margin: 0;
  padding: var(--space-1) 0;
  max-height: 220px;
  overflow-y: auto;
}
.slash-menu-item {
  display: flex;
  align-items: center;
  gap: var(--space-2);
  padding: var(--space-2) var(--space-3);
  cursor: pointer;
  color: var(--color-text-muted);
  transition: background-color var(--duration-fast) var(--ease-standard),
              color var(--duration-fast) var(--ease-standard);
}
.slash-menu-item.selected, .slash-menu-item:hover {
  background: color-mix(in srgb, var(--color-action) 20%, transparent);
  color: var(--color-text);
}
.slash-icon {
  font-size: 1rem;
}
.slash-label-group {
  display: flex;
  flex-direction: column;
}
.slash-label {
  font-size: 0.9rem;
  font-weight: 500;
}
.slash-syntax {
  font-size: 0.75rem;
  color: var(--color-text-muted);
}
.slash-no-results {
  padding: var(--space-3);
  font-size: 0.85rem;
  color: var(--color-text-muted);
  text-align: center;
}
</style>

