<template>
  <div 
    class="markdown-preview prose prose-invert" 
    v-html="renderedContent"
    @click="handlePreviewClick"
    @change="handleCheckboxChange"
  ></div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { renderMarkdown } from '../services/markdown'

const props = defineProps<{
  content: string
}>()

const emit = defineEmits<{
  (e: 'navigate-wikilink', target: string): void
  (e: 'toggle-task', itemText: string, isChecked: boolean): void
}>()

const renderedContent = computed(() => renderMarkdown(props.content || ''))

function handlePreviewClick(event: MouseEvent) {
  const target = event.target as HTMLElement

  // Copy code button click
  const copyBtn = target.closest('.copy-code-btn') as HTMLButtonElement | null
  if (copyBtn) {
    const pre = copyBtn.closest('.code-block-wrapper')?.querySelector('pre')
    if (pre) {
      navigator.clipboard.writeText(pre.textContent || '')
      copyBtn.textContent = 'Copied!'
      setTimeout(() => { copyBtn.textContent = 'Copy' }, 2000)
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
</script>

<style scoped>
.markdown-preview {
  /* Centered reading column (Notion-style ~640-760px page), not full pane
     width — see docs/visual-identity.md §11, previously an open item. */
  max-width: 760px;
  margin: 0 auto;
  padding: 1.5rem;
  overflow-y: auto;
  color: var(--color-text);
  line-height: 1.6;
  height: 100%;
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
  border-bottom: 1px solid var(--color-border);
  padding-bottom: var(--space-2);
  margin-top: var(--space-4);
  margin-bottom: var(--space-4);
  color: var(--color-text);
}

:deep(h2) {
  font-size: 1.35rem;
  font-weight: 600;
  margin-top: var(--space-6);
  margin-bottom: var(--space-3);
  color: var(--color-text);
}

:deep(p) {
  margin-bottom: var(--space-4);
}

:deep(ul), :deep(ol) {
  padding-left: var(--space-6);
  margin-bottom: var(--space-4);
}

:deep(li) {
  margin-bottom: var(--space-1);
}

:deep(input[type="checkbox"]) {
  appearance: none;
  width: 1.1rem;
  height: 1.1rem;
  border: 1px solid var(--color-border-strong);
  border-radius: var(--radius-sm);
  background: var(--color-canvas);
  cursor: pointer;
  vertical-align: middle;
  margin-right: var(--space-2);
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
  top: 50%;
  left: 50%;
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
  margin-bottom: var(--space-4);
}

:deep(.copy-code-btn) {
  position: absolute;
  top: var(--space-2);
  right: var(--space-2);
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
  padding: var(--space-4) var(--space-6);
  border-radius: var(--radius-md);
  overflow-x: auto;
  margin: 0;
  border: 1px solid var(--color-border);
  color: var(--color-text);
  font-family: var(--font-mono);
}
</style>

