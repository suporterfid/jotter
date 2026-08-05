import { describe, expect, it } from 'vitest'
import { splitFrontMatter, joinFrontMatter } from '../frontMatterGuard'

describe('splitFrontMatter / joinFrontMatter', () => {
  it('splits off a leading front matter block, keeping its delimiters', () => {
    const raw = '---\ntitle: Test Note\nstatus: draft\n---\n\n# Body\n'

    const { frontMatter, body } = splitFrontMatter(raw)

    expect(frontMatter).toBe('---\ntitle: Test Note\nstatus: draft\n---\n')
    expect(body).toBe('\n# Body\n')
  })

  it('returns an empty front matter and the untouched raw string when there is no front matter', () => {
    const raw = '# Body only\n\nNo front matter here.\n'

    const { frontMatter, body } = splitFrontMatter(raw)

    expect(frontMatter).toBe('')
    expect(body).toBe(raw)
  })

  it('does not treat a body-only leading hr (***) as front matter', () => {
    const raw = '***\n\nSome text after a thematic break.\n'

    const { frontMatter, body } = splitFrontMatter(raw)

    expect(frontMatter).toBe('')
    expect(body).toBe(raw)
  })

  it('joinFrontMatter reassembles the original byte-for-byte', () => {
    const raw = '---\ntitle: Test Note\n---\n\n# Body\n'
    const { frontMatter, body } = splitFrontMatter(raw)

    expect(joinFrontMatter(frontMatter, body)).toBe(raw)
  })

  it('joinFrontMatter with empty front matter returns the body unchanged', () => {
    const body = '# Body only\n'
    expect(joinFrontMatter('', body)).toBe(body)
  })
})
