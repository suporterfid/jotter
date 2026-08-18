import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import i18n from './i18n'
import NotificationPreferences from './components/NotificationPreferences.vue'
import { getNotificationPreferences, updateNotificationPreference } from './services/api'

vi.mock('./services/api', () => ({
  getNotificationPreferences: vi.fn(),
  updateNotificationPreference: vi.fn(),
}))

const mockedGetPreferences = vi.mocked(getNotificationPreferences)
const mockedUpdatePreference = vi.mocked(updateNotificationPreference)

describe('NotificationPreferences', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockedGetPreferences.mockResolvedValue([
      { type: 'mention', mode: 'immediate', explicit: false },
      { type: 'note_edited', mode: 'digest', explicit: false },
    ])
    mockedUpdatePreference.mockResolvedValue({ type: 'note_edited', mode: 'off', explicit: true })
  })

  it('loads categories and persists a changed delivery mode', async () => {
    const wrapper = mount(NotificationPreferences, {
      props: { isOpen: true },
      global: { plugins: [i18n] },
    })

    await flushPromises()

    expect(wrapper.find('[data-testid="notification-preferences-modal"]').exists()).toBe(true)
    expect(wrapper.findAll('[data-testid="notification-preference-row"]')).toHaveLength(2)

    await wrapper.get('[data-testid="notification-preference-note_edited"]').setValue('off')

    expect(mockedUpdatePreference).toHaveBeenCalledWith('note_edited', 'off')
  })

  it('confirms an unsubscribe request before setting the category to off', async () => {
    vi.spyOn(window, 'confirm').mockReturnValue(true)

    mount(NotificationPreferences, {
      props: { isOpen: true, unsubscribeType: 'mention' },
      global: { plugins: [i18n] },
    })

    await flushPromises()

    expect(window.confirm).toHaveBeenCalled()
    expect(mockedUpdatePreference).toHaveBeenCalledWith('mention', 'off')
  })
})
