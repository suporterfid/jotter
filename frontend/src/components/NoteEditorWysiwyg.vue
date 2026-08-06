<template>
  <div ref="rootEl" class="wysiwyg-editor" data-testid="wysiwyg-editor" @mouseup="handleMouseUp"></div>

  <!-- Selection formatting toolbar: Notion-style, appears above a
       non-collapsed selection instead of living as permanent chrome —
       matches this surface's "no chrome unless needed" ethos (WY.5). -->
  <div
    v-if="selectionFormat"
    class="format-toolbar"
    data-testid="format-toolbar"
    :style="{ top: `${selectionFormat.top}px`, left: `${selectionFormat.left}px` }"
    @mousedown.prevent
  >
    <button
      type="button"
      class="format-btn"
      data-testid="format-bold"
      :class="{ active: selectionFormat.bold }"
      :title="t('wysiwyg.bold')"
      @click="toggleMark('bold')"
    ><strong>B</strong></button>
    <button
      type="button"
      class="format-btn"
      data-testid="format-italic"
      :class="{ active: selectionFormat.italic }"
      :title="t('wysiwyg.italic')"
      @click="toggleMark('italic')"
    ><em>I</em></button>
    <button
      type="button"
      class="format-btn"
      data-testid="format-strike"
      :class="{ active: selectionFormat.strike }"
      :title="t('wysiwyg.strikethrough')"
      @click="toggleMark('strike')"
    ><s>S</s></button>
    <button
      type="button"
      class="format-btn"
      data-testid="format-code"
      :class="{ active: selectionFormat.code }"
      :title="t('wysiwyg.inlineCode')"
      @click="toggleMark('code')"
    ><code>&lt;/&gt;</code></button>
  </div>
</template>

<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import {
  createWysiwygEditor,
  type SelectionFormat,
  type SlashQuery,
  type ToggleableMark,
  type WysiwygEditorHandle,
} from '../services/wysiwygEditor'
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
 * [[wikilink]], ![[embed]], > [!NOTE] callouts, and <details> toggles are
 * native nodes as of WY.3 (#323); `slash-query` re-points NoteEditor.vue's
 * existing SlashMenu.vue (#256) at this editor instead of the textarea.
 * `comment-trigger` does the same for the selection-driven "💬 Comment"
 * affordance (WY.4, #324), using a real document-structure anchor line
 * (wysiwygEditor.ts's getSelectionAnchorLine()) instead of the textarea's
 * selectionStart character-offset heuristic.
 */
const { t } = useI18n()

const props = defineProps<{
  content: string
}>()

const emit = defineEmits<{
  (e: 'update:content', content: string): void
  (e: 'slash-query', state: SlashQuery | null): void
  (e: 'comment-trigger', state: { line: number; top: number; left: number } | null): void
}>()

const rootEl = ref<HTMLElement | null>(null)
const selectionFormat = ref<SelectionFormat | null>(null)
let handle: WysiwygEditorHandle | null = null

let frontMatter = splitFrontMatter(props.content).frontMatter

// Guards against feeding our own emitted change back into setMarkdown()
// (which would reset the cursor/selection on every keystroke) when the
// content prop updates as a direct echo of what we just emitted.
let lastEmitted = props.content

onMounted(async () => {
  if (!rootEl.value) return
  const { body } = splitFrontMatter(props.content)
  handle = await createWysiwygEditor(
    rootEl.value,
    body,
    (markdown) => {
      const full = joinFrontMatter(frontMatter, markdown)
      lastEmitted = full
      emit('update:content', full)
    },
    (state) => emit('slash-query', state),
    (state) => { selectionFormat.value = state }
  )
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

/**
 * Lets NoteEditor.vue's SlashMenu (#256) insert a block without
 * string-splicing into a textarea that no longer exists in this mode —
 * blockRegistry.ts stays the single source of truth for each block's
 * markdown snippet either way. Replaces the triggering "/query" text when
 * called from a slash-query selection; otherwise inserts at the cursor.
 */
function insertBlock(markdown: string) {
  handle?.insertBlockReplacingSlashQuery(markdown)
}

defineExpose({ insertBlock })

function toggleMark(mark: ToggleableMark) {
  handle?.toggleMark(mark)
}

/**
 * Live mode's equivalent of NoteEditor.vue's handleTextSelection: a real
 * document-structure anchor line instead of a textarea coordinate
 * heuristic (WY.4, #324). Coordinates are computed relative to
 * .editor-body (the shared ancestor comment-trigger-btn/comment-composer
 * are positioned against), same as the textarea's own handler.
 */
function handleMouseUp(event: MouseEvent) {
  const line = handle?.getSelectionAnchorLine() ?? null
  if (line === null) {
    emit('comment-trigger', null)
    return
  }

  const wrapperEl = (event.currentTarget as HTMLElement).closest('.editor-body')
  if (!wrapperEl) return
  const wrapperRect = wrapperEl.getBoundingClientRect()
  emit('comment-trigger', {
    line,
    top: event.clientY - wrapperRect.top + 12,
    left: event.clientX - wrapperRect.left,
  })
}
</script>

<style scoped>
.format-toolbar {
  position: fixed;
  transform: translate(-50%, calc(-100% - 8px));
  z-index: 60;
  display: flex;
  gap: 2px;
  background: var(--color-surface);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-md);
  padding: var(--space-1);
  box-shadow: var(--shadow-float);
}

.format-btn {
  min-width: 2rem;
  height: 2rem;
  display: flex;
  align-items: center;
  justify-content: center;
  border: none;
  background: transparent;
  border-radius: var(--radius-sm);
  color: var(--color-text);
  cursor: pointer;
  font-size: 0.875rem;
}

.format-btn:hover {
  background: var(--color-surface-emphasis);
}

.format-btn.active {
  background: var(--color-action);
  color: var(--color-on-action, #fff);
}

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
