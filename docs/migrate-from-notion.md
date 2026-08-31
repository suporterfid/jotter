# Migrate from Notion

Notion's **Export → Markdown & CSV** produces a ZIP whose file and folder
names carry a 32-character page id (`Engineering Wiki 2f9c0b1a…e8.md`) and
whose links are URL-encoded relative paths to those files. Jotter's Notion
import mode cleans this up so the result looks like a vault written by hand.

## 1. Export from Notion

Workspace or page **… → Export**: format **Markdown & CSV**, include
subpages, **Create folders for subpages** on. Download the ZIP (it usually has
one top-level `Export-<uuid>/` folder).

## 2. Import

In Jotter: **More actions → Import**, choose the ZIP, set **Source** to
**Notion Markdown export**. Or on the server:

```sh
php artisan vault:import <workspace-id-or-slug> /path/Export-….zip --source=notion
```

With `--source=notion` Jotter:

- strips the `Export-<uuid>/` wrapper;
- removes the ` <32-hex>` id from every file and folder name
  (`Engineering Wiki 2f9c….md` → `Engineering Wiki.md`,
  `Engineering Wiki 2f9c…/Onboarding abcd….md` → `Engineering Wiki/Onboarding.md`);
- rewrites Markdown links inside `.md` files: links to other exported pages
  become `[[wikilinks]]` (so backlinks work), links to attachments are
  URL-decoded and id-stripped, external `https://` links are untouched;
- keeps images (`png jpg jpeg gif svg webp`) and PDFs.

Existing files are not overwritten unless you tick **Overwrite existing notes**.

## What does not convert automatically

| Notion feature | Result | What to do |
|---|---|---|
| Databases (`*.csv`) | Skipped — listed under `errors` as `Disallowed file type` | Paste as a Markdown table, or turn rows into notes with YAML front matter and use a Jotter collection view |
| Two pages with the same name in one folder | Second one is skipped as an existing file | Rename in Notion before exporting, or import in two passes |
| Toggle blocks, callouts | Plain Markdown (`<details>`, quotes) | Jotter renders them as-is |
| Embeds of external services | Left as links | Enable `JOTTER_EXTERNAL_EMBED_DOMAINS` for allowed hosts |
| Comments, page history | Not exported by Notion | — |

## 3. Check

- Open the top page: page links show as `[[wikilinks]]` and resolve.
- Backlinks panel of a frequently linked page lists its sources.
- Images referenced from pages render.

## Tested with

`ImportSourcesTest::test_notion_export_strips_page_ids_and_rewrites_links_to_wikilinks`
imports an archive built in Notion's export layout (`Export-<uuid>/`, page ids
on files and folders, URL-encoded links with `#anchors`, an image, a CSV
database) and asserts the resulting paths, rewritten links, and the skipped
CSV. If you have a real export that behaves differently, open an issue with
the entry names (`unzip -l export.zip`) — no note content is needed.
