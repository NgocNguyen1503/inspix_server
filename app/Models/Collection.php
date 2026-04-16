<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Collection extends Model
{
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'title',
        'description',
        'topic_id',
        'total_likes',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'user_id' => 'integer',
        'topic_id' => 'integer',
        'total_likes' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function topic(): BelongsTo
    {
        return $this->belongsTo(Topic::class, 'topic_id');
    }

    public function images(): HasMany
    {
        return $this->hasMany(Image::class, 'collection_id');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class, 'collection_id');
    }

    public function likes(): HasMany
    {
        return $this->hasMany(Like::class, 'collection_id');
    }
}
