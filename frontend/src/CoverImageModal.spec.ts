import { mount } from '@vue/test-utils'
import { describe, expect, it, vi, beforeEach } from 'vitest'
import CoverImageModal from './components/CoverImageModal.vue'

vi.mock('./services/api', () => ({
  uploadAttachment: vi.fn(),
}))

import { uploadAttachment } from './services/api'

describe('CoverImageModal', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('starts on the Upload tab', () => {
    const wrapper = mount(CoverImageModal, { props: { workspaceId: 1 } })
    expect(wrapper.find('[data-testid="cover-upload-input"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="cover-url-input"]').exists()).toBe(false)
  })

  it('switches to the URL tab', async () => {
    const wrapper = mount(CoverImageModal, { props: { workspaceId: 1 } })
    await wrapper.find('[data-testid="cover-url-tab-btn"]').trigger('click')
    expect(wrapper.find('[data-testid="cover-url-input"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="cover-upload-input"]').exists()).toBe(false)
  })

  it('disables the URL tab\'s submit button for an empty input', async () => {
    const wrapper = mount(CoverImageModal, { props: { workspaceId: 1 } })
    await wrapper.find('[data-testid="cover-url-tab-btn"]').trigger('click')
    const submitBtn = wrapper.find('[data-testid="cover-url-submit-btn"]')
    expect(submitBtn.attributes('disabled')).toBeDefined()
    await wrapper.find('[data-testid="cover-url-input"]').setValue('https://example.com/x.jpg')
    expect(wrapper.find('[data-testid="cover-url-submit-btn"]').attributes('disabled')).toBeUndefined()
  })

  it('emits set-cover with the typed URL and does not call uploadAttachment', async () => {
    const wrapper = mount(CoverImageModal, { props: { workspaceId: 1 } })
    await wrapper.find('[data-testid="cover-url-tab-btn"]').trigger('click')
    await wrapper.find('[data-testid="cover-url-input"]').setValue('https://example.com/x.jpg')
    await wrapper.find('[data-testid="cover-url-submit-btn"]').trigger('click')
    expect(wrapper.emitted('set-cover')).toEqual([['https://example.com/x.jpg']])
    expect(uploadAttachment).not.toHaveBeenCalled()
  })

  it('uploads the selected file and emits set-cover with the resulting url', async () => {
    vi.mocked(uploadAttachment).mockResolvedValue({
      id: 1, workspace_id: 1, path: 'covers/x.jpg', mime: 'image/jpeg', size: 100,
      created_at: '2026-07-31T00:00:00Z', url: 'https://app/attachments/covers/x.jpg',
    })
    const wrapper = mount(CoverImageModal, { props: { workspaceId: 1 } })
    const input = wrapper.find('[data-testid="cover-upload-input"]')
    const file = new File(['x'], 'x.jpg', { type: 'image/jpeg' })
    Object.defineProperty(input.element, 'files', { value: [file] })
    await input.trigger('change')
    await wrapper.vm.$nextTick()
    expect(uploadAttachment).toHaveBeenCalledWith(1, file)
    expect(wrapper.emitted('set-cover')).toEqual([['https://app/attachments/covers/x.jpg']])
  })

  it('emits close when the overlay background is clicked', async () => {
    const wrapper = mount(CoverImageModal, { props: { workspaceId: 1 } })
    await wrapper.find('.modal-overlay').trigger('click.self')
    expect(wrapper.emitted('close')).toBeTruthy()
  })
})
