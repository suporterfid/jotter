import { config, enableAutoUnmount } from '@vue/test-utils'
import { afterEach, beforeEach } from 'vitest'
import i18n from './i18n'

// Reuse the app's actual i18n singleton (not a separate instance) so tests that
// import `i18n` directly observe the same locale ref that mounted components
// read/write through useI18n(). Force English so none of the existing spec
// files' rendered-text assertions need to change.
i18n.global.locale.value = 'en'

config.global.plugins.push(i18n)
enableAutoUnmount(afterEach)

function removeRightDrawerTarget() {
  document.getElementById('app-right-drawer')?.remove()
  document.getElementById('app-right-drawer-primary')?.remove()
  document.getElementById('app-right-drawer-secondary')?.remove()
}

beforeEach(() => {
  removeRightDrawerTarget()

  const target = document.createElement('div')
  target.id = 'app-right-drawer'
  document.body.append(target)

  for (const id of ['app-right-drawer-primary', 'app-right-drawer-secondary']) {
    const paneTarget = document.createElement('div')
    paneTarget.id = id
    document.body.append(paneTarget)
  }
})

afterEach(() => {
  removeRightDrawerTarget()
})
