<template>
  <nav v-if="links.length > 0 || brand.powered_by" class="brand-footer" data-testid="brand-footer" :aria-label="t('brand.footerLabel')">
    <a
      v-for="link in links"
      :key="link.key"
      :href="link.href"
      target="_blank"
      rel="noopener"
      class="brand-footer-link"
      :data-testid="`brand-link-${link.key}`"
    >
      {{ t(`brand.${link.key}`) }}
    </a>
    <a
      v-if="brand.powered_by"
      :href="brand.powered_by_url"
      target="_blank"
      rel="noopener"
      class="brand-footer-link brand-powered-by"
      data-testid="brand-powered-by"
    >
      {{ t('brand.poweredBy') }}
    </a>
  </nav>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { brand, brandLinks } from '../services/brand'

const { t } = useI18n()
const links = computed(() => brandLinks(brand))
</script>

<style scoped>
.brand-footer {
  display: flex;
  flex-wrap: wrap;
  justify-content: center;
  gap: var(--space-1) var(--space-3);
  padding: 0 var(--space-4) var(--space-2);
  font-size: 0.75rem;
}

.brand-footer-link {
  color: var(--color-text-muted);
  text-decoration: none;
}

.brand-footer-link:hover,
.brand-footer-link:focus-visible {
  color: var(--color-text);
  text-decoration: underline;
}
</style>
