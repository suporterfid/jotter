<template>
  <div class="local-graph-panel">
    <div v-if="neighbors.length === 0" class="local-graph-empty">
      <p>{{ t('localGraphPanel.empty') }}</p>
    </div>
    <svg v-else class="local-graph-svg" :viewBox="`0 0 ${width} ${height}`">
      <line
        v-for="neighbor in positionedNeighbors"
        :key="`edge-${neighbor.id}`"
        :x1="centerX"
        :y1="centerY"
        :x2="neighbor.x"
        :y2="neighbor.y"
        class="local-graph-edge"
        :class="`local-graph-edge-${neighbor.direction}`"
      />

      <g class="local-graph-node-group local-graph-center" data-testid="local-graph-center">
        <circle :cx="centerX" :cy="centerY" r="20" class="local-graph-node-circle local-graph-center-circle" />
        <text :x="centerX" :y="centerY + 34" text-anchor="middle" class="local-graph-node-label">{{ centerTitle }}</text>
      </g>

      <g
        v-for="neighbor in positionedNeighbors"
        :key="neighbor.id"
        class="local-graph-node-group"
        data-testid="local-graph-neighbor"
        @click="$emit('select-neighbor', neighbor.id)"
      >
        <circle :cx="neighbor.x" :cy="neighbor.y" r="14" class="local-graph-node-circle" />
        <text :x="neighbor.x" :y="neighbor.y + 26" text-anchor="middle" class="local-graph-node-label">{{ neighbor.title }}</text>
      </g>
    </svg>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import type { LocalGraphNeighbor } from '../services/types'

const { t } = useI18n()

const props = defineProps<{
  centerTitle: string
  neighbors: LocalGraphNeighbor[]
}>()

defineEmits<{
  (e: 'select-neighbor', noteId: number): void
}>()

const width = 320
const height = 320
const centerX = width / 2
const centerY = height / 2
const radius = Math.min(width, height) * 0.35

const positionedNeighbors = computed(() => {
  const count = props.neighbors.length
  if (count === 0) return []
  return props.neighbors.map((neighbor, index) => {
    const angle = (index / count) * 2 * Math.PI - Math.PI / 2
    return {
      ...neighbor,
      x: centerX + radius * Math.cos(angle),
      y: centerY + radius * Math.sin(angle),
    }
  })
})
</script>

<style scoped>
.local-graph-panel {
  padding: var(--space-3) var(--space-4);
}

.local-graph-empty {
  color: var(--color-text-muted);
  font-size: 0.875rem;
}

.local-graph-svg {
  width: 100%;
  height: auto;
}

.local-graph-edge {
  stroke: var(--color-border-strong);
  stroke-width: 1.5;
}

.local-graph-edge-backlink {
  stroke-dasharray: none;
}

.local-graph-edge-outgoing {
  stroke-dasharray: 4 2;
}

.local-graph-node-group {
  cursor: pointer;
}

.local-graph-center {
  cursor: default;
}

.local-graph-node-circle {
  fill: var(--color-surface);
  stroke: var(--color-action);
  stroke-width: 2;
}

.local-graph-center-circle {
  fill: var(--color-action);
  stroke: var(--color-border-strong);
}

.local-graph-node-group:not(.local-graph-center):hover .local-graph-node-circle {
  fill: var(--color-action);
  stroke: var(--color-border-strong);
}

.local-graph-node-label {
  fill: var(--color-text);
  font-size: 0.75rem;
  font-family: var(--font-sans);
}
</style>
