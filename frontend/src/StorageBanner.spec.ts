import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import StorageBanner from './components/StorageBanner.vue'
import { formatBytes } from './services/formatBytes'

const MB = 1024 * 1024

describe('StorageBanner (#390 workspace quota)', () => {
  it('stays hidden below the warning threshold and for unlimited workspaces', () => {
    expect(mount(StorageBanner, { props: { storage: null } }).find('[data-testid="storage-banner"]').exists()).toBe(false)
    expect(mount(StorageBanner, { props: { storage: { used_bytes: 10 * MB, limit_bytes: 100 * MB, percent: 10, warning: false, exceeded: false, quota_mb: 100 } } })
      .find('[data-testid="storage-banner"]').exists()).toBe(false)
    expect(mount(StorageBanner, { props: { storage: { used_bytes: 10 * MB, limit_bytes: null, percent: null, warning: false, exceeded: false, quota_mb: 0 } } })
      .find('[data-testid="storage-banner"]').exists()).toBe(false)
  })

  it('warns near the quota and says so when full', () => {
    const near = mount(StorageBanner, { props: { storage: { used_bytes: 85 * MB, limit_bytes: 100 * MB, percent: 85, warning: true, exceeded: false, quota_mb: 100 } } })
    expect(near.text()).toContain('85.0 MB of 100.0 MB (85%)')
    expect(near.classes()).not.toContain('storage-banner--full')

    const full = mount(StorageBanner, { props: { storage: { used_bytes: 100 * MB, limit_bytes: 100 * MB, percent: 100, warning: true, exceeded: true, quota_mb: 100 } } })
    expect(full.text()).toContain('is full')
    expect(full.find('[data-testid="storage-banner"]').classes()).toContain('storage-banner--full')
  })

  it('formats sizes in binary units', () => {
    expect(formatBytes(512, 'en')).toBe('512 B')
    expect(formatBytes(1536, 'en')).toBe('1.5 KB')
    expect(formatBytes(1024 * MB, 'en')).toBe('1.0 GB')
  })

  it('shows the GitHub sync state only when it needs attention (#441)', () => {
    const banner = (state: 'synced' | 'pending' | 'delayed' | 'read_only') =>
      mount(StorageBanner, { props: { storage: null, sync: { driver: 'github', state, pending: 4 } } })
    expect(banner('synced').find('[data-testid="storage-banner"]').exists()).toBe(false)
    expect(banner('pending').find('[data-testid="storage-banner"]').exists()).toBe(false)
    expect(banner('delayed').text()).toContain('4 changes queued')
    expect(banner('read_only').text()).toContain('read-only')
    expect(banner('read_only').find('[data-testid="storage-banner"]').classes()).toContain('storage-banner--full')
  })
})
