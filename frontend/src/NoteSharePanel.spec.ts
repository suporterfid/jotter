import { mount } from '@vue/test-utils'
import { describe, expect, it, vi, beforeEach } from 'vitest'
import i18n from './i18n'
import NoteSharePanel from './components/NoteSharePanel.vue'
import type { NoteShareState } from './services/types'

const inactive: NoteShareState = {
  active: false,
  url: null,
  expires_at: null,
  revoked_at: null,
}

describe('NoteSharePanel', () => {
  beforeEach(() => {
    vi.stubGlobal('navigator', { clipboard: { writeText: vi.fn().mockResolvedValue(undefined) } })
    vi.stubGlobal('confirm', vi.fn().mockReturnValue(true))
  })

  it('shows an active link, copies it, and emits revoke', async () => {
    const state: NoteShareState = {
      active: true,
      url: 'https://example.test/share/opaque',
      expires_at: null,
      revoked_at: null,
    }
    const wrapper = mount(NoteSharePanel, {
      props: { state },
      global: { plugins: [i18n] },
    })

    await wrapper.get('[data-testid="copy-share-link"]').trigger('click')
    expect(navigator.clipboard.writeText).toHaveBeenCalledWith(state.url)

    await wrapper.get('[data-testid="revoke-share-link"]').trigger('click')
    expect(wrapper.emitted('revoke')).toHaveLength(1)
  })

  it('requires an explicit action before creating a public link', async () => {
    const wrapper = mount(NoteSharePanel, {
      props: { state: inactive },
      global: { plugins: [i18n] },
    })

    expect(wrapper.find('[data-testid="create-share-link"]').exists()).toBe(true)
    expect(wrapper.text()).toContain('Not shared')

    await wrapper.get('[data-testid="share-expiry"]').setValue('2026-08-20T12:00')
    await wrapper.get('[data-testid="create-share-link"]').trigger('click')
    expect(wrapper.emitted('create')).toEqual([['2026-08-20T12:00']])
  })
})
