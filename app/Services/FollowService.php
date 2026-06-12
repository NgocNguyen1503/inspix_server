<?php

namespace App\Services;

use App\Models\Follower;
use Exception;
use Illuminate\Support\Facades\DB;
use function Illuminate\Support\now;

class FollowService
{
    public function toggleFollow(string $userUuid, string $authorUuid, ?string $username): array
    {
        try {
            $follow = Follower::where('user_uuid', $userUuid)
                ->where('author_uuid', $authorUuid)
                ->first();

            if (!$follow) {
                Follower::insert([
                    'user_uuid' => $userUuid,
                    'author_uuid' => $authorUuid,
                    'username' => $username ?? null,
                    'created_at' => now(),
                    'updated_at' => now()
                ]);
                return ['created' => true];
            }

            $follow->deleteOrFail();
            return ['created' => false];
        } catch (\Throwable $th) {
            throw new Exception($th->getMessage());
        }
    }
}