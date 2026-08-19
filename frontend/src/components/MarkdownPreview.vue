<template>
  <div
    ref="rootEl"
    class="markdown-preview prose prose-invert"
    v-html="renderedContent"
    @click="handlePreviewClick"
    @change="handleCheckboxChange"
    @mouseover="handleWikilinkMouseOver"
    @mouseout="handleWikilinkMouseOut"
    @scroll="handleScroll"
  ></div>
</template>

<script setup lang="ts">
import { computed, onBeforeUnmount, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { renderMarkdown, type EmbedResolution } from '../services/markdown'

const { t } = useI18n()

const props = defineProps<{
  content: string
  resolveEmbed?: (target: string) => EmbedResolution
  headingIdPrefix?: string
}>()

const emit = defineEmits<{
  (e: 'navigate-wikilink', target: string): void
  (e: 'toggle-task', itemText: string, isChecked: boolean): void
  (e: 'hover-wikilink', target: string, rect: DOMRect): void
  (e: 'unhover-wikilink'): void
}>()

const rootEl = ref<HTMLElement | null>(null)

const renderedContent = computed(() => renderMarkdown(
  props.content || '',
  props.resolveEmbed,
  t('markdownPreview.copy'),
  props.headingIdPrefix,
))

function scrollToHeading(sourceSlug: string): void {
  const heading = Array.from(rootEl.value?.querySelectorAll<HTMLElement>('[data-heading-source]') ?? [])
    .find((element) => element.dataset.headingSource === sourceSlug)
  heading?.scrollIntoView({ behavior: 'smooth', block: 'start' })
}

defineExpose({ scrollToHeading })

function handlePreviewClick(event: MouseEvent) {
  const target = event.target as HTMLElement

  // Copy code button click
  const copyBtn = target.closest('.copy-code-btn') as HTMLButtonElement | null
  if (copyBtn) {
    const pre = copyBtn.closest('.code-block-wrapper')?.querySelector('pre')
    if (pre) {
      navigator.clipboard.writeText(pre.textContent || '')
      copyBtn.textContent = t('markdownPreview.copied')
      setTimeout(() => { copyBtn.textContent = t('markdownPreview.copy') }, 2000)
    }
    return
  }

  // Wikilink click
  const link = target.closest('a.wikilink') as HTMLAnchorElement | null
  if (link) {
    event.preventDefault()
    const wikilinkTarget = link.getAttribute('data-target')
    if (wikilinkTarget) {
      emit('navigate-wikilink', wikilinkTarget)
    }
  }
}

function handleCheckboxChange(event: Event) {
  const input = event.target as HTMLInputElement | null
  if (input && input.type === 'checkbox') {
    const li = input.closest('li')
    if (li) {
      const isChecked = input.checked
      const text = li.textContent?.trim() || ''
      emit('toggle-task', text, isChecked)
    }
  }
}

// Hover preview (G.2): debounced so a mouse merely passing over a link on
// its way elsewhere doesn't trigger a fetch — only a genuine pause does.
let hoverTimer: ReturnType<typeof setTimeout> | null = null

function clearHoverTimer() {
  if (hoverTimer) {
    clearTimeout(hoverTimer)
    hoverTimer = null
  }
}

function handleWikilinkMouseOver(event: MouseEvent) {
  const target = event.target as HTMLElement
  const link = target.closest('a.wikilink') as HTMLAnchorElement | null
  if (!link) return
  const wikilinkTarget = link.getAttribute('data-target')
  if (!wikilinkTarget) return

  clearHoverTimer()
  hoverTimer = setTimeout(() => {
    hoverTimer = null
    emit('hover-wikilink', wikilinkTarget, link.getBoundingClientRect())
  }, 300)
}

function handleWikilinkMouseOut(event: MouseEvent) {
  const target = event.target as HTMLElement
  const link = target.closest('a.wikilink') as HTMLAnchorElement | null
  if (!link) return

  clearHoverTimer()
  emit('unhover-wikilink')
}

function handleScroll() {
  clearHoverTimer()
  emit('unhover-wikilink')
}

onBeforeUnmount(clearHoverTimer)
</script>

<style scoped>
.markdown-preview {
  /* Centered reading column (Notion-style ~640-760px page), not full pane
     width — see docs/visual-identity.md §11, previously an open item. */
  max-inline-size: 720px;
  margin-block: 0;
  margin-inline: auto;
  padding-block: 1.5rem;
  padding-inline: 1.5rem;
  overflow-block: auto;
  color: var(--color-text);
  line-height: 1.6;
  block-size: 100%;
}

:deep(.wikilink) {
  color: var(--color-action);
  background: color-mix(in srgb, var(--color-action) 12%, transparent);
  padding: 0.1rem 0.35rem;
  border-radius: var(--radius-sm);
  text-decoration: none;
  font-weight: 500;
  border-bottom: 1px dashed var(--color-action);
  transition: background-color var(--duration-fast) var(--ease-standard),
              border-color var(--duration-fast) var(--ease-standard);
}

:deep(.wikilink:hover) {
  background: color-mix(in srgb, var(--color-action) 25%, transparent);
  border-bottom-style: solid;
}

:deep(h1) {
  font-size: 1.75rem;
  font-weight: 700;
  border-block-end: 1px solid var(--color-border);
  padding-block-end: var(--space-2);
  margin-block-start: var(--space-4);
  margin-block-end: var(--space-4);
  color: var(--color-text);
}

:deep(h2) {
  font-size: 1.35rem;
  font-weight: 600;
  margin-block-start: var(--space-6);
  margin-block-end: var(--space-3);
  color: var(--color-text);
}

:deep(p) {
  margin-block-end: var(--space-4);
}

:deep(ul), :deep(ol) {
  padding-inline-start: var(--space-6);
  margin-block-end: var(--space-4);
}

:deep(li) {
  margin-block-end: var(--space-1);
}

:deep(input[type="checkbox"]) {
  appearance: none;
  inline-size: 1.1rem;
  block-size: 1.1rem;
  border: 1px solid var(--color-border-strong);
  border-radius: var(--radius-sm);
  background: var(--color-canvas);
  cursor: pointer;
  vertical-align: middle;
  margin-inline-end: var(--space-2);
  position: relative;
  transition: background-color var(--duration-fast) var(--ease-standard),
              border-color var(--duration-fast) var(--ease-standard);
}

:deep(input[type="checkbox"]:checked) {
  background: var(--color-action);
  border-color: var(--color-action);
}

:deep(input[type="checkbox"]:checked::after) {
  content: '✓';
  position: absolute;
  inset-block-start: 50%;
  inset-inline-start: 50%;
  transform: translate(-50%, -50%);
  color: var(--color-neutral-0);
  font-size: 0.75rem;
  font-weight: bold;
}

:deep(code) {
  background: var(--color-surface-emphasis);
  padding: 0.2rem 0.4rem;
  border-radius: var(--radius-sm);
  font-family: var(--font-mono);
  font-size: 0.875em;
}

:deep(.code-block-wrapper) {
  position: relative;
  margin-block-end: var(--space-4);
}

:deep(.copy-code-btn) {
  position: absolute;
  inset-block-start: var(--space-2);
  inset-inline-end: var(--space-2);
  background: var(--color-surface-emphasis);
  border: 1px solid var(--color-border);
  color: var(--color-text-muted);
  padding: 0.2rem 0.5rem;
  border-radius: var(--radius-sm);
  font-size: 0.75rem;
  cursor: pointer;
  transition: background-color var(--duration-fast) var(--ease-standard),
              color var(--duration-fast) var(--ease-standard),
              border-color var(--duration-fast) var(--ease-standard);
  font-family: inherit;
  z-index: 10;
}

:deep(.copy-code-btn:hover) {
  background: color-mix(in srgb, var(--color-action) 30%, transparent);
  color: var(--color-text);
  border-color: var(--color-action);
}

:deep(pre) {
  background: var(--color-surface);
  padding-block: var(--space-4);
  padding-inline: var(--space-6);
  border-radius: var(--radius-md);
  overflow-inline: auto;
  margin: 0;
  border: 1px solid var(--color-border);
  color: var(--color-text);
  font-family: var(--font-mono);
}
</style>
