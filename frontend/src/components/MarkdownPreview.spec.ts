import { mount } from '@vue/test-utils'
import { defineComponent, ref } from 'vue'
import { describe, expect, it, vi } from 'vitest'
import MarkdownPreview from './MarkdownPreview.vue'

describe('MarkdownPreview pane-local navigation', () => {
  it('scrolls only the heading inside the preview instance', async () => {
    Object.defineProperty(HTMLElement.prototype, 'scrollIntoView', {
      configurable: true,
      value: vi.fn(),
    })
    const left = ref<InstanceType<typeof MarkdownPreview> | null>(null)
    const right = ref<InstanceType<typeof MarkdownPreview> | null>(null)
    const host = defineComponent({
      components: { MarkdownPreview },
      setup: () => ({ left, right }),
      template: `
        <div>
          <MarkdownPreview ref="left" content="# Same heading" heading-id-prefix="left--" />
          <MarkdownPreview ref="right" content="# Same heading" heading-id-prefix="right--" />
        </div>
      `,
    })

    const wrapper = mount(host)
    const headings = wrapper.findAll('h1')
    const leftScroll = vi.fn()
    const rightScroll = vi.fn()
    Object.defineProperty(headings[0].element, 'scrollIntoView', { configurable: true, value: leftScroll })
    Object.defineProperty(headings[1].element, 'scrollIntoView', { configurable: true, value: rightScroll })

    left.value?.scrollToHeading('same-heading')

    expect(leftScroll).toHaveBeenCalledOnce()
    expect(rightScroll).not.toHaveBeenCalled()
  })
})
