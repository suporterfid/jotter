import { describe, expect, it } from 'vitest'
import { roundTripMarkdown } from '../wysiwygRoundTrip'

/**
 * WY.1 round-trip fidelity harness (docs/20260805-jotter-wysiwyg-editor-epic-spec.md §5).
 *
 * Every syntax feature `frontend/src/services/markdown.ts` and
 * `app/Domain/Vault/MarkdownServerRenderer.php` special-case gets a fixture
 * here, run through Markdown → Milkdown doc → Markdown.
 *
 * Two categories, both required by the issue's acceptance criteria:
 *
 * - LOSSLESS_FIXTURES: byte-identical (after trimming trailing newline)
 *   round trip. A regression here is a real bug — these must never move to
 *   the gaps list silently.
 * - KNOWN_GAP_FIXTURES: the round trip is NOT lossless today. Each entry
 *   asserts the *actual current* mangled output (not the original input),
 *   so CI stays green while the mismatch stays visible and undeniable —
 *   this is what "documented, not silently dropped" means. Every entry here
 *   is required scope for WY.3 (#323): either a native ProseMirror node
 *   needs to be added for the construct, or (for front matter) the WYSIWYG
 *   editor must strip/reattach it outside Milkdown's parse, the same way
 *   MarkdownDocument::parse()/compose() already does server-side.
 */

interface LosslessFixture {
  name: string
  markdown: string
}

interface KnownGapFixture {
  name: string
  markdown: string
  /** The current (mangled) round-trip output — not the original input. */
  actualOutput: string
  /** Why this doesn't round-trip today, and what WY.3 needs to fix it. */
  gap: string
}

const LOSSLESS_FIXTURES: LosslessFixture[] = [
  {
    name: 'headings h1-h6',
    markdown: '# H1\n\n## H2\n\n### H3\n\n#### H4\n\n##### H5\n\n###### H6\n',
  },
  {
    name: 'emphasis: italic, bold, strikethrough (GFM)',
    markdown: '*italic* _also italic_ **bold** ~~strikethrough~~\n',
  },
  {
    name: 'inline code',
    markdown: 'Use `inline code` here.\n',
  },
  {
    name: 'links and images',
    markdown: '[a link](https://example.com) and ![alt text](https://example.com/x.png)\n',
  },
  {
    name: 'nested ordered lists',
    markdown: '1. first\n2. second\n   1. nested one\n   2. nested two\n3. third\n',
  },
  {
    name: 'code fence, no language',
    markdown: '```\nplain code\n```\n',
  },
  {
    name: 'code fence, language-tagged',
    markdown: "```js\nconst x = 1;\n```\n",
  },
  {
    name: 'mermaid fence (opaque — no native node, must pass through byte-for-byte)',
    markdown: '```mermaid\ngraph TD;\nA-->B;\n```\n',
  },
  {
    name: 'dataview-style fence (opaque — Obsidian plugin syntax, no native node)',
    markdown: '```dataview\nLIST FROM #tag\n```\n',
  },
  {
    name: 'standalone block-id reference (not modeled as a native node; must survive as plain text)',
    markdown: 'Some paragraph text. ^myblock\n',
  },
  {
    name: 'blockquote (plain, no callout marker)',
    markdown: '> A plain quoted line.\n',
  },
]

const KNOWN_GAP_FIXTURES: KnownGapFixture[] = [
  {
    name: 'unordered + nested task lists (bullet marker normalized - -> *)',
    markdown: '- [ ] parent\n  - [x] child done\n  - [ ] child open\n- [ ] sibling\n',
    actualOutput: '* [ ] parent\n  * [x] child done\n  * [ ] child open\n* [ ] sibling\n',
    gap: 'Milkdown/remark-stringify normalizes every unordered bullet marker to `*` regardless of the source marker (`-`, `*`, or `+`). This is semantically lossless CommonMark (bullet character carries no meaning) but is NOT byte-identical, so it is tracked here rather than in LOSSLESS_FIXTURES. WY.3 should decide whether to configure remark-stringify to preserve `-` (Jotter\'s convention throughout this codebase) via a serializer option, since a note saved once through the WYSIWYG editor will otherwise rewrite every list in the file.',
  },
  {
    name: 'tables (header-separator row normalized --- -> -)',
    markdown: '| A | B |\n| --- | --- |\n| 1 | 2 |\n',
    actualOutput: '| A | B |\n| - | - |\n| 1 | 2 |\n',
    gap: 'GFM table serialization shortens the separator row to the minimum valid width (`-` instead of `---`). Semantically identical CommonMark, not byte-identical. Same disposition as the bullet-marker gap above — cosmetic diff on every saved table, worth a serializer option in WY.3 if avoidable.',
  },
  {
    name: 'wikilink [[Note]]',
    markdown: 'See [[Other Note]] for more.\n',
    actualOutput: 'See \\[\\[Other Note]] for more.\n',
    gap: 'No native wikilink node exists yet (that is WY.3\'s job, from blockRegistry.ts). Milkdown sees literal `[[`/`]]` text and remark-stringify defensively backslash-escapes the brackets so they cannot be misread as link syntax on the next parse. Saving a note through the WYSIWYG editor today would permanently rewrite every wikilink in the file with escape characters — this is the single highest-priority native node for WY.3.',
  },
  {
    name: 'wikilink with alias and heading anchor: [[Note#Heading|Alias]]',
    markdown: '[[Note#Heading|Alias]]\n',
    actualOutput: '\\[\\[Note#Heading|Alias]]\n',
    gap: 'Same bracket-escaping gap as the plain wikilink above; alias and #heading fragment syntax is untouched inside the escaped brackets, so WY.3\'s wikilink node must parse alias/heading/block-ref forms, not just the bare target.',
  },
  {
    name: 'wikilink block reference: [[Note#^blockid]]',
    markdown: '[[Note#^blockid]]\n',
    actualOutput: '\\[\\[Note#^blockid]]\n',
    gap: 'Same bracket-escaping gap. WikilinkExtractor.php\'s targetBlock() parsing (the `#^id` convention) needs a matching case in WY.3\'s wikilink node.',
  },
  {
    name: 'embed ![[Note]]',
    markdown: 'See ![[Other Note]] embedded.\n',
    actualOutput: 'See !\\[\\[Other Note]] embedded.\n',
    gap: 'Same root cause as the wikilink gap — no native embed node yet (blockRegistry.ts "embed" entry), so the `![[`/`]]` brackets get defensively escaped. Required scope for WY.3 alongside the wikilink node.',
  },
  {
    name: 'callout > [!NOTE]',
    markdown: '> [!NOTE] This is a note.\n',
    actualOutput: '> \\[!NOTE] This is a note.\n',
    gap: 'No native callout node yet (blockRegistry.ts "callout" entry). Milkdown sees a plain blockquote containing literal `[!NOTE]` text and escapes the brackets the same way it does for wikilinks. Required scope for WY.3.',
  },
  {
    name: 'front matter',
    markdown: '---\ntitle: Test Note\nstatus: draft\n---\n\n# Body\n',
    actualOutput: '***\n\ntitle: Test Note\nstatus: draft\n-------------\n\n# Body\n',
    gap: 'CommonMark has no concept of YAML front matter. The leading `---` is parsed as a thematic break (re-serialized as `***`); "title: Test Note" becomes a plain paragraph; "status: draft" immediately followed by the closing `---` is parsed as a Setext H2 heading. Front matter is destroyed entirely. This is the single most severe gap: front matter carries Jotter\'s typed note properties (§ MarkdownDocument::parse()/compose()). WY.2/WY.3 MUST strip front matter before handing the body to Milkdown and re-attach it unchanged on save, exactly as the server-side MarkdownDocument class already does — Milkdown must never see raw front matter. (Content after the front matter block, e.g. "# Body" here, is unaffected — the damage is contained to the front matter block itself.)',
  },
]

describe('WYSIWYG round-trip fidelity (Markdown -> Milkdown doc -> Markdown)', () => {
  describe.each(LOSSLESS_FIXTURES)('lossless: $name', ({ markdown }) => {
    it('round-trips byte-for-byte', async () => {
      const output = await roundTripMarkdown(markdown)
      expect(output.trim()).toBe(markdown.trim())
    })
  })

  describe.each(KNOWN_GAP_FIXTURES)('known gap: $name', ({ markdown, actualOutput, gap }) => {
    it('does not round-trip losslessly (documented gap, required scope for #323/WY.3)', async () => {
      const output = await roundTripMarkdown(markdown)

      // Asserting against actualOutput (not the original markdown) is the point:
      // it proves the gap is real, current, and specific — not silently dropped.
      expect(output).toBe(actualOutput)
      expect(gap.length).toBeGreaterThan(0)
    })
  })
})
