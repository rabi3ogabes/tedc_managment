<?php

namespace App\Social\Events;

use App\Models\Post;

/** Raised when the matching thing happens in a space; gamification and the integration bus listen. */
class PostPublished
{
    public function __construct(public readonly Post $post) {}
}
