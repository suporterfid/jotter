import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'

import App from './App.vue'

describe('App', () => {
  it('renders the Jotter landing screen', () => {
    const wrapper = mount(App)

    expect(wrapper.get('h1').text()).toBe('Jotter')
    expect(wrapper.text()).toContain('Your pocket notebook')
    expect(wrapper.text()).toContain('Markdown files stay yours')
  })
})
