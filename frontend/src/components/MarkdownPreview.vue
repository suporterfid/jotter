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
  padding: 1.5rem;
  overflow-y: auto;
  color: var(--text-main, #e2e8f0);
  line-height: 1.6;
  height: 100%;
}

:deep(.wikilink) {
  color: var(--accent-color, #818cf8);
  background: rgba(129, 140, 248, 0.12);
  padding: 0.1rem 0.35rem;
  border-radius: 0.25rem;
  text-decoration: none;
  font-weight: 500;
  border-bottom: 1px dashed var(--accent-color, #818cf8);
  transition: all 0.15s ease;
}

:deep(.wikilink:hover) {
  background: rgba(129, 140, 248, 0.25);
  border-bottom-style: solid;
}

:deep(h1) {
  font-size: 1.75rem;
  font-weight: 700;
  border-bottom: 1px solid var(--border-color, #2d2d38);
  padding-bottom: 0.5rem;
  margin-top: 1rem;
  margin-bottom: 1rem;
  color: var(--text-light, #f8fafc);
}

:deep(h2) {
  font-size: 1.35rem;
  font-weight: 600;
  margin-top: 1.25rem;
  margin-bottom: 0.75rem;
  color: var(--text-light, #f8fafc);
}

:deep(p) {
  margin-bottom: 1rem;
}

:deep(ul), :deep(ol) {
  padding-left: 1.5rem;
  margin-bottom: 1rem;
}

:deep(li) {
  margin-bottom: 0.35rem;
}

:deep(input[type="checkbox"]) {
  appearance: none;
  width: 1.1rem;
  height: 1.1rem;
  border: 1px solid rgba(255, 255, 255, 0.25);
  border-radius: 0.25rem;
  background: rgba(255, 255, 255, 0.05);
  cursor: pointer;
  vertical-align: middle;
  margin-right: 0.5rem;
  position: relative;
  transition: all 0.15s ease;
}

:deep(input[type="checkbox"]:checked) {
  background: var(--accent-color, #6366f1);
  border-color: var(--accent-color, #6366f1);
}

:deep(input[type="checkbox"]:checked::after) {
  content: '✓';
  position: absolute;
  top: 50%;
  left: 50%;
  transform: translate(-50%, -50%);
  color: #ffffff;
  font-size: 0.75rem;
  font-weight: bold;
}

:deep(code) {
  background: rgba(255, 255, 255, 0.08);
  padding: 0.2rem 0.4rem;
  border-radius: 0.25rem;
  font-family: monospace;
  font-size: 0.875em;
}

:deep(.code-block-wrapper) {
  position: relative;
  margin-bottom: 1.25rem;
}

:deep(.copy-code-btn) {
  position: absolute;
  top: 0.5rem;
  right: 0.5rem;
  background: rgba(255, 255, 255, 0.08);
  border: 1px solid rgba(255, 255, 255, 0.12);
  color: #94a3b8;
  padding: 0.2rem 0.5rem;
  border-radius: 0.25rem;
  font-size: 0.75rem;
  cursor: pointer;
  transition: all 0.15s ease;
  font-family: inherit;
  z-index: 10;
}

:deep(.copy-code-btn:hover) {
  background: rgba(99, 102, 241, 0.3);
  color: #f8fafc;
  border-color: #818cf8;
}

:deep(pre) {
  background: #0d1117;
  padding: 1rem 1.25rem;
  border-radius: 0.5rem;
  overflow-x: auto;
  margin: 0;
  border: 1px solid rgba(255, 255, 255, 0.08);
  color: #e6edf3;
  font-family: 'Fira Code', 'Cascadia Code', Consolas, monospace;
}
</style>
