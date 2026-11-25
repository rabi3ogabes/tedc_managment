<?php

namespace App\Social\Events;

use App\Models\Comment;

/** Raised when the matching thing happens in a space; gamification and the integration bus listen. */
class AnswerAccepted
{
    public function __construct(public readonly Comment $comment) {}
}
