# Wikilink Transclusion (![[note]] Embeds) — Design

Date: 2026-08-04
Source: `docs/20260803-jotter-obsidian-ui-parity-audit.md` §G.5, issue #288
(reopened, was tracked-not-implemented, filed via #291).

## Problem

No occurrence of embed/transclusion handling anywhere in `frontend/src/`
(grep for "transclu"/"embed" was empty). Obsidian renders `![[Note
Title]]` as the referenced note's content inline. Jotter's wikilinks
(`[[...]]`) are link-only; there is no embed syntax or renderer.

## Decisions

- **Whole-note embeds only for v1.** `![[Note#Heading]]` and
  `![[Note#^blockId]]` embed the entire `Note`, with the fragment
  silently ignored. No existing block-id-to-content extraction logic
  exists anywhere in the codebase (the backend only tracks block ids for
  backlink attribution, via `WikilinkExtractor.php`, not content
  lookup) — adding heading/block-scoped extraction now would be real
  net-new scope beyond the audit's M-L estimate.
- **No recursive nesting.** An embedded note's own `![[...]]` syntax is
  left completely literal (unrendered) rather than expanded. This
  eliminates infinite-loop risk by construction — there is no recursive
  render call to guard. A direct self-embed (a note embedding itself)
  is still explicitly caught and shown as "Cannot embed a note within
  itself," since it's a real, easy-to-hit case (unlike genuine multi-hop
  cycles, which nesting-non-recursion already makes unreachable).
- **Notes only, not attachments.** `![[image.png]]`-style attachment
  embeds are out of scope; a target that doesn't resolve to a note
  renders as "unresolved," even if it happens to be an attachment
  filename.

## Architecture

- **`services/wikilinks.ts`** — new exported constant:
  ```ts
  export const EMBED_PATTERN = /!\[\[([^\]|#]+)(?:#[^\]|]*)?\]\]/g
  ```
  and new util:
  ```ts
  export function parseEmbedTargets(markdown: string): string[]
  ```
  Returns unique embed targets (trimmed, fragment stripped) via
  `markdown.matchAll(EMBED_PATTERN)` — `matchAll` operates on an
  internal copy of the regex per spec, so sharing one `g`-flagged
  constant across call sites (this function, and `markdown.ts`'s
  `renderEmbeds` below) carries no shared-mutable-state risk.

- **`services/markdown.ts`**:
  - `renderWikilinks`'s regex gains a `(?<!!)` negative lookbehind so
    `![[...]]` is never mistaken for a plain link (previously it would
    have rendered as `!<a class="wikilink">...</a>`, with a stray `!`).
  - New:
    ```ts
    export interface EmbedResolution {
      status: 'resolved' | 'loading' | 'unresolved' | 'circular'
      html?: string // present only when status === 'resolved'
    }

    function renderEmbeds(text: string, resolveEmbed?: (target: string) => EmbedResolution): string
    ```
    Runs *before* `renderWikilinks` in the pipeline. When `resolveEmbed`
    is omitted, it's a no-op (text passes through unchanged) — this is
    also what makes nesting non-recursive: an embedded note's content is
    rendered via a plain `renderMarkdown(cachedContent)` call (no
    resolver argument), so any `![[...]]` inside it is never touched by
    `renderEmbeds` and stays literal.
    Each match is replaced with
    `<div class="embed-block" data-embed-status="<status>" data-embed-target="<target>">...</div>`
    — same string-splice-before-`marked.parse()` pattern
    `renderCallouts` already uses (proven safe for raw-HTML-block
    passthrough in this codebase). Body per status: `resolved` →
    `resolution.html`; `loading` → "Loading embed…"; `circular` →
    "Cannot embed a note within itself."; `unresolved` → "Note not
    found: '<target>'".
  - `renderMarkdown(markdownText: string, resolveEmbed?: (target: string) => EmbedResolution): string`
    gains the optional second parameter, calling `renderEmbeds` first.

- **`services/blockRegistry.ts`** — new `embed` entry in
  `blockDefinitions`: `allowed_tags: ['div']`,
  `allowed_attributes: ['class', 'data-embed-status',
  'data-embed-target']`, `syntax: '![[Note Title]]'`. Required for
  DOMPurify's allowlist (the `embed-block` div's new data attributes
  aren't covered by any existing entry); also gives the feature a
  Slash-menu entry for free, since the same registry powers both.

- **`MarkdownPreview.vue`** — new optional prop
  `resolveEmbed?: (target: string) => EmbedResolution`, threaded
  straight into `renderMarkdown()` inside the existing `renderedContent`
  computed.

- **`NoteEditor.vue`**:
  - New `embedContentCache = reactive(new Map<number, string>())` —
    deliberately separate from G.2's hover-preview `noteContentCache`
    (a plain `Map`), because this one must drive a `computed`'s
    reactivity through `.get()`/`.set()` calls, not just back a `ref`
    reassignment; Vue's `reactive()` correctly instruments `Map`
    mutations for dependency tracking, a plain `Map` would not.
  - New `watch(editableContent, ..., { immediate: true })`: scans for
    embed targets via `parseEmbedTargets`, resolves each via the
    existing `resolveWikilinkTarget`, and fetches+caches any
    resolved-but-uncached, non-self target via the existing `getNote`.
  - New `resolveEmbed(target: string): EmbedResolution`: no match →
    `unresolved`; `match.id === props.note.id` → `circular`; cache miss
    → `loading`; cache hit → `resolved` with
    `html: renderMarkdown(cachedContent)` (no resolver arg — this is the
    non-recursion point).
  - Template: `<MarkdownPreview ... :resolve-embed="resolveEmbed" />`.

## Reactivity

No side effects inside the render path — `resolveEmbed` is a pure read
of already-fetched state; fetching is a separate, explicit
`watch`-driven effect. When a fetch resolves, `embedContentCache.set(...)`
invalidates `MarkdownPreview`'s `renderedContent` computed automatically:
`resolveEmbed`'s `.get()` call happens synchronously inside that
computed's evaluation (via `renderMarkdown` → `renderEmbeds` →
`resolveEmbed`), and Vue's dependency tracking is stack-based, not
scoped only to the computed's own function body — any reactive read
during that synchronous call chain is tracked.

## Backend

No change needed. `WikilinkExtractor.php` already indexes `[[...]]`
inside `![[...]]` for backlinks, so an embed also correctly shows up as
a backlink/outgoing-link on the target note — matching Obsidian's own
behavior (embeds count as links there too).

## Edge cases

- Fetch failure (network/404 after target resolved) — cache never
  populated, so state stays `loading` indefinitely rather than showing a
  stale error; acceptable for a passive rendering affordance (matches
  G.2's precedent for its own fetch-failure case).
- A plain `[[Note]]` link (no `!` prefix) must render unaffected by the
  `renderWikilinks` lookbehind change — regression-tested explicitly.

## Testing

- Unit (`wikilinks.spec.ts` additions): `parseEmbedTargets` — single
  target, multiple targets, duplicate targets deduped, fragment
  stripped, no embeds → `[]`.
- Unit (`markdown.spec.ts` additions): `renderMarkdown` with a
  `resolveEmbed` mock — `resolved`/`loading`/`unresolved`/`circular`
  each produce the corresponding `data-embed-status` div; `![[...]]`
  with no `resolveEmbed` argument stays completely literal in the
  output; a plain `[[Note]]` still renders as a link (lookbehind
  regression case).
- Component (`MarkdownPreview.spec.ts` additions): passing a
  `resolve-embed` prop threads through to produce the embed div in the
  rendered output.
- `NoteEditor.spec.ts` additions: a note containing `![[Ideas]]` fetches
  and caches `Ideas`'s content via `getNote`, then renders the resolved
  embed HTML; a note embedding its own id renders the circular guard
  without calling `getNote`; a note embedding an unresolved target
  renders "Note not found" without calling `getNote`.

## Out of scope

- Heading/block-scoped partial embeds (`#Heading`, `#^blockId`).
- Recursive/nested embed expansion.
- Attachment embeds (images, PDFs via `![[file.ext]]`).
- Any backend rendering path (server-rendered HTML for the publish/API
  surface) — this audit's scope was `frontend/src/` only.
