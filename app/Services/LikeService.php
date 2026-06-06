<?php

namespace App\Services;

use App\Models\Like;
use Illuminate\Support\Facades\DB;

class LikeService
{
    public function __construct(private readonly ImageService $imageService)
    {
    }

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

    public function getLikedCollections(string $userUuid, ?int $limit = 12, ?int $offset = 0): array
    {
        $likedCollectionUuids = DB::table('likes')
            ->where('user_uuid', $userUuid)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->pluck('collection_uuid')
            ->map(fn($uuid) => is_string($uuid) ? $uuid : (string) $uuid)
            ->filter()
            ->unique()
            ->values()
            ->all();

        $total = count($likedCollectionUuids);
        $pageUuids = array_slice($likedCollectionUuids, $offset, $limit);

        if (count($pageUuids) === 0) {
            return [
                'items' => collect(),
                'total' => $total,
            ];
        }

        $serverUuids = DB::table('collections')
            ->whereIn('uuid', $pageUuids)
            ->pluck('uuid')
            ->map(fn($uuid) => is_string($uuid) ? $uuid : (string) $uuid)
            ->filter()
            ->values()
            ->all();

        $serverItems = $this->imageService->getCollectionsByUuids($serverUuids, $userUuid)
            ->keyBy('uuid');

        $unsplashUuids = array_values(array_diff($pageUuids, $serverUuids));
        $unsplashItems = $this->imageService->getUnsplashCollectionsByUuids($unsplashUuids)->keyBy('uuid');

        $items = collect($pageUuids)
            ->map(fn(string $uuid) => $serverItems->get($uuid) ?? $unsplashItems->get($uuid))
            ->filter()
            ->values();

        return [
            'items' => $items,
            'total' => $total,
        ];
    }
}
