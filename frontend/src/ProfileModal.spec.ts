import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import ProfileModal from './components/ProfileModal.vue'
import { updateProfile } from './services/api'

vi.mock('./services/api', () => ({ updateProfile: vi.fn(), startTwoFactor: vi.fn(), confirmTwoFactor: vi.fn(), disableTwoFactor: vi.fn(), regenerateRecoveryCodes: vi.fn() }))

describe('ProfileModal (#390 name correction)', () => {
  beforeEach(() => vi.clearAllMocks())

  it('saves the trimmed name and reports it back', async () => {
    vi.mocked(updateProfile).mockResolvedValue('Joana Doe')
    const wrapper = mount(ProfileModal, { props: { currentName: 'Joe Doe', email: 'joe@acme.test' } })

    expect((wrapper.find('[data-testid="profile-name"]').element as HTMLInputElement).value).toBe('Joe Doe')
    expect(wrapper.text()).toContain('joe@acme.test')

    await wrapper.find('[data-testid="profile-name"]').setValue('  Joana Doe ')
    await wrapper.find('form').trigger('submit.prevent')
    await flushPromises()

    expect(updateProfile).toHaveBeenCalledWith('Joana Doe')
    expect(wrapper.emitted('updated')?.[0]).toEqual(['Joana Doe'])
    expect(wrapper.find('[data-testid="profile-success"]').exists()).toBe(true)
  })

  it('shows the server message when the provider manages the name', async () => {
    vi.mocked(updateProfile).mockRejectedValue({ response: { status: 409, data: { message: 'Your name comes from your sign-in provider; change it there.' } } })
    const wrapper = mount(ProfileModal, { props: { currentName: 'Joe', email: 'joe@acme.test' } })

    await wrapper.find('form').trigger('submit.prevent')
    await flushPromises()

    expect(wrapper.find('[data-testid="profile-error"]').text()).toContain('sign-in provider')
    expect(wrapper.emitted('updated')).toBeUndefined()
  })
})
