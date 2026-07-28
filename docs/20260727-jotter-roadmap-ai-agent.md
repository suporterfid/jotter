# Jotter Roadmap for AI Agent Planning

This document defines a pragmatic product roadmap for Jotter based on the currently visible public feature set of Jotter and a gap analysis against AppFlowy, AFFiNE, Docmost, and Colanode.[cite:27][cite:31][cite:25][cite:26][cite:24] It is written to be consumed by an AI agent that may later decompose the roadmap into epics, milestones, issues, architecture tasks, and UX workstreams.

## Scope and baseline

Jotter’s public feature descriptions present it as a lightweight notes product with offline-first behavior, rich note taking, Material You styling, Markdown support, keyboard shortcuts, slash commands, code highlighting, and realtime collaboration.[cite:27][cite:31] By contrast, the comparison products position themselves as broader workspaces that combine documents, structured data, collaboration, attachments, hierarchy, administration, and in some cases whiteboarding or visual canvases.[cite:25][cite:26][cite:24][cite:28]

The roadmap below assumes Jotter should evolve in controlled stages rather than attempting immediate parity with all competitors.[cite:18][cite:23][cite:26] That matters because AppFlowy emphasizes structured workflows and database views, AFFiNE emphasizes merged docs and whiteboards, Docmost emphasizes collaborative wiki capabilities, and Colanode emphasizes an all-in-one local-first collaboration stack.[cite:18][cite:24][cite:26][cite:28]

## External references

### Primary repos and product references

- Jotter repository: [https://github.com/suporterfid/jotter](https://github.com/suporterfid/jotter)
- AppFlowy repository: [https://github.com/AppFlowy-IO/AppFlowy](https://github.com/AppFlowy-IO/AppFlowy) [cite:18]
- AFFiNE repository: [https://github.com/toeverything/AFFiNE](https://github.com/toeverything/AFFiNE) [cite:24]
- Docmost repository: [https://github.com/docmost/docmost](https://github.com/docmost/docmost) [cite:26]
- Colanode repository: [https://github.com/colanode/colanode](https://github.com/colanode/colanode) [cite:25]
- Colanode docs: [https://colanode.com/docs/](https://colanode.com/docs/) [cite:38]
- AppFlowy comparison pages: [https://appflowy.com/compare/appflowy-vs-docmost](https://appflowy.com/compare/appflowy-vs-docmost), [https://appflowy.com/compare/appflowy-vs-affine](https://appflowy.com/compare/appflowy-vs-affine) [cite:18][cite:23]
- Docmost overview: [https://docmost.com/blog/open-source-notion-alternatives/](https://docmost.com/blog/open-source-notion-alternatives/) [cite:10]

### Reference feature signals from comparison products

| Product | Feature signals relevant to roadmap |
|---|---|
| AppFlowy | Grid, Kanban, Calendar, Gallery, List, Feed, Chart views; broader project-management scope; stronger governed team workflows.[cite:18][cite:23] |
| AFFiNE | Docs and whiteboard merged in one workspace; edgeless canvas; planning and visual thinking orientation.[cite:24][cite:23] |
| Docmost | Real-time collaboration, diagrams, spaces, permissions, groups, comments, page history, search, file attachments, nested pages, backlinks.[cite:26][cite:30][cite:36] |
| Colanode | Chat, notes, files, wiki, project management, customizable databases, local-first collaboration, file management, team workspace model.[cite:25][cite:28][cite:38] |

## Product direction options

Jotter should choose one clear center of gravity before large implementation work begins.[cite:18][cite:23][cite:28] The visible market positions of the reference tools suggest four viable strategic identities.[cite:18][cite:24][cite:26][cite:25]

| Option | Description | Pros | Risks |
|---|---|---|---|
| Notes-first | Best-in-class fast notes app with offline-first local ownership.[cite:27] | Smaller scope, clearer UX, easier quality bar. | Harder to differentiate against simple note apps. |
| Wiki/docs-first | Lightweight self-hosted knowledge base closer to Docmost.[cite:26] | Strong team documentation value. | Requires permissions, history, hierarchy, attachments earlier. |
| Workspace-first | Structured work management closer to AppFlowy or Colanode.[cite:18][cite:25] | Larger TAM and stronger Notion-replacement story. | Complexity rises fast, especially around data models and collaboration. |
| Visual knowledge app | Knowledge + whiteboard direction closer to AFFiNE.[cite:24] | Distinctive product identity. | Requires major editor and rendering investment. |

Recommended direction: begin as a notes-first and knowledge-first product, then selectively expand into workspace capabilities after the knowledge model is mature.[cite:26][cite:27][cite:31] This preserves focus while still moving toward the features users expect from modern open-source Notion alternatives.[cite:10][cite:18]

## Feature gap summary

Jotter appears to have core note-editing and collaboration basics, but the public materials do not show structured databases, page hierarchy, backlinks, file attachments, granular permissions, wiki spaces, page history, comments, diagrams, or visual canvases.[cite:27][cite:31] Those missing capabilities are prominently advertised by Docmost, AppFlowy, AFFiNE, and Colanode, making them the clearest roadmap targets.[cite:26][cite:18][cite:24][cite:25]

### Gaps with highest user value

1. Information architecture: nested pages, folders, backlinks, linked mentions, and stronger cross-note navigation.[cite:30][cite:36]
2. Retrieval: full-text search, filtered search, quick switcher, and metadata-based discovery.[cite:26][cite:30]
3. Durability: page history, restore flows, import/export, and reliable backups.[cite:26][cite:36]
4. Rich knowledge content: attachments, embeds, callouts, tables, synced blocks, and templates.[cite:26][cite:36][cite:24]
5. Team use: comments, mentions, shared workspaces, roles, permissions, groups, and activity logs.[cite:26][cite:30][cite:23]
6. Structured work: note properties, database-like tables, and later Kanban/calendar style views.[cite:18][cite:25]

## Roadmap principles

The roadmap should preserve Jotter’s speed and simplicity while adding compounding capabilities in a dependency-aware order.[cite:27][cite:31] The sequencing below assumes that search, hierarchy, metadata, and versioning are foundational, while database views and enterprise administration should come later.[cite:18][cite:26][cite:36]

### Sequencing rules for the AI agent

- Do not build multiple editor paradigms at once; stabilize the block and note model before adding databases or canvas features.[cite:18][cite:24]
- Prefer features that unlock multiple later capabilities, such as metadata, page IDs, backlinks, and history.[cite:30][cite:36]
- Keep self-hosting and offline-first concerns visible in each phase because those properties are part of the OSS differentiation story of several comparison products.[cite:25][cite:15][cite:24]
- Treat collaboration features as a second-order layer on top of a strong single-user knowledge model.[cite:26][cite:27]

## Proposed roadmap

## Phase 1: Strengthen the notes core

Goal: make Jotter a high-trust personal knowledge app before expanding scope.[cite:27][cite:31]

### Must-have outcomes

- Nested pages or folders for information hierarchy.[cite:30][cite:36]
- Full-text search with basic filters such as title, tags, and modified date.[cite:26][cite:30]
- Tags and note properties as a first metadata layer.[cite:18][cite:25]
- File and image attachments with local and synced storage strategy.[cite:26][cite:25]
- Note history with restore and compare-friendly revision snapshots.[cite:26][cite:36]
- Import/export for Markdown and JSON backup.[cite:27][cite:26]

### Why Phase 1 comes first

Docmost’s visible wiki features show that hierarchy, search, attachments, and history are central to durable knowledge management rather than “advanced extras.”[cite:26][cite:30][cite:36] Without these, Jotter remains closer to a lightweight editor than a knowledge system.[cite:27][cite:31]

### Suggested epics

- Core note identity and stable page IDs.
- Hierarchy and navigation model.
- Search indexing pipeline.
- Attachments subsystem.
- Revision history subsystem.
- Backup and import/export flows.

## Phase 2: Build a knowledge workspace

Goal: make Jotter feel meaningfully closer to modern Notion-style OSS tools without overcommitting to enterprise complexity.[cite:18][cite:24][cite:10]

### Must-have outcomes

- Expanded slash command system for callouts, toggles, tables, dividers, embeds, and code blocks.[cite:31][cite:36]
- Backlinks and linked mentions.[cite:36]
- Synced blocks or reusable content blocks.[cite:36]
- Page templates for recurring notes and meeting patterns.[cite:24][cite:18]
- Command palette and quick switcher.[cite:24]
- Lightweight table view for notes and metadata.[cite:18][cite:25]

### Why this matters

AFFiNE and AppFlowy both push beyond simple notes into a composable workspace model.[cite:24][cite:18] Jotter does not need immediate parity, but it does need enough block richness and information reuse to support serious daily workflows.[cite:23][cite:10]

### Suggested epics

- Block schema extension.
- Backlink graph and reference index.
- Template engine.
- Reusable block model.
- Metadata table explorer.
- Command palette UX.

## Phase 3: Collaboration and team readiness

Goal: turn Jotter from a personal knowledge app into a team-usable system.[cite:26][cite:25][cite:38]

### Must-have outcomes

- Shared workspaces or spaces.[cite:26][cite:30]
- Roles, permissions, and groups.[cite:26][cite:23]
- Inline comments, mentions, and notification events.[cite:26]
- Page sharing flows and presence indicators.[cite:26][cite:24]
- Activity feed and basic audit log.[cite:23][cite:25]

### Why this comes after Phase 2

Docmost’s public positioning shows that collaboration becomes substantially more valuable when there is already a structured page model, history, comments, and workspace boundaries.[cite:26][cite:30] Adding permissions too early often causes architectural churn across storage, sync, and editor layers.[cite:23][cite:25]

### Suggested epics

- Workspace and membership model.
- Permissions and ACL model.
- Commenting subsystem.
- Notification/event bus.
- Sharing and presence.
- Activity logging.

## Phase 4: Structured work management

Goal: selectively expand into AppFlowy or Colanode territory only if product strategy supports it.[cite:18][cite:25][cite:28]

### Candidate outcomes

- Database-like collections over note metadata.[cite:18][cite:25]
- Views such as table, board, calendar, and gallery.[cite:18]
- Relations and linked records between content types.[cite:18]
- Status workflows, assignees, dates, and simple automations.[cite:18][cite:28]

### Why this is optional and late

Structured work management greatly increases complexity in schema design, querying, permissions, and UX consistency.[cite:18][cite:25] AppFlowy advertises a broader project-management scope than Docmost, which is a reminder that this step changes Jotter from a notes app into a larger operating system for work.[cite:18][cite:20]

### Suggested epics

- Collection and view abstraction.
- Board/calendar rendering.
- Query/filter/sort engine.
- Relations and linked records.
- Workflow state system.

## Phase 5: Distinctive differentiators

Goal: choose one or two unique strengths instead of trying to copy all reference products.[cite:24][cite:18][cite:25]

### Possible differentiators

- Offline-first excellence and resilient sync.[cite:27][cite:25]
- Android-first and mobile-first speed advantage.[cite:27]
- AI-assisted knowledge workflows such as summarization, note linking, task extraction, and search augmentation, implemented in a privacy-aware way compatible with self-hosting expectations in OSS communities.[cite:24][cite:25][cite:15]
- Visual canvas or whiteboard features only if Jotter explicitly chooses the AFFiNE direction.[cite:24]

## Prioritized backlog for near-term execution

The AI agent should treat the following as the highest-priority implementation order for the next major cycles.[cite:26][cite:36][cite:18]

| Priority | Item | Rationale |
|---|---|---|
| 1 | Full-text search | High-value retrieval primitive used by every serious knowledge app.[cite:26][cite:30] |
| 2 | Nested pages/folders | Required for scalable organization.[cite:30][cite:36] |
| 3 | Tags and properties | Foundation for filtering and future structured views.[cite:18][cite:25] |
| 4 | Backlinks | Raises knowledge density and navigation quality.[cite:36] |
| 5 | Attachments | Required for practical team and documentation use.[cite:26][cite:25] |
| 6 | Version history | High trust and recoverability feature.[cite:26][cite:36] |
| 7 | Templates | Strong productivity multiplier with modest complexity.[cite:24][cite:18] |
| 8 | Synced blocks | Reuse and consistency across pages.[cite:36] |
| 9 | Comments and mentions | First major team-collaboration milestone.[cite:26] |
| 10 | Shared workspaces and permissions | Opens team adoption path.[cite:26][cite:23] |
| 11 | Metadata table view | First step toward structured workspace model.[cite:18][cite:25] |
| 12 | Board/calendar views | Only after metadata model is stable.[cite:18] |

## Dependency map for planning agents

The roadmap is not only a priority list; it also contains implementation dependencies that should constrain planning.[cite:18][cite:26]

- Stable page identity should precede backlinks, history, comments, sharing, and synced blocks.[cite:30][cite:36]
- Properties and tags should precede filtered search and table/board/calendar views.[cite:18][cite:25]
- Attachments should precede richer wiki and documentation use cases.[cite:26]
- Workspace boundaries should precede granular permissions and groups.[cite:26][cite:30]
- Comments and audit trails should reuse shared event infrastructure when possible.[cite:23][cite:25]

## Suggested milestone framing

### Milestone A: Knowledge foundations

Deliver search, hierarchy, properties, attachments, and history.[cite:26][cite:30][cite:36]

### Milestone B: Connected knowledge

Deliver backlinks, templates, synced blocks, and a more capable block editor.[cite:36][cite:24]

### Milestone C: Team collaboration

Deliver workspaces, comments, mentions, permissions, and activity history.[cite:26][cite:23]

### Milestone D: Structured workflows

Deliver metadata table view first, then optional board and calendar views.[cite:18][cite:25]

## Non-goals for the next cycle

To preserve focus, the next cycle should avoid full whiteboard parity with AFFiNE, broad chat-and-files parity with Colanode, and the entire database-view breadth advertised by AppFlowy.[cite:24][cite:25][cite:18] Those are valid future directions, but they are not the shortest path to making Jotter compelling.[cite:20][cite:10]

## Risks and trade-offs

Expanding too quickly into workspace and database features can erode Jotter’s core strengths in speed, simplicity, and offline usability.[cite:27][cite:25] Conversely, staying too minimal for too long risks weak differentiation because users evaluating OSS Notion alternatives increasingly expect search, hierarchy, attachments, history, and at least some structured organization.[cite:10][cite:26][cite:18]

A disciplined roadmap therefore favors foundational knowledge features first, collaboration second, and structured workspace features third.[cite:26][cite:18][cite:36] That sequence keeps the product coherent while still moving toward the capabilities users already recognize in leading OSS alternatives.[cite:24][cite:25][cite:10]
