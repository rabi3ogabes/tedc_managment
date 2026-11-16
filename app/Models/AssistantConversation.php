<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['user_id', 'title', 'locale'])]
class AssistantConversation extends Model
{
    use HasUuids;

    protected $table = 'assistant_conversations';
}
