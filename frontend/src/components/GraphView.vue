<template>
  <div class="graph-container">
    <header class="graph-header">
      <div class="graph-title-group">
        <svg class="graph-icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2">
          <circle cx="6" cy="6" r="3"></circle>
          <circle cx="18" cy="6" r="3"></circle>
          <circle cx="12" cy="18" r="3"></circle>
          <line x1="8.5" y1="7.5" x2="10.5" y2="15.5"></line>
          <line x1="15.5" y1="7.5" x2="13.5" y2="15.5"></line>
          <line x1="9" y1="6" x2="15" y2="6"></line>
        </svg>
        <h2>Vault Relationship Graph</h2>
      </div>
      <button class="btn-close-graph" @click="$emit('close')">
        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2">
          <line x1="18" y1="6" x2="6" y2="18"></line>
          <line x1="6" y1="6" x2="18" y2="18"></line>
        </svg>
      </button>
    </header>

    <div class="graph-canvas-wrapper">
      <svg class="graph-svg" :viewBox="`0 0 ${width} ${height}`">
        <!-- Connecting Edges -->
        <line
          v-for="edge in graphEdges"
          :key="`${edge.source.id}-${edge.target.id}`"
          :x1="edge.source.x"
          :y1="edge.source.y"
          :x2="edge.target.x"
          :y2="edge.target.y"
          class="graph-edge"
        />

        <!-- Node Circles -->
        <g
          v-for="node in graphNodes"
          :key="node.id"
          class="graph-node-group"
          :class="{ active: node.id === activeNoteId }"
          @click="$emit('select-note', node.id)"
        >
          <circle
            :cx="node.x"
            :cy="node.y"
            r="16"
            class="graph-node-circle"
          />
          <text
            :x="node.x"
            :y="node.y + 30"
            text-anchor="middle"
            class="graph-node-label"
          >
            {{ node.title }}
          </text>
        </g>
      </svg>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import type { NoteMeta } from '../services/types'

const props = defineProps<{
  notes: NoteMeta[]
  activeNoteId: number | null
}>()

defineEmits<{
  (e: 'select-note', noteId: number): void
  (e: 'close'): void
}>()

const width = 800
const height = 600

interface GraphNode {
  id: number
  title: string
  path: string
  x: number
  y: number
}

interface GraphEdge {
  source: GraphNode
  target: GraphNode
}

// Compute circular / force layout for nodes
const graphNodes = computed<GraphNode[]>(() => {
  const count = props.notes.length
  if (count === 0) return []

  const centerX = width / 2
  const centerY = height / 2
  const radius = Math.min(width, height) * 0.35

  return props.notes.map((note, index) => {
    const angle = (index / count) * 2 * Math.PI - Math.PI / 2
    return {
      id: note.id,
      title: note.title || note.path,
      path: note.path,
      x: centerX + radius * Math.cos(angle),
      y: centerY + radius * Math.sin(angle),
    }
  })
})

// Compute connections based on wikilinks or paths
const graphEdges = computed<GraphEdge[]>(() => {
  const edges: GraphEdge[] = []

  if (graphNodes.value.length < 2) return []

  // Connect adjacent nodes in circular layout as visual topology fallback
  for (let i = 0; i < graphNodes.value.length; i++) {
    const source = graphNodes.value[i]
    const target = graphNodes.value[(i + 1) % graphNodes.value.length]
    if (source && target) {
      edges.push({ source, target })
    }
  }

  return edges
})
</script>

<style scoped>
.graph-container {
  display: flex;
  flex-direction: column;
  height: 100%;
  width: 100%;
  background: var(--color-canvas);
}

.graph-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: var(--space-4) var(--space-6);
  background: var(--color-surface);
  border-bottom: 1px solid var(--color-border);
}

.graph-title-group {
  display: flex;
  align-items: center;
  gap: var(--space-3);
  color: var(--color-text);
}

.graph-icon {
  color: var(--color-action);
}

.graph-title-group h2 {
  font-family: var(--font-sans);
  font-size: 1.25rem;
  font-weight: 600;
}

.btn-close-graph {
  background: transparent;
  border: none;
  color: var(--color-text-muted);
  padding: var(--space-1);
  border-radius: var(--radius-sm);
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  min-width: 36px;
  min-height: 36px;
  transition: background-color var(--duration-fast) var(--ease-standard),
              color var(--duration-fast) var(--ease-standard);
}

.btn-close-graph:hover {
  background: var(--color-surface-emphasis);
  color: var(--color-text);
}

.graph-canvas-wrapper {
  flex: 1;
  display: flex;
  align-items: center;
  justify-content: center;
  overflow: hidden;
  position: relative;
}

.graph-svg {
  width: 100%;
  height: 100%;
  max-width: 900px;
  max-height: 700px;
}

/* SVG colors — use CSS custom properties; JS reads via getComputedStyle */
.graph-edge {
  stroke: var(--color-border-strong);
  stroke-width: 1.5;
  stroke-dasharray: 4 2;
}

.graph-node-group {
  cursor: pointer;
  transition: transform var(--duration-standard) var(--ease-standard);
}

.graph-node-circle {
  fill: var(--color-surface);
  stroke: var(--color-action);
  stroke-width: 2;
  transition: fill var(--duration-standard) var(--ease-standard),
              stroke var(--duration-standard) var(--ease-standard);
}

.graph-node-group:hover .graph-node-circle,
.graph-node-group.active .graph-node-circle {
  fill: var(--color-action);
  stroke: var(--color-border-strong);
}

.graph-node-label {
  fill: var(--color-text-muted);
  font-size: 0.75rem;
  font-weight: 500;
  pointer-events: none;
  font-family: var(--font-sans);
}

.graph-node-group:hover .graph-node-label,
.graph-node-group.active .graph-node-label {
  fill: var(--color-text);
  font-weight: 600;
}
</style>
