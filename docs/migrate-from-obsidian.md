# Migrate from Obsidian

An Obsidian vault is already Markdown on disk, so the migration is a copy.
Jotter keeps `[[wikilinks]]`, `![[embeds]]`, YAML front matter, tags, callouts,
and attachments; after import you can keep using Obsidian on the same files
through WebDAV.

## 1. Export

Zip the vault folder (the folder that contains your `.md` files and the hidden
`.obsidian/` directory):

```sh
cd ~/Notes
zip -r my-vault.zip "My Vault"
```

The archive may contain the vault folder as its only top-level entry — that
is how Finder/Explorer compression and `zip -r` produce it.

## 2. Import

In Jotter: sidebar **More actions → Import**, pick the ZIP, set **Source** to
**Obsidian vault export**, submit. Or from the server:

```sh
php artisan vault:import <workspace-id-or-slug> /path/my-vault.zip --source=obsidian
```

With `--source=obsidian` Jotter:

- strips the single top-level folder so `My Vault/Index.md` becomes `Index.md`;
- skips `.obsidian/` (app settings, workspace layout, plugins) and `.trash/`;
- keeps everything else, including `attachments/`, nested folders, and
  `_templates/` (Jotter's template folder has the same name Obsidian users
  often pick — templates simply work).

Existing files are not overwritten unless **Overwrite existing notes** is
checked (`--overwrite`).

Limits: 5 000 entries, 100 MB uncompressed, allowed extensions
`md markdown txt png jpg jpeg gif svg webp pdf json`. Other files are listed
under `errors` in the import result and can be uploaded as attachments.

## 3. Check

- Backlinks panel of a hub note shows its incoming links (wikilinks are
  resolved by path, title, or `aliases:` front matter).
- Search finds a known note.
- Attachments render (paths are kept as-is, e.g. `attachments/logo.png`).

## Keep Obsidian

Point Obsidian's WebDAV-capable sync (or any WebDAV client) at
`https://<host>/api/webdav/<workspace-id>` with your Jotter credentials; edits
made in Obsidian are re-indexed by the hourly `vault:reindex` job or on the
next save through the API.

## Tested with

`ImportSourcesTest::test_obsidian_export_strips_the_vault_folder_and_skips_obsidian_metadata`
imports an archive with the vault-folder wrapper, `.obsidian/app.json`,
`.trash/`, nested folders, an attachment, and cross-linked notes, and asserts
the wikilinks resolve afterwards.
