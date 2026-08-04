import { mount } from '@vue/test-utils'
import { describe, expect, it, vi, afterEach } from 'vitest'
import MarkdownPreview from './components/MarkdownPreview.vue'

describe('MarkdownPreview wikilink hover', () => {
  afterEach(() => {
    vi.useRealTimers()
  })

  it('emits hover-wikilink with the target and a rect after a 300ms hover', async () => {
    const wrapper = mount(MarkdownPreview, {
      props: { content: 'See [[Ideas]] for more.' },
    })
    vi.useFakeTimers()
    const link = wrapper.get('a.wikilink')
    await link.trigger('mouseover')

    expect(wrapper.emitted('hover-wikilink')).toBeUndefined()
    await vi.advanceTimersByTimeAsync(300)

    const emitted = wrapper.emitted('hover-wikilink')
    expect(emitted).toHaveLength(1)
    expect(emitted![0][0]).toBe('Ideas')
    expect(typeof (emitted![0][1] as DOMRect).top).toBe('number')
  })

  it('cancels the pending hover if mouseout fires before 300ms', async () => {
    const wrapper = mount(MarkdownPreview, {
      props: { content: 'See [[Ideas]] for more.' },
    })
    vi.useFakeTimers()
    const link = wrapper.get('a.wikilink')
    await link.trigger('mouseover')
    await link.trigger('mouseout')
    await vi.advanceTimersByTimeAsync(300)

    expect(wrapper.emitted('hover-wikilink')).toBeUndefined()
  })

  it('emits unhover-wikilink on mouseout after a successful hover', async () => {
    const wrapper = mount(MarkdownPreview, {
      props: { content: 'See [[Ideas]] for more.' },
    })
    vi.useFakeTimers()
    const link = wrapper.get('a.wikilink')
    await link.trigger('mouseover')
    await vi.advanceTimersByTimeAsync(300)
    await link.trigger('mouseout')

    expect(wrapper.emitted('unhover-wikilink')).toHaveLength(1)
  })

  it('emits unhover-wikilink when the preview scrolls', async () => {
    const wrapper = mount(MarkdownPreview, {
      props: { content: 'See [[Ideas]] for more.' },
    })
    await wrapper.get('.markdown-preview').trigger('scroll')

    expect(wrapper.emitted('unhover-wikilink')).toHaveLength(1)
  })
})

describe('MarkdownPreview embeds', () => {
  it('threads a resolveEmbed prop into the rendered output', () => {
    const wrapper = mount(MarkdownPreview, {
      props: {
        content: 'Before.\n\n![[Ideas]]',
        resolveEmbed: () => ({ status: 'resolved', html: '<p>Idea body.</p>' }),
      },
    })
    expect(wrapper.html()).toContain('data-embed-status="resolved"')
    expect(wrapper.html()).toContain('Idea body.')
  })

  it('leaves an embed literal when no resolveEmbed prop is given', () => {
    const wrapper = mount(MarkdownPreview, {
      props: { content: '![[Ideas]]' },
    })
    expect(wrapper.text()).toContain('![[Ideas]]')
  })
})
