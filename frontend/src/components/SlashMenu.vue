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
import { BlockDefinition, blockDefinitions } from '../services/blockRegistry'

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
  background: #1e1e2e;
  border: 1px solid #45475a;
  border-radius: 6px;
  width: 260px;
  box-shadow: 0 4px 12px rgba(0, 0, 0, 0.4);
  color: #cdd6f4;
  overflow: hidden;
}
.slash-menu-header {
  font-size: 0.75rem;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  color: #a6adc8;
  padding: 8px 12px;
  border-bottom: 1px solid #313244;
}
.slash-menu-list {
  list-style: none;
  margin: 0;
  padding: 4px 0;
  max-height: 220px;
  overflow-y: auto;
}
.slash-menu-item {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 8px 12px;
  cursor: pointer;
}
.slash-menu-item.selected {
  background: #313244;
  color: #cba6f7;
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
  color: #a6adc8;
}
.slash-no-results {
  padding: 10px 12px;
  font-size: 0.85rem;
  color: #a6adc8;
  text-align: center;
}
</style>
