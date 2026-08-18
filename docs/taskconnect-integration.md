# TaskConnect Delegation Contract (Issue #67)

This document defines the submission contract for delegating heavy content processing (PDF/DOCX parsing, web crawling, vector embeddings) from Jotter to TaskConnect per spec §3 N2 and §9.

## Overview

Jotter strictly enforces **no in-process heavy compute** (spec §3 N2, §4 shared-hosting limits). Heavy jobs are dispatched through the `JobDispatcher` interface to TaskConnect asynchronously.

## Job Payload Contract

All delegated jobs must supply a JSON payload matching the following schema:

```json
{
  "job_id": "uuid-v4",
  "workspace_id": 1,
  "action": "parse_document | crawl_url | generate_embeddings",
  "payload": {
    "file_path": "attachments/report.pdf",
    "target_note_path": "imports/report.md",
    "options": {
      "ocr": false,
      "preserve_images": true
    }
  },
  "callback_url": "https://hub.taskconnect.com.br/api/workspaces/1/jobs/callback",
  "idempotency_key": "workspace_1_file_report.pdf_v1"
}
```

## Seam Integrity (Spec §9)

- `App\Domain\Jobs\TaskConnectJobDispatcher` is the seam implementation.
- Jotter operates 100% self-contained when TaskConnect is absent (`JOB_DISPATCHER=local`).
- Delegated tasks degrade gracefully to unavailable without breaking core Markdown vault functionality.

## PDF export action (#354)

Workspace PDF generation uses the same dispatcher seam. The canonical payload is:

```json
{
  "workspace_id": 1,
  "action": "generate_pdf",
  "payload": {
    "export_id": "uuid-v4",
    "note_ids": [12, 18]
  },
  "idempotency_key": "pdf_export_uuid-v4"
}
```

The `note_ids` snapshot is produced after workspace and note ACL filtering. A
worker must update the export record through the application boundary and never
publish the private output path to clients. Local mode remains self-contained;
TaskConnect is an optional dispatcher implementation.
