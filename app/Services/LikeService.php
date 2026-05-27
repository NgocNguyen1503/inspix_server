<?php

namespace App\Services;

use App\Models\Like;
use Illuminate\Support\Facades\DB;

class LikeService
{
    /**
     * Toggle like for a user on a collection.
     */
    public function toggleLike(string $userUuid, string $collectionUuid): array
    {
        return DB::transaction(function () use ($userUuid, $collectionUuid) {
            $existing = Like::where('user_uuid', $userUuid)
                ->where('collection_uuid', $collectionUuid)
                ->first();

            $collectionExists = DB::table('collections')->where('uuid', $collectionUuid)->first();

            if ($existing !== null) {
                $existing->delete();

                if ($collectionExists !== null) {
                    DB::table('collections')->where('uuid', $collectionUuid)->where('total_likes', '>', 0)->decrement('total_likes');
                    $updated = DB::table('collections')->where('uuid', $collectionUuid)->value('total_likes');
                    $totalLikes = $updated !== null ? (int) $updated : null;
                } else {
                    $totalLikes = null;
                }

                return ['created' => false, 'total_likes' => $totalLikes];
            }

            Like::create([
                'user_uuid' => $userUuid,
                'collection_uuid' => $collectionUuid,
            ]);

            if ($collectionExists !== null) {
                DB::table('collections')->where('uuid', $collectionUuid)->increment('total_likes');
                $updated = DB::table('collections')->where('uuid', $collectionUuid)->value('total_likes');
                $totalLikes = $updated !== null ? (int) $updated : null;
            } else {
                $totalLikes = null;
            }

            return ['created' => true, 'total_likes' => $totalLikes];
        });
    }
}
