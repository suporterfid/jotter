<template>
  <div ref="rootEl" class="wysiwyg-editor" data-testid="wysiwyg-editor"></div>
</template>

<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { createWysiwygEditor, type WysiwygEditorHandle } from '../services/wysiwygEditor'
import { splitFrontMatter, joinFrontMatter } from '../services/frontMatterGuard'

/**
 * WY.2 (#322): additive "live" view mode, standard CommonMark+GFM nodes
 * only. Reuses NoteEditor.vue's existing content/autosave contract instead
 * of forking it — this component only translates between Milkdown's
 * imperative editor and a plain `content` prop / `update:content` emit, the
 * same v-model shape a <textarea> would use, so the parent's existing
 * `watch(editableContent, ...)` debounce and Ctrl+S flush (#254) work
 * completely unchanged.
 *
 * Front matter is stripped before it ever reaches Milkdown and reattached
 * unchanged on every emit — CommonMark has no front matter concept and
 * destroys it (see wysiwygRoundTrip.spec.ts's "front matter" known gap).
 *
 * [[wikilink]], ![[embed]], and > [!NOTE] callouts have no native node yet
 * (WY.3, #323) — they pass through as plain text, which WY.1's round-trip
 * harness already proves is safe.
 */
const props = defineProps<{
  content: string
}>()

const emit = defineEmits<{
  (e: 'update:content', content: string): void
}>()

const rootEl = ref<HTMLElement | null>(null)
let handle: WysiwygEditorHandle | null = null

let frontMatter = splitFrontMatter(props.content).frontMatter

// Guards against feeding our own emitted change back into setMarkdown()
// (which would reset the cursor/selection on every keystroke) when the
// content prop updates as a direct echo of what we just emitted.
let lastEmitted = props.content

onMounted(async () => {
  if (!rootEl.value) return
  const { body } = splitFrontMatter(props.content)
  handle = await createWysiwygEditor(rootEl.value, body, (markdown) => {
    const full = joinFrontMatter(frontMatter, markdown)
    lastEmitted = full
    emit('update:content', full)
  })
})

watch(() => props.content, (newContent) => {
  if (newContent !== lastEmitted) {
    lastEmitted = newContent
    const split = splitFrontMatter(newContent)
    frontMatter = split.frontMatter
    handle?.setMarkdown(split.body)
  }
})

onBeforeUnmount(() => {
  handle?.destroy()
  handle = null
})
</script>

<style scoped>
.wysiwyg-editor {
  flex: 1;
  min-height: 0;
  overflow-y: auto;
  padding: var(--space-4);
  font-family: var(--font-sans);
  font-size: 0.95rem;
  line-height: 1.6;
  color: var(--color-text);
}

.wysiwyg-editor :deep(.milkdown) {
  outline: none;
}

.wysiwyg-editor :deep(h1),
.wysiwyg-editor :deep(h2),
.wysiwyg-editor :deep(h3),
.wysiwyg-editor :deep(h4),
.wysiwyg-editor :deep(h5),
.wysiwyg-editor :deep(h6) {
  font-family: var(--font-sans);
  color: var(--color-text);
  margin-top: var(--space-4);
  margin-bottom: var(--space-2);
}

.wysiwyg-editor :deep(p) {
  margin-bottom: var(--space-3);
}

.wysiwyg-editor :deep(code) {
  font-family: var(--font-mono, monospace);
  background: var(--color-surface-emphasis);
  border-radius: var(--radius-sm);
  padding: 0.1rem 0.3rem;
}

.wysiwyg-editor :deep(pre) {
  font-family: var(--font-mono, monospace);
  background: var(--color-surface-emphasis);
  border-radius: var(--radius-sm);
  padding: var(--space-3);
  overflow-x: auto;
}

.wysiwyg-editor :deep(pre code) {
  background: none;
  padding: 0;
}

.wysiwyg-editor :deep(table) {
  border-collapse: collapse;
  width: 100%;
  margin-bottom: var(--space-3);
}

.wysiwyg-editor :deep(th),
.wysiwyg-editor :deep(td) {
  border: 1px solid var(--color-border);
  padding: var(--space-1) var(--space-2);
}

.wysiwyg-editor :deep(blockquote) {
  border-left: 3px solid var(--color-border);
  margin-left: 0;
  padding-left: var(--space-3);
  color: var(--color-text-muted);
}
</style>
