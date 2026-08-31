<template>
  <img
    v-if="brand.logo_url"
    :src="brand.logo_url"
    :alt="brand.name"
    class="brand-logo"
    :width="size"
    :height="size"
    data-testid="brand-logo"
  />
  <svg
    v-else
    class="brand-icon"
    viewBox="0 0 24 24"
    :width="size"
    :height="size"
    fill="none"
    stroke="currentColor"
    stroke-width="2"
    aria-hidden="true"
    data-testid="brand-mark"
  >
    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
    <polyline points="14 2 14 8 20 8"></polyline>
    <line x1="16" y1="13" x2="8" y2="13"></line>
    <line x1="16" y1="17" x2="8" y2="17"></line>
    <polyline points="10 9 9 9 8 9"></polyline>
  </svg>
</template>

<script setup lang="ts">
import { brand } from '../services/brand'

withDefaults(defineProps<{ size?: number }>(), { size: 22 })
</script>

<style scoped>
.brand-logo {
  display: block;
  object-fit: contain;
}

.brand-icon {
  color: var(--color-action);
}
</style>
