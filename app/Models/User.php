<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $primaryKey = 'uuid';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'bio',
        'avatar_url',
        'total_collections',
        'total_likes',
        'total_images',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function collections(): HasMany
    {
        return $this->hasMany(Collection::class, 'user_uuid', 'uuid');
    }

    public function images(): HasMany
    {
        return $this->hasMany(Image::class, 'user_uuid', 'uuid');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class, 'user_uuid', 'uuid');
    }

    public function likes(): HasMany
    {
        return $this->hasMany(Like::class, 'user_uuid', 'uuid');
    }

    public function interests(): HasMany
    {
        return $this->hasMany(UserInterested::class, 'user_uuid', 'uuid');
    }

    public function followingAuthors(): HasMany
    {
        return $this->hasMany(Follower::class, 'user_uuid', 'uuid');
    }

    public function followerUsers(): HasMany
    {
        return $this->hasMany(Follower::class, 'author_uuid', 'uuid');
    }
}
