<template>
  <div
    ref="popupRef"
    class="wikilink-preview-popup"
    data-testid="wikilink-preview-popup"
    :style="positionStyle"
  >
    <div v-if="unresolvedTarget" class="wikilink-preview-unresolved">
      {{ t('wikilinkPreview.noteNotYet', { target: unresolvedTarget }) }}
    </div>
    <div v-else-if="content === null" class="wikilink-preview-loading">
      {{ t('wikilinkPreview.loading') }}
    </div>
    <div v-else class="wikilink-preview-content" v-html="renderedContent"></div>
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { renderMarkdown } from '../services/markdown'
import type { NoteMeta } from '../services/types'

const { t } = useI18n()

const props = defineProps<{
  rect: DOMRect
  note: NoteMeta | null
  content: string | null
  unresolvedTarget: string | null
}>()

const popupRef = ref<HTMLElement | null>(null)
const positionStyle = ref({
  top: `${props.rect.bottom + 4}px`,
  left: `${props.rect.left}px`,
})

const renderedContent = computed(() => (props.content ? renderMarkdown(props.content, undefined, t('markdownPreview.copy')) : ''))

onMounted(() => {
  const el = popupRef.value
  if (!el) return
  const width = el.offsetWidth
  let left = props.rect.left
  if (left + width > window.innerWidth) {
    left = props.rect.right - width
  }
  positionStyle.value = { top: `${props.rect.bottom + 4}px`, left: `${left}px` }
})
</script>

<style scoped>
.wikilink-preview-popup {
  position: fixed;
  z-index: 50;
  width: 320px;
  background: var(--color-surface);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-md);
  box-shadow: var(--shadow-float);
  padding: var(--space-3);
}

.wikilink-preview-content {
  max-height: 200px;
  overflow: hidden;
  font-size: 0.875rem;
  -webkit-mask-image: linear-gradient(to bottom, black 80%, transparent 100%);
  mask-image: linear-gradient(to bottom, black 80%, transparent 100%);
}

.wikilink-preview-loading,
.wikilink-preview-unresolved {
  color: var(--color-text-muted);
  font-size: 0.875rem;
}
</style>
