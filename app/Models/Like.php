<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Like extends Model
{
    use HasFactory;

    protected $table = 'likes';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_uuid',
        'collection_uuid',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'user_uuid' => 'string',
        'collection_uuid' => 'string',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_uuid', 'uuid');
    }

    public function collection(): BelongsTo
    {
        return $this->belongsTo(Collection::class, 'collection_uuid', 'uuid');
    }
}
