<?php

namespace App\Domain\Links;

use App\Domain\Auth\AuthenticatedSubject;
use App\Domain\Auth\NoteAccess;
use App\Models\Note;

/**
 * Outgoing wikilinks of a note, ACL-filtered: links to notes the subject cannot
 * see are dropped, unresolved links are kept. Shared by the REST endpoint and
 * the MCP `get_outgoing_links` tool.
 */
final class OutgoingLinks
{
    public function __construct(private readonly NoteAccess $noteAccess) {}

    /**
     * @return list<array{id: int|null, path: string|null, title: string|null, target_ref: string, target_block: string|null, resolved: bool}>
     */
    public function for(AuthenticatedSubject $subject, Note $note): array
    {
        return $note->outgoingLinks()
            ->where('type', 'wikilink')
            ->with('targetNote')
            ->get()
            ->filter(fn ($link) => $link->targetNote === null || $this->noteAccess->canView($subject, $link->targetNote))
            ->map(fn ($link) => [
                'id' => $link->targetNote?->id,
                'path' => $link->targetNote?->path,
                'title' => $link->targetNote?->title,
                'target_ref' => $link->target_ref,
                'target_block' => $link->target_block,
                'resolved' => $link->targetNote !== null,
            ])
            ->values()
            ->all();
    }
}
