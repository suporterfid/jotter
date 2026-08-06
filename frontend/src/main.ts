import { createApp } from 'vue'

/*
 * Deterministic stylesheet import order (Issue #105 / Spec §13):
 * 1. Self-hosted font declarations (@font-face)
 * 2. Semantic design tokens (:root variables)
 * 3. Base element reset and global defaults
 */
import './styles/fonts.css'
import './styles/tokens.css'
import './style.css'

import App from './App.vue'
import i18n from './i18n'

createApp(App).use(i18n).mount('#app')
