import { afterEach, describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import ThemeToggle from './ThemeToggle.vue'

describe('ThemeToggle', () => {
  afterEach(() => {
    localStorage.clear()
    document.documentElement.removeAttribute('data-theme')
  })

  it('associates its localized label with all three theme preferences', () => {
    const wrapper = mount(ThemeToggle, { attachTo: document.body })
    const label = wrapper.get('label')
    const select = wrapper.get('select')

    expect(label.text()).toContain('Theme')
    expect(select.attributes('aria-label')).toBe('Theme')
    expect(select.findAll('option').map(option => option.attributes('value'))).toEqual(['system', 'light', 'dark'])

    wrapper.unmount()
  })
})
