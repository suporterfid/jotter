<?php

namespace App\Domain\Notifications;

enum NotificationType: string
{
    case MENTION = 'mention';
    case NOTE_COMMENTED = 'note_commented';
    case COMMENT_REPLY = 'comment_reply';
    case NOTE_EDITED = 'note_edited';
    case NOTE_MOVED = 'note_moved';
    case NOTE_DELETED = 'note_deleted';
    // Remote vault sync (#442): conflict copies, external edits that won, store failures.
    case VAULT_SYNC = 'vault_sync';
}
