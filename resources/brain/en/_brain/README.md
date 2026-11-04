# Brain — where to start

This workspace is the brain shared by people and AI agents. Everything is plain Markdown: open it in Jotter, in Obsidian, or in any editor. Folder names stay the same in every language so agent write paths work everywhere.

| Folder | Holds | Who writes |
| --- | --- | --- |
| `projetos/<slug>/estado.md` | State of each project: status, latest decision, next step | People (agents propose through `inbox/`) |
| `decisoes/YYYY-MM-DD-<slug>.md` | Dated decisions, one per file | People |
| `diario/YYYY-MM-DD.md` | Daily log; each agent appends what it did | Agents only append |
| `inbox/<client>/` | Drafts, findings, and proposals from each agent | That client's agent |
| `pessoas/<name>.md` | Who is who, context, preferences | People |
| `_brain/` | This router and the agent rules | People only |

Moving inbox items into the official folders is done by a person, or by an agent holding a token with wider write paths issued for that purpose.
