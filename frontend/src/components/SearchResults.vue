<template>
  <div class="search-results-panel">
    <div class="search-header">
      <span>{{ t('searchResults.headingFor', { query }) }}</span>
      <span class="count">{{ t('searchResults.matches', { count: results.length }) }}</span>
    </div>

    <div class="filter-bar">
      <input
        v-model="titleDraft"
        type="text"
        class="filter-input"
        :placeholder="t('searchResults.filterByTitlePlaceholder')"
        :aria-label="t('searchResults.filterByTitle')"
        data-testid="search-filter-title"
        @change="emitFilters"
      />

      <input
        v-model="modifiedAfterDraft"
        type="date"
        class="filter-input"
        :aria-label="t('searchResults.modifiedAfter')"
        data-testid="search-filter-modified-after"
        @change="emitFilters"
      />
      <span class="filter-range-sep" aria-hidden="true">–</span>
      <input
        v-model="modifiedBeforeDraft"
        type="date"
        class="filter-input"
        :aria-label="t('searchResults.modifiedBefore')"
        data-testid="search-filter-modified-before"
        @change="emitFilters"
      />

      <div v-if="availableTags.length > 0" class="filter-tags">
        <button
          v-for="tag in availableTags"
          :key="tag"
          type="button"
          class="tag-pill"
          :class="{ active: selectedTags.includes(tag) }"
          data-testid="search-filter-tag"
          @click="toggleTag(tag)"
        >
          #{{ tag }}
        </button>
      </div>

      <button
        v-if="hasActiveFilters"
        type="button"
        class="clear-filters-btn"
        data-testid="search-filter-clear"
        @click="clearFilters"
      >
        {{ t('searchResults.clearFilters') }}
      </button>
    </div>

    <div v-if="results.length === 0" class="no-results">
      {{ t('searchResults.noResults') }}
    </div>

    <div v-else class="results-list">
      <div
        v-for="item in results"
        :key="item.note_id"
        class="result-card"
        @click="$emit('select-note', item.note_id)"
      >
        <div class="result-title">{{ item.title || item.path }}</div>
        <div class="result-path">{{ item.path }}</div>
        <div v-if="item.snippet" class="result-snippet" v-html="item.snippet"></div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import type { SearchResult, SearchFilters } from '../services/types'

const { t } = useI18n()

const props = defineProps<{
  query: string
  results: SearchResult[]
  filters: SearchFilters
  availableTags: string[]
}>()

const emit = defineEmits<{
  (e: 'select-note', noteId: number): void
  (e: 'update:filters', filters: SearchFilters): void
}>()

const titleDraft = ref(props.filters.title ?? '')
const modifiedAfterDraft = ref(props.filters.modifiedAfter ?? '')
const modifiedBeforeDraft = ref(props.filters.modifiedBefore ?? '')
const selectedTags = ref<string[]>([...(props.filters.tags ?? [])])

watch(
  () => props.filters,
  (filters) => {
    titleDraft.value = filters.title ?? ''
    modifiedAfterDraft.value = filters.modifiedAfter ?? ''
    modifiedBeforeDraft.value = filters.modifiedBefore ?? ''
    selectedTags.value = [...(filters.tags ?? [])]
  }
)

const hasActiveFilters = computed(() =>
  Boolean(
    titleDraft.value.trim() ||
    modifiedAfterDraft.value ||
    modifiedBeforeDraft.value ||
    selectedTags.value.length > 0
  )
)

function emitFilters() {
  emit('update:filters', {
    title: titleDraft.value.trim() || undefined,
    modifiedAfter: modifiedAfterDraft.value || undefined,
    modifiedBefore: modifiedBeforeDraft.value || undefined,
    tags: selectedTags.value.length > 0 ? [...selectedTags.value] : undefined
  })
}

function toggleTag(tag: string) {
  const idx = selectedTags.value.indexOf(tag)
  if (idx === -1) {
    selectedTags.value.push(tag)
  } else {
    selectedTags.value.splice(idx, 1)
  }
  emitFilters()
}

function clearFilters() {
  titleDraft.value = ''
  modifiedAfterDraft.value = ''
  modifiedBeforeDraft.value = ''
  selectedTags.value = []
  emitFilters()
}
</script>

<style scoped>
.search-results-panel {
  padding: var(--space-4);
  background: var(--color-canvas);
  height: 100%;
  overflow-y: auto;
}

.search-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  font-weight: 600;
  color: var(--color-text-muted);
  font-size: 0.875rem;
  margin-bottom: var(--space-4);
  padding-bottom: var(--space-2);
  border-bottom: 1px solid var(--color-border);
}

.count {
  background: var(--color-surface-emphasis);
  color: var(--color-action);
  padding: 0.125rem 0.5rem;
  border-radius: var(--radius-pill);
  font-size: 0.75rem;
}

.filter-bar {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: var(--space-2);
  margin-bottom: var(--space-4);
  padding-bottom: var(--space-3);
  border-bottom: 1px solid var(--color-border);
}

.filter-input {
  background: var(--color-surface);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-sm);
  padding: 0.375rem 0.625rem;
  font-size: 0.8125rem;
  color: var(--color-text);
  min-height: 36px;
}

.filter-input:focus-visible {
  outline: 2px solid var(--color-focus);
  outline-offset: 1px;
}

.filter-range-sep {
  color: var(--color-text-muted);
}

.filter-tags {
  display: flex;
  flex-wrap: wrap;
  gap: var(--space-1);
}

.tag-pill {
  background: var(--color-surface);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-pill);
  padding: 0.25rem 0.625rem;
  font-size: 0.75rem;
  color: var(--color-text-muted);
  cursor: pointer;
  min-height: 36px;
}

.tag-pill.active {
  background: var(--color-action);
  color: var(--color-text-inverse);
  border-color: var(--color-action);
}

.clear-filters-btn {
  background: transparent;
  border: 1px solid var(--color-border);
  border-radius: var(--radius-sm);
  padding: 0.375rem 0.625rem;
  font-size: 0.75rem;
  color: var(--color-text-muted);
  cursor: pointer;
  min-height: 36px;
}

.clear-filters-btn:hover {
  color: var(--color-text);
  border-color: var(--color-text-muted);
}

.no-results {
  color: var(--color-text-muted);
  padding: var(--space-8) 0;
  text-align: center;
}

.results-list {
  display: flex;
  flex-direction: column;
  gap: var(--space-3);
}

.result-card {
  background: var(--color-surface);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-md);
  padding: var(--space-3);
  cursor: pointer;
  transition: border-color var(--duration-fast) var(--ease-standard),
              background-color var(--duration-fast) var(--ease-standard);
}

.result-card:hover {
  background: var(--color-surface-emphasis);
  border-color: var(--color-action);
}

.result-title {
  font-weight: 600;
  color: var(--color-text);
  font-size: 1rem;
}

.result-path {
  font-size: 0.75rem;
  color: var(--color-text-muted);
  margin-top: 0.125rem;
  margin-bottom: var(--space-2);
}

.result-snippet {
  font-size: 0.875rem;
  color: var(--color-text-muted);
  line-height: 1.4;
  background: var(--color-canvas);
  padding: var(--space-2);
  border-radius: var(--radius-sm);
}
</style>
