import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import MyDataPanel from './components/MyDataPanel.vue'
import i18n from './i18n'
import { cancelAccountDeletion, requestAccountDeletion } from './services/api'

vi.mock('./services/api', () => ({
  USER_DATA_EXPORT_URL: '/api/user/data-export',
  requestAccountDeletion: vi.fn(),
  cancelAccountDeletion: vi.fn(),
}))

describe('MyDataPanel (LGPD self-service)', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    i18n.global.locale.value = 'pt-BR'
  })

  it('offers the data download as a browser download', () => {
    const link = mount(MyDataPanel).find('[data-testid="my-data-download"]')
    expect(link.attributes('href')).toBe('/api/user/data-export')
    expect(link.attributes('download')).toBeDefined()
  })

  it('asks for confirmation, then records the deletion request', async () => {
    vi.mocked(requestAccountDeletion).mockResolvedValue('2026-11-10T12:00:00+00:00')
    const wrapper = mount(MyDataPanel, { props: { deletionRequestedAt: null } })

    await wrapper.find('[data-testid="my-data-request"]').trigger('click')
    expect(requestAccountDeletion).not.toHaveBeenCalled()
    expect(wrapper.text()).toContain('até 30 dias')

    await wrapper.find('[data-testid="my-data-confirm"]').trigger('click')
    await flushPromises()

    expect(requestAccountDeletion).toHaveBeenCalledOnce()
    expect(wrapper.emitted('deletion-changed')?.[0]).toEqual(['2026-11-10T12:00:00+00:00'])
    expect(wrapper.find('[data-testid="my-data-requested"]').exists()).toBe(true)
  })

  it('withdraws a pending request', async () => {
    vi.mocked(cancelAccountDeletion).mockResolvedValue(null)
    const wrapper = mount(MyDataPanel, { props: { deletionRequestedAt: '2026-11-10T12:00:00+00:00' } })

    await wrapper.find('[data-testid="my-data-cancel"]').trigger('click')
    await flushPromises()

    expect(cancelAccountDeletion).toHaveBeenCalledOnce()
    expect(wrapper.emitted('deletion-changed')?.[0]).toEqual([null])
    expect(wrapper.find('[data-testid="my-data-request"]').exists()).toBe(true)
  })

  it('shows the server message when the request is refused', async () => {
    vi.mocked(requestAccountDeletion).mockRejectedValue({ response: { status: 409, data: { message: 'Este é o último administrador ativo; promova outra pessoa antes.' } } })
    const wrapper = mount(MyDataPanel)

    await wrapper.find('[data-testid="my-data-request"]').trigger('click')
    await wrapper.find('[data-testid="my-data-confirm"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-testid="my-data-error"]').text()).toContain('último administrador')
    expect(wrapper.emitted('deletion-changed')).toBeUndefined()
  })
})
