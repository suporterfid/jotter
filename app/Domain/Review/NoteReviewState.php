<?php

namespace App\Domain\Review;

enum NoteReviewState: string
{
    case DRAFT = 'draft';
    case IN_REVIEW = 'in_review';
    case CHANGES_REQUESTED = 'changes_requested';
    case APPROVED = 'approved';
}
