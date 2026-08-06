import { config } from '@vue/test-utils'
import i18n from './i18n'

// Reuse the app's actual i18n singleton (not a separate instance) so tests that
// import `i18n` directly observe the same locale ref that mounted components
// read/write through useI18n(). Force English so none of the existing spec
// files' rendered-text assertions need to change.
i18n.global.locale.value = 'en'

config.global.plugins.push(i18n)
