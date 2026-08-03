import { mount, flushPromises } from '@vue/test-utils'
import { describe, expect, it, vi, beforeEach, afterEach } from 'vitest'
import ChangePasswordModal from './components/ChangePasswordModal.vue'

const changePassword = vi.fn()

vi.mock('./services/api', () => ({
  changePassword: (...args: unknown[]) => changePassword(...args),
}))

describe('ChangePasswordModal', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    changePassword.mockResolvedValue(undefined)
    vi.useFakeTimers()
  })

  afterEach(() => {
    vi.useRealTimers()
  })

  it('emits close when the overlay is clicked', async () => {
    const wrapper = mount(ChangePasswordModal)
    await wrapper.find('.modal-overlay').trigger('click.self')
    expect(wrapper.emitted('close')).toBeTruthy()
  })

  it('blocks submission when new password and confirmation do not match', async () => {
    const wrapper = mount(ChangePasswordModal)
    await wrapper.find('[data-testid="change-password-current"]').setValue('old-pass')
    await wrapper.find('[data-testid="change-password-new"]').setValue('new-pass-1')
    await wrapper.find('[data-testid="change-password-confirm"]').setValue('new-pass-2')
    await wrapper.find('form').trigger('submit')

    expect(changePassword).not.toHaveBeenCalled()
    expect(wrapper.find('[data-testid="change-password-error"]').text()).toContain('do not match')
  })

  it('submits current and new password via the API on a matching confirmation', async () => {
    const wrapper = mount(ChangePasswordModal)
    await wrapper.find('[data-testid="change-password-current"]').setValue('old-pass')
    await wrapper.find('[data-testid="change-password-new"]').setValue('new-pass-1')
    await wrapper.find('[data-testid="change-password-confirm"]').setValue('new-pass-1')
    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(changePassword).toHaveBeenCalledWith('old-pass', 'new-pass-1')
    expect(wrapper.find('[data-testid="change-password-success"]').exists()).toBe(true)
  })

  it('shows the server error message when the API call fails', async () => {
    changePassword.mockRejectedValue({ response: { data: { message: 'Current password does not match.' } } })
    const wrapper = mount(ChangePasswordModal)
    await wrapper.find('[data-testid="change-password-current"]').setValue('wrong-pass')
    await wrapper.find('[data-testid="change-password-new"]').setValue('new-pass-1')
    await wrapper.find('[data-testid="change-password-confirm"]').setValue('new-pass-1')
    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(wrapper.find('[data-testid="change-password-error"]').text()).toContain('Current password does not match.')
  })

  it('auto-closes shortly after a successful change', async () => {
    const wrapper = mount(ChangePasswordModal)
    await wrapper.find('[data-testid="change-password-current"]').setValue('old-pass')
    await wrapper.find('[data-testid="change-password-new"]').setValue('new-pass-1')
    await wrapper.find('[data-testid="change-password-confirm"]').setValue('new-pass-1')
    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(wrapper.emitted('close')).toBeFalsy()
    vi.advanceTimersByTime(1500)
    expect(wrapper.emitted('close')).toBeTruthy()
  })
})
