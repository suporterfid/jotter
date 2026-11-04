import { flushPromises, mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import ConnectedAppsModal from './components/ConnectedAppsModal.vue'

const apiGet = vi.fn()
const apiDelete = vi.fn().mockResolvedValue({ data: {} })

vi.mock('./services/api', () => ({
  api: {
    get: (...args: unknown[]) => apiGet(...args),
    delete: (...args: unknown[]) => apiDelete(...args),
  },
}))

describe('ConnectedAppsModal', () => {
  it('lists connected apps and revokes one', async () => {
    apiGet.mockResolvedValue({ data: { data: [
      { id: 4, client_name: 'Claude', redirect_hosts: ['claude.ai'], workspaces: ['brain'], scopes: ['mcp:read'], last_used_at: null, expires_at: null },
    ] } })
    vi.spyOn(window, 'confirm').mockReturnValue(true)

    const wrapper = mount(ConnectedAppsModal)
    await flushPromises()

    expect(apiGet).toHaveBeenCalledWith('/user/oauth-grants')
    expect(wrapper.find('[data-testid="connected-app-4"]').text()).toContain('Claude')
    expect(wrapper.find('[data-testid="connected-app-4"]').text()).toContain('brain')

    await wrapper.find('[data-testid="connected-app-revoke-4"]').trigger('click')
    await flushPromises()

    expect(apiDelete).toHaveBeenCalledWith('/user/oauth-grants/4')
    expect(wrapper.find('[data-testid="connected-app-4"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="connected-apps-empty"]').exists()).toBe(true)
  })
})
