# Content approval workflow

The content approval workflow records review and sign-off metadata for a note. The Markdown file remains the source of truth and stays readable by Obsidian, WebDAV, and other vault tools.

## States

- `draft`: no current review is in progress, or the approved hash became stale after a content edit.
- `in_review`: an editor submitted the current note content for review.
- `changes_requested`: a reviewer sent the note back with a reason; the editor must update and resubmit it.
- `approved`: the assigned reviewer, workspace owner, or workspace admin approved the exact current content hash.

An approved note becomes stale when its projected `content_hash` differs from the hash recorded at approval. The API then exposes the effective state as `draft` with `stale: true` until the note is submitted again.

## Permissions

- Editors can submit notes they can edit.
- The assigned reviewer can approve or request changes only if `NoteAccess` allows them to view the note.
- Workspace owners and admins can assign reviewers and act as reviewers.
- Viewers and service tokens can read the review summary when they can view the note, but cannot assign, submit, approve, or request changes.
- Per-note ACLs remain authoritative. A reviewer assignment never grants note access.

## Important boundary

Approval is advisory review metadata, not a filesystem lock and not automatic publication. A user who can edit the vault through Obsidian, WebDAV, MCP, or the filesystem can still change the Markdown file. Jotter detects the resulting projection hash change and marks the previous approval stale; it does not write workflow data into front matter or block the external writer.

Approval also does not change static publishing. If publication gating is needed later, it requires a separate product decision and issue.

## Audit trail

Assignment, submission, approval, requested changes, and approval invalidation are written as immutable events in the existing `audit_log`. Events carry the note/workspace scope, actor, state transition, reviewer id, and content hash where needed. They never include the note body, share tokens, credentials, or other secrets.

