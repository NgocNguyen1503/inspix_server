<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Follower extends Model
{
    use HasFactory;

    protected $table = 'followers';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_uuid',
        'author_uuid',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'user_uuid' => 'string',
        'author_uuid' => 'string',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_uuid', 'uuid');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_uuid', 'uuid');
    }
}
