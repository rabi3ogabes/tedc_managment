<?php

namespace App\Social\Events;

use App\Models\Comment;
use App\Models\Post;

/** Someone reacted to a post or a comment. */
class ReactionReceived
{
    public function __construct(public readonly string $targetType, public readonly Post|Comment $target) {}
}
