<?php

namespace App\Services;

use App\Models\Image;
use Carbon\Carbon;
/**
 * @method array searchCollections(string $searchKey, int $limit = 12, int $offset = 0, ?string $userUuid = null)
 */
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class ImageService
{
    public function __construct(private readonly UnsplashKeyManager $keyManager)
    {
    }

    private function callUnsplash(string $endpoint, array $params = []): ?\Illuminate\Http\Client\Response
    {
        $apiUrl = rtrim((string) config('services.unsplash.api_url', 'https://api.unsplash.com'), '/');

        // Try each available key
        while (($key = $this->keyManager->getAvailableKey()) !== null) {
            $response = Http::retry(1, 300)
                ->timeout(20)
                ->acceptJson()
                ->withHeaders(['Authorization' => 'Client-ID ' . $key])
                ->get($apiUrl . $endpoint, $params);

            // 429 Too Many Requests or 403 Forbidden = out of quota
            if (in_array($response->status(), [403, 429], true)) {
                $this->keyManager->markExhausted($key);
                continue;
            }

            return $response;
        }

        // all key exhausted
        return null;
    }

    /**
     * @param list<string> $authorUuids
     * @return array<string, array{followers: int, following: int}>
     */
    public function getFollowCountsBatch(array $authorUuids): array
    {
        if (count($authorUuids) === 0) {
            return [];
        }

        $followers = DB::table('followers')
            ->whereIn('author_uuid', $authorUuids)
            ->selectRaw('author_uuid, COUNT(*) as count')
            ->groupBy('author_uuid')
            ->pluck('count', 'author_uuid');

        $following = DB::table('followers')
            ->whereIn('user_uuid', $authorUuids)
            ->selectRaw('user_uuid, COUNT(*) as count')
            ->groupBy('user_uuid')
            ->pluck('count', 'user_uuid');

        $result = [];
        foreach ($authorUuids as $uuid) {
            $result[$uuid] = [
                'followers' => (int) ($followers[$uuid] ?? 0),
                'following' => (int) ($following[$uuid] ?? 0),
            ];
        }

        return $result;
    }

    /**
     * @return array{items: \Illuminate\Support\Collection<int, array<string, mixed>>, total: int}
     */
    public function getCollectionFeed(int $limit = 12, int $offset = 0, ?string $userUuid = null, ?int $topicId = null): array
    {
        $serverFeed = $this->getServerCollectionFeed($limit, $offset, $userUuid, $topicId);
        $serverItems = $serverFeed['items'];
        $serverTotal = (int) $serverFeed['total'];

        $remaining = max(0, $limit - $serverItems->count());
        $unsplashItems = $remaining > 0
            ? $this->getUnsplashCollectionFeed($remaining, $userUuid, $topicId)
            : collect();

        return [
            'items' => $serverItems->concat($unsplashItems)->values(),
            'total' => $serverTotal + $unsplashItems->count(),
        ];
    }

    /**
     * @return array{items: \Illuminate\Support\Collection<int, array<string, mixed>>, total: int}
     */
    private function getServerCollectionFeed(int $limit, int $offset, ?string $userUuid, ?int $topicId = null): array
    {
        $totalQuery = DB::table('collections');
        if ($topicId !== null) {
            $totalQuery->where('topic_id', $topicId);
        }
        $total = (int) $totalQuery->count();

        $likedCollectionUuids = $userUuid !== null
            ? DB::table('likes')
                ->where('user_uuid', $userUuid)
                ->pluck('collection_uuid')
                ->map(fn($uuid) => $this->nullableString($uuid))
                ->filter()
                ->unique()
                ->values()
                ->all()
            : [];

        $followedAuthorUuids = $userUuid !== null
            ? DB::table('followers')
                ->where('user_uuid', $userUuid)
                ->pluck('author_uuid')
                ->map(fn($uuid) => $this->nullableString($uuid))
                ->filter()
                ->unique()
                ->values()
                ->all()
            : [];

        $rowsQuery = DB::table('collections as c')
            ->join('users as u', 'u.uuid', '=', 'c.user_uuid')
            ->leftJoin('topics as t', 't.id', '=', 'c.topic_id')
            ->select([
                'c.uuid',
                'c.title',
                'c.description',
                'c.total_likes',
                DB::raw('(SELECT COUNT(*) FROM comments cm WHERE cm.collection_uuid = c.uuid) as total_comments'),
                'c.created_at',
                'c.updated_at',
                'u.uuid as author_uuid',
                'u.name as author_name',
                'u.avatar_url as author_avatar_url',
                'u.bio as author_bio',
                't.id as topic_id',
                't.name as topic_name',
            ]);

        if ($topicId !== null) {
            $rowsQuery->where('c.topic_id', $topicId);
        }

        $rowsQuery
            ->orderByDesc('c.created_at')
            ->orderByDesc('c.uuid');

        $rows = $rowsQuery
            ->offset($offset)
            ->limit($limit)
            ->get();

        if ($rows->isEmpty()) {
            return [
                'items' => collect(),
                'total' => $total,
            ];
        }

        $collectionUuids = $rows->pluck('uuid')->map(fn($uuid) => $this->nullableString($uuid))->filter()->values()->all();

        $imagesByCollection = DB::table('images')
            ->select([
                'uuid',
                'color',
                'width',
                'height',
                'url_small',
                'url_regular',
                'url_full',
                'download_url',
                'collection_uuid',
            ])
            ->whereIn('collection_uuid', $collectionUuids)
            ->orderBy('created_at')
            ->get()
            ->groupBy('collection_uuid');

        $latestCommentsByCollection = $this->getLatestCommentsByCollections($collectionUuids);

        $likedCollectionMap = collect($likedCollectionUuids)->flip();
        $followedAuthorMap = collect($followedAuthorUuids)->flip();

        $authorUuids = $rows->pluck('author_uuid')->map(fn($uuid) => $this->nullableString($uuid))->filter()->unique()->values()->all();
        $followCounts = $this->getFollowCountsBatch($authorUuids);

        $items = $rows->map(function (object $row) use ($imagesByCollection, $userUuid, $likedCollectionMap, $followedAuthorMap, $latestCommentsByCollection, $followCounts): array {
            $collectionImages = $imagesByCollection->get($row->uuid, collect())->map(function (object $image): array {
                return [
                    'uuid' => $this->nullableString($image->uuid),
                    'color' => $image->color,
                    'width' => $image->width !== null ? (int) $image->width : null,
                    'height' => $image->height !== null ? (int) $image->height : null,
                    'url_small' => $this->nullableString($image->url_small),
                    'url_regular' => $this->nullableString($image->url_regular),
                    'url_full' => $this->nullableString($image->url_full),
                    'download_url' => $this->nullableString($image->download_url ?? $image->url_full),
                ];
            })->values();

            return [
                'uuid' => $this->nullableString($row->uuid),
                'title' => $this->nullableString($row->title),
                'description' => $this->nullableString($row->description),
                'total_likes' => (int) $row->total_likes,
                'total_comments' => (int) ($row->total_comments ?? 0),
                'is_liked' => $userUuid !== null ? $likedCollectionMap->has((string) $row->uuid) : false,
                'images' => $collectionImages,
                'author' => [
                    'uuid' => $this->nullableString($row->author_uuid),
                    'name' => $this->nullableString($row->author_name),
                    'avatar_url' => $this->resolveAvatarUrl($row->author_avatar_url),
                    'bio' => $this->nullableString($row->author_bio),
                    'username' => null,
                    'is_followed' => $userUuid !== null ? $followedAuthorMap->has((string) $row->author_uuid) : false,
                    'followers' => $followCounts[(string) $row->author_uuid]['followers'] ?? 0,
                    'following' => $followCounts[(string) $row->author_uuid]['following'] ?? 0,
                ],
                'topic' => [
                    'id' => $row->topic_id !== null ? (int) $row->topic_id : null,
                    'name' => $this->nullableString($row->topic_name),
                ],
                'created_at' => $this->nullableString($row->created_at),
                'created_at_human' => $this->humanizeDateTime($row->created_at),
                'updated_at' => $this->nullableString($row->updated_at),
                'updated_at_human' => $this->humanizeDateTime($row->updated_at),
                'latest_comment' => $latestCommentsByCollection->get($row->uuid, null),
            ];
        })->values();

        return [
            'items' => $items,
            'total' => $total,
        ];
    }

    /**
     * @param list<string> $likedCollectionUuids
     * @return list<int>
     */
    private function getPreferredTopicIds(?string $userUuid, array $likedCollectionUuids): array
    {
        if ($userUuid === null) {
            return [];
        }

        $storedTopicIds = DB::table('user_interested')
            ->where('user_uuid', $userUuid)
            ->orderByDesc('id')
            ->value('topic_ids');

        $interestedTopicIds = collect(explode(',', (string) $storedTopicIds))
            ->map(fn($id) => (int) trim($id))
            ->filter(fn($id) => $id > 0)
            ->values()
            ->all();

        $likedTopicIds = count($likedCollectionUuids) > 0
            ? DB::table('collections')
                ->whereIn('uuid', $likedCollectionUuids)
                ->pluck('topic_id')
                ->map(fn($id) => (int) $id)
                ->filter(fn($id) => $id > 0)
                ->values()
                ->all()
            : [];

        return collect(array_merge($interestedTopicIds, $likedTopicIds))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    private function getUnsplashCollectionFeed(int $limit, ?string $userUuid, ?int $topicId = null): Collection
    {
        $params = [
            'count' => min($limit, 30),
        ];

        if ($topicId !== null) {
            $topicName = $this->nullableString(DB::table('topics')->where('id', $topicId)->value('name'));
            $queryText = $topicName;
        } else {
            $queryText = $this->buildUnsplashQueryFromUser($userUuid);
        }

        if ($queryText !== null) {
            $params['query'] = $queryText;
        }

        $response = $this->callUnsplash('/photos/random', $params);
        if ($response === null || $response->failed()) {
            return collect();
        }

        $rows = $response->json();
        if (!is_array($rows)) {
            return collect();
        }

        if (isset($rows['id'])) {
            $rows = [$rows];
        }

        return collect($rows)->map(function (array $photo) use ($userUuid): array {
            $rawId = $this->nullableString($photo['id'] ?? null);

            $isLiked = false;
            if ($userUuid !== null && $rawId !== null) {
                $isLiked = DB::table('likes')
                    ->where('user_uuid', $userUuid)
                    ->where('collection_uuid', $rawId)
                    ->exists();
            }

            $collections = $photo['collections'] ?? [];
            if (is_array($collections) && count($collections) > 0) {
                $firstCollection = $collections[0];
                $collectionUuid = $this->nullableString($firstCollection['id'] ?? null);

                $collectionIsLiked = false;
                if ($userUuid !== null && $collectionUuid !== null) {
                    $collectionIsLiked = DB::table('likes')
                        ->where('user_uuid', $userUuid)
                        ->where('collection_uuid', $collectionUuid)
                        ->exists();
                }

                return [
                    'uuid' => $collectionUuid,
                    'title' => $this->nullableString($firstCollection['title'] ?? null),
                    'description' => $this->nullableString($firstCollection['description'] ?? null),
                    'total_likes' => isset($firstCollection['total_likes']) ? (int) $firstCollection['total_likes'] : 0,
                    'total_comments' => 0,
                    'is_liked' => $collectionIsLiked,
                    'images' => [
                        [
                            'uuid' => $rawId,
                            'color' => $photo['color'] ?? null,
                            'width' => isset($photo['width']) ? (int) $photo['width'] : null,
                            'height' => isset($photo['height']) ? (int) $photo['height'] : null,
                            'url_small' => $this->nullableString(($photo['urls'] ?? [])['small'] ?? null),
                            'url_regular' => $this->nullableString(($photo['urls'] ?? [])['regular'] ?? null),
                            'url_full' => $this->nullableString(($photo['urls'] ?? [])['full'] ?? null),
                            'download_url' => $this->nullableString(($photo['links'] ?? [])['download_location'] ?? ($photo['links'] ?? [])['download'] ?? ($photo['urls'] ?? [])['full'] ?? null),
                        ]
                    ],
                    'author' => [
                        'uuid' => $this->nullableString(($photo['user'] ?? [])['id'] ?? null),
                        'name' => $this->nullableString(($photo['user'] ?? [])['name'] ?? null),
                        'avatar_url' => $this->resolveAvatarUrl((($photo['user'] ?? [])['profile_image'] ?? [])['medium'] ?? null),
                        'bio' => $this->nullableString(($photo['user'] ?? [])['bio'] ?? null),
                        'is_followed' => false,
                        'followers' => 0,
                        'following' => 0,
                    ],
                    'topic' => [
                        'id' => null,
                        'name' => null,
                    ],
                    'created_at' => $this->nullableString($firstCollection['created_at'] ?? null),
                    'created_at_human' => $this->humanizeDateTime($firstCollection['created_at'] ?? null),
                    'updated_at' => $this->nullableString($firstCollection['updated_at'] ?? null),
                    'updated_at_human' => $this->humanizeDateTime($firstCollection['updated_at'] ?? null),
                    'latest_comment' => null,
                ];
            }

            $createdAt = $this->nullableString($photo['created_at'] ?? null);
            $updatedAt = $this->nullableString($photo['updated_at'] ?? null);
            $user = $photo['user'] ?? [];
            $userProfileImage = $user['profile_image'] ?? [];

            return [
                'uuid' => $rawId,
                'title' => $this->nullableString($photo['alt_description'] ?? $photo['description'] ?? null),
                'description' => $this->nullableString($photo['description'] ?? $photo['alt_description'] ?? null),
                'total_likes' => isset($photo['likes']) ? (int) $photo['likes'] : null,
                'total_comments' => 0,
                'is_liked' => $isLiked,
                'images' => [
                    [
                        'uuid' => $rawId,
                        'color' => $photo['color'] ?? null,
                        'width' => isset($photo['width']) ? (int) $photo['width'] : null,
                        'height' => isset($photo['height']) ? (int) $photo['height'] : null,
                        'url_small' => $this->nullableString(($photo['urls'] ?? [])['small'] ?? null),
                        'url_regular' => $this->nullableString(($photo['urls'] ?? [])['regular'] ?? null),
                        'url_full' => $this->nullableString(($photo['urls'] ?? [])['full'] ?? null),
                        'download_url' => $this->nullableString(($photo['links'] ?? [])['download_location'] ?? ($photo['links'] ?? [])['download'] ?? ($photo['urls'] ?? [])['full'] ?? null),
                    ]
                ],
                'author' => [
                    'uuid' => $this->nullableString($user['id'] ?? null),
                    'name' => $this->nullableString($user['name'] ?? null),
                    'avatar_url' => $this->resolveAvatarUrl($userProfileImage['medium'] ?? null),
                    'bio' => $this->nullableString($user['bio'] ?? null),
                    'username' => $this->nullableString($user['username'] ?? null),
                    'is_followed' => false,
                    'followers' => 0,
                    'following' => 0,
                ],
                'topic' => [
                    'id' => null,
                    'name' => null,
                ],
                'created_at' => $createdAt,
                'created_at_human' => $this->humanizeDateTime($createdAt),
                'updated_at' => $updatedAt,
                'updated_at_human' => $this->humanizeDateTime($updatedAt),
                'latest_comment' => null,
            ];
        })->values();
    }

    /**
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>|null
     */
    public function getExploreByCollection(string $collectionUuid, int $limit = 12, int $offset = 0, ?string $userUuid = null): ?Collection
    {
        $collection = DB::table('collections')
            ->where('uuid', $collectionUuid)
            ->first();

        if ($collection === null) {
            $unsplashCollectionImages = $this->getUnsplashCollectionSourceImages($collectionUuid);

            if ($unsplashCollectionImages !== null) {
                return $this->buildExploreFromSourceImages($unsplashCollectionImages, $collectionUuid, null, $limit, $offset, $userUuid);
            }

            $fallbackImage = (object) [
                'uuid' => $collectionUuid,
                'color' => null,
            ];

            return $this->buildExploreFromSourceImages(collect([$fallbackImage]), $collectionUuid, null, $limit, $offset, $userUuid);

            return null;
        }

        $topicId = $collection->topic_id !== null ? (int) $collection->topic_id : null;

        $sourceImages = DB::table('images')
            ->select(['uuid', 'color'])
            ->where('collection_uuid', $collectionUuid)
            ->get();

        return $this->buildExploreFromSourceImages($sourceImages, $collectionUuid, $topicId, $limit, $offset, $userUuid);
    }

    /**
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    private function buildExploreFromSourceImages(Collection $sourceImages, string $excludeCollectionUuid, ?int $topicId, int $limit, int $offset, ?string $userUuid = null): Collection
    {
        if ($sourceImages->isEmpty()) {
            return collect();
        }

        $targetCount = $offset + $limit;
        $perImage = (int) ceil(max($targetCount, $limit) * 2 / max(1, $sourceImages->count()));
        $collected = collect();

        foreach ($sourceImages as $image) {
            $imageUuid = $this->nullableString(is_array($image) ? ($image['uuid'] ?? null) : ($image->uuid ?? null));
            $sourceColor = $this->nullableString(is_array($image) ? ($image['color'] ?? null) : ($image->color ?? null));

            if ($imageUuid === null) {
                continue;
            }

            $dbItems = $this->getExploreFromDb($imageUuid, $excludeCollectionUuid, $topicId, $sourceColor, $perImage);
            $unsplashItems = $this->getExploreFromUnsplash($topicId, $sourceColor, $perImage, $userUuid);

            $collected = $collected->concat($dbItems)->concat($unsplashItems);
        }

        $collectionUuids = $collected
            ->map(fn($item) => $item['collection']['uuid'] ?? $item['uuid'] ?? null)
            ->filter()
            ->unique()
            ->shuffle()
            ->values();

        if ($collectionUuids->count() < $targetCount) {
            $need = $targetCount - $collectionUuids->count();

            $fillUuids = DB::table('collections as c')
                ->select('c.uuid')
                ->where('c.uuid', '!=', $excludeCollectionUuid)
                ->whereNotIn('c.uuid', $collectionUuids->all())
                ->when($topicId !== null, fn($q) => $q->where('c.topic_id', $topicId))
                ->orderByDesc('c.total_likes')
                ->orderByDesc('c.created_at')
                ->limit($need)
                ->pluck('uuid')
                ->map(fn($uuid) => $this->nullableString($uuid))
                ->filter()
                ->values();

            if ($fillUuids->isNotEmpty()) {
                $collectionUuids = $collectionUuids->concat($fillUuids)->unique()->values();
            }
        }

        if ($collectionUuids->count() < $targetCount) {
            $need = $targetCount - $collectionUuids->count();

            $fillUuids = DB::table('collections as c')
                ->select('c.uuid')
                ->where('c.uuid', '!=', $excludeCollectionUuid)
                ->whereNotIn('c.uuid', $collectionUuids->all())
                ->when($topicId !== null, fn($q) => $q->where(function ($query) use ($topicId): void {
                    $query->where('c.topic_id', '!=', $topicId)->orWhereNull('c.topic_id');
                }))
                ->orderByDesc('c.total_likes')
                ->orderByDesc('c.created_at')
                ->limit($need)
                ->pluck('uuid')
                ->map(fn($uuid) => $this->nullableString($uuid))
                ->filter()
                ->values();

            if ($fillUuids->isNotEmpty()) {
                $collectionUuids = $collectionUuids->concat($fillUuids)->unique()->values();
            }
        }

        $pageUuids = $collectionUuids->slice($offset, $limit)->values();

        if ($pageUuids->isEmpty()) {
            return $collected->unique('uuid')->shuffle()->slice($offset, $limit)->values();
        }

        $items = $this->getCollectionsByUuids($pageUuids->all(), $userUuid);

        if ($items->count() < $limit) {
            $remaining = $limit - $items->count();
            $leftover = $collected
                ->filter(fn($item) => !isset($item['collection']['uuid']) || in_array($item['collection']['uuid'], $pageUuids->all(), true) === false)
                ->unique('uuid')
                ->shuffle()
                ->take($remaining)
                ->values();

            if ($leftover->isNotEmpty()) {
                $items = $items->concat($leftover)->values();
            }
        }

        return $items;
    }

    /**
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    private function getExploreFromDb(string $excludeImageUuid, string $excludeCollectionUuid, ?int $topicId, ?string $sourceColor, int $limit): Collection
    {
        $sourceRgb = $this->hexToRgb($sourceColor);

        $rowsQueryBase = DB::table('images as i')
            ->join('collections as c', 'c.uuid', '=', 'i.collection_uuid')
            ->join('users as u', 'u.uuid', '=', 'c.user_uuid')
            ->leftJoin('topics as t', 't.id', '=', 'c.topic_id')
            ->select([
                'i.uuid',
                'i.color',
                'i.width',
                'i.height',
                'i.url_small',
                'i.url_regular',
                'i.url_full',
                'i.download_url',
                'i.created_at',
                'c.uuid as collection_uuid',
                'c.title as collection_title',
                'c.description as collection_description',
                'c.total_likes as collection_total_likes',
                DB::raw('(SELECT COUNT(*) FROM comments cm WHERE cm.collection_uuid = c.uuid) as collection_total_comments'),
                'c.created_at as collection_created_at',
                'c.updated_at as collection_updated_at',
                'u.uuid as author_uuid',
                'u.name as author_name',
                'u.avatar_url as author_avatar_url',
                'u.bio as author_bio',
                't.id as topic_id',
                't.name as topic_name',
            ])
            ->where('i.uuid', '!=', $excludeImageUuid)
            ->where('i.collection_uuid', '!=', $excludeCollectionUuid);

        $rowsQuery = clone $rowsQueryBase;
        if ($topicId !== null) {
            $rowsQuery->where('c.topic_id', $topicId);
        }

        $rows = $rowsQuery
            ->orderByDesc('c.total_likes')
            ->limit($limit * 3)
            ->get();

        if ($rows->isEmpty() && $topicId !== null) {
            $rows = $rowsQueryBase->orderByDesc('c.total_likes')->limit($limit * 3)->get();
        }

        if ($rows->isEmpty()) {
            return collect();
        }

        if ($sourceRgb !== null) {
            $rows = $rows->sortBy(function (object $row) use ($sourceRgb): float {
                $rgb = $this->hexToRgb($this->nullableString($row->color));
                if ($rgb === null) {
                    return PHP_FLOAT_MAX;
                }
                return $this->colorDistance($sourceRgb, $rgb);
            })->values();
        }

        $rows = $rows->take($limit)->values();

        $authorUuids = $rows->pluck('author_uuid')->map(fn($uuid) => $this->nullableString($uuid))->filter()->unique()->values()->all();
        $followCounts = $this->getFollowCountsBatch($authorUuids);

        return $rows->map(function (object $row) use ($followCounts): array {
            return [
                'uuid' => $this->nullableString($row->uuid),
                'color' => $row->color,
                'width' => $row->width !== null ? (int) $row->width : null,
                'height' => $row->height !== null ? (int) $row->height : null,
                'url_small' => $this->nullableString($row->url_small),
                'url_regular' => $this->nullableString($row->url_regular),
                'url_full' => $this->nullableString($row->url_full),
                'download_url' => $this->nullableString($row->download_url ?? $row->url_full),
                'author' => [
                    'uuid' => $this->nullableString($row->author_uuid),
                    'name' => $this->nullableString($row->author_name),
                    'avatar_url' => $this->resolveAvatarUrl($row->author_avatar_url),
                    'bio' => $this->nullableString($row->author_bio),
                    'username' => null,
                    'followers' => $followCounts[(string) $row->author_uuid]['followers'] ?? 0,
                    'following' => $followCounts[(string) $row->author_uuid]['following'] ?? 0,
                ],
                'collection' => [
                    'uuid' => $this->nullableString($row->collection_uuid),
                    'title' => $this->nullableString($row->collection_title),
                    'description' => $this->nullableString($row->collection_description),
                    'total_likes' => $row->collection_total_likes !== null ? (int) $row->collection_total_likes : null,
                    'total_comments' => $row->collection_total_comments !== null ? (int) $row->collection_total_comments : null,
                    'created_at' => $this->nullableString($row->collection_created_at),
                    'created_at_human' => $this->humanizeDateTime($row->collection_created_at),
                    'updated_at' => $this->nullableString($row->collection_updated_at),
                    'updated_at_human' => $this->humanizeDateTime($row->collection_updated_at),
                ],
                'topic' => [
                    'id' => $row->topic_id !== null ? (int) $row->topic_id : null,
                    'name' => $this->nullableString($row->topic_name),
                ],
                'created_at' => $this->nullableString($row->created_at),
                'created_at_human' => $this->humanizeDateTime($row->created_at),
            ];
        })->values();
    }

    /**
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    private function getExploreFromUnsplash(?int $topicId, ?string $sourceColor, int $limit, ?string $userUuid = null): Collection
    {
        $queryParts = [];

        if ($topicId !== null) {
            $topicName = $this->nullableString(DB::table('topics')->where('id', $topicId)->value('name'));
            if ($topicName !== null) {
                $queryParts[] = $topicName;
            }
        }

        if ($sourceColor !== null) {
            $colorKeyword = $this->hexToColorKeyword($sourceColor);
            if ($colorKeyword !== null) {
                $queryParts[] = $colorKeyword;
            }
        }

        $params = ['count' => min($limit, 30)];

        if (count($queryParts) > 0) {
            $params['query'] = implode(' ', $queryParts);
        }

        if ($sourceColor !== null) {
            $unsplashColor = $this->hexToUnsplashColor($sourceColor);
            if ($unsplashColor !== null) {
                $params['color'] = $unsplashColor;
            }
        }

        $response = $this->callUnsplash('/photos/random', $params);
        if ($response === null || $response->failed()) {
            return collect();
        }

        $rows = $response->json();
        if (!is_array($rows)) {
            return collect();
        }

        if (isset($rows['id'])) {
            $rows = [$rows];
        }

        return collect($rows)->map(function (array $photo) use ($userUuid): array {
            $rawId = $this->nullableString($photo['id'] ?? null);
            $createdAt = $this->nullableString($photo['created_at'] ?? null);
            $updatedAt = $this->nullableString($photo['updated_at'] ?? null);

            $isLiked = false;
            if ($userUuid !== null && $rawId !== null) {
                $isLiked = DB::table('likes')
                    ->where('user_uuid', $userUuid)
                    ->where('collection_uuid', $rawId)
                    ->exists();
            }

            $user = $photo['user'] ?? [];
            $userProfileImage = $user['profile_image'] ?? [];

            return [
                'uuid' => $rawId,
                'title' => $this->nullableString($photo['alt_description'] ?? $photo['description'] ?? null),
                'description' => $this->nullableString($photo['description'] ?? $photo['alt_description'] ?? null),
                'total_likes' => isset($photo['likes']) ? (int) $photo['likes'] : null,
                'total_comments' => null,
                'is_liked' => $isLiked,
                'images' => [
                    [
                        'uuid' => $rawId,
                        'color' => $photo['color'] ?? null,
                        'width' => isset($photo['width']) ? (int) $photo['width'] : null,
                        'height' => isset($photo['height']) ? (int) $photo['height'] : null,
                        'url_small' => $this->nullableString(($photo['urls'] ?? [])['small'] ?? null),
                        'url_regular' => $this->nullableString(($photo['urls'] ?? [])['regular'] ?? null),
                        'url_full' => $this->nullableString(($photo['urls'] ?? [])['full'] ?? null),
                        'download_url' => $this->nullableString(($photo['links'] ?? [])['download_location'] ?? ($photo['links'] ?? [])['download'] ?? ($photo['urls'] ?? [])['full'] ?? null),
                    ]
                ],
                'author' => [
                    'uuid' => $this->nullableString($user['id'] ?? null),
                    'name' => $this->nullableString($user['name'] ?? null),
                    'avatar_url' => $this->resolveAvatarUrl($userProfileImage['medium'] ?? null),
                    'bio' => $this->nullableString($user['bio'] ?? null),
                    'username' => $this->nullableString($user['username'] ?? null),
                    'is_followed' => false,
                    'followers' => 0,
                    'following' => 0,
                ],
                'topic' => [
                    'id' => null,
                    'name' => $this->extractUnsplashTopicName($photo),
                ],
                'created_at' => $createdAt,
                'created_at_human' => $this->humanizeDateTime($createdAt),
                'updated_at' => $updatedAt,
                'updated_at_human' => $this->humanizeDateTime($updatedAt),
            ];
        })->values();
    }

    /**
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    private function getUnsplashCollectionSourceImages(string $collectionUuid): ?Collection
    {
        $response = $this->callUnsplash('/collections/' . $collectionUuid . '/photos', [
            'per_page' => 30,
            'page' => 1,
        ]);
        if ($response === null || $response->failed()) {
            return null;
        }

        $rows = $response->json();
        if (!is_array($rows)) {
            return null;
        }

        return collect($rows)
            ->map(function (array $photo): ?object {
                $uuid = $this->nullableString($photo['id'] ?? null);
                if ($uuid === null) {
                    return null;
                }

                return (object) [
                    'uuid' => $uuid,
                    'color' => $photo['color'] ?? null,
                ];
            })
            ->filter()
            ->values();
    }

    /**
     * @param list<string> $collectionUuids
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    public function getCollectionsByUuids(array $collectionUuids, ?string $userUuid = null): Collection
    {
        if (count($collectionUuids) === 0) {
            return collect();
        }

        $rows = DB::table('collections as c')
            ->join('users as u', 'u.uuid', '=', 'c.user_uuid')
            ->leftJoin('topics as t', 't.id', '=', 'c.topic_id')
            ->select([
                'c.uuid',
                'c.title',
                'c.description',
                'c.total_likes',
                DB::raw('(SELECT COUNT(*) FROM comments cm WHERE cm.collection_uuid = c.uuid) as total_comments'),
                'c.created_at',
                'c.updated_at',
                'u.uuid as author_uuid',
                'u.name as author_name',
                'u.avatar_url as author_avatar_url',
                'u.bio as author_bio',
                't.id as topic_id',
                't.name as topic_name',
            ])
            ->whereIn('c.uuid', $collectionUuids)
            ->get();

        if ($rows->isEmpty()) {
            return collect();
        }

        $rowsMap = $rows->keyBy('uuid');
        $collectionUuidsQuery = $rows->pluck('uuid')->map(fn($uuid) => $this->nullableString($uuid))->filter()->values()->all();

        $imagesByCollection = DB::table('images')
            ->select(['uuid', 'color', 'width', 'height', 'url_small', 'url_regular', 'url_full', 'download_url', 'collection_uuid'])
            ->whereIn('collection_uuid', $collectionUuidsQuery)
            ->orderBy('created_at')
            ->get()
            ->groupBy('collection_uuid');

        $likedCollectionUuids = $userUuid !== null
            ? DB::table('likes')
                ->where('user_uuid', $userUuid)
                ->pluck('collection_uuid')
                ->map(fn($uuid) => $this->nullableString($uuid))
                ->filter()
                ->unique()
                ->values()
                ->all()
            : [];

        $likedCollectionMap = collect($likedCollectionUuids)->flip();

        $followedAuthorUuids = $userUuid !== null
            ? DB::table('followers')
                ->where('user_uuid', $userUuid)
                ->pluck('author_uuid')
                ->map(fn($uuid) => $this->nullableString($uuid))
                ->filter()
                ->unique()
                ->values()
                ->all()
            : [];

        $followedAuthorMap = collect($followedAuthorUuids)->flip();

        $authorUuids = $rows->pluck('author_uuid')->map(fn($uuid) => $this->nullableString($uuid))->filter()->unique()->values()->all();
        $followCounts = $this->getFollowCountsBatch($authorUuids);

        return collect($collectionUuids)->map(function (string $uuid) use ($rowsMap, $imagesByCollection, $likedCollectionMap, $followedAuthorMap, $followCounts, $userUuid): ?array {
            $row = $rowsMap->get($uuid);
            if ($row === null) {
                return null;
            }

            $collectionImages = $imagesByCollection->get($row->uuid, collect())->map(function (object $image): array {
                return [
                    'uuid' => $this->nullableString($image->uuid),
                    'color' => $image->color,
                    'width' => $image->width !== null ? (int) $image->width : null,
                    'height' => $image->height !== null ? (int) $image->height : null,
                    'url_small' => $this->nullableString($image->url_small),
                    'url_regular' => $this->nullableString($image->url_regular),
                    'url_full' => $this->nullableString($image->url_full),
                    'download_url' => $this->nullableString($image->download_url ?? $image->url_full),
                ];
            })->values();

            return [
                'uuid' => $this->nullableString($row->uuid),
                'title' => $this->nullableString($row->title),
                'description' => $this->nullableString($row->description),
                'total_likes' => (int) $row->total_likes,
                'total_comments' => (int) ($row->total_comments ?? 0),
                'is_liked' => $likedCollectionMap->has($uuid),
                'images' => $collectionImages,
                'author' => [
                    'uuid' => $this->nullableString($row->author_uuid),
                    'name' => $this->nullableString($row->author_name),
                    'avatar_url' => $this->resolveAvatarUrl($row->author_avatar_url),
                    'bio' => $this->nullableString($row->author_bio),
                    'username' => null,
                    'is_followed' => $userUuid !== null ? $followedAuthorMap->has((string) $row->author_uuid) : false,
                    'followers' => $followCounts[(string) $row->author_uuid]['followers'] ?? 0,
                    'following' => $followCounts[(string) $row->author_uuid]['following'] ?? 0,
                ],
                'topic' => [
                    'id' => $row->topic_id !== null ? (int) $row->topic_id : null,
                    'name' => $this->nullableString($row->topic_name),
                ],
                'created_at' => $this->nullableString($row->created_at),
                'created_at_human' => $this->humanizeDateTime($row->created_at),
                'updated_at' => $this->nullableString($row->updated_at),
                'updated_at_human' => $this->humanizeDateTime($row->updated_at),
            ];
        })->filter()->values();
    }

    public function getUnsplashCollectionsByUuids(array $collectionUuids, ?string $userUuid = null): Collection
    {
        if (count($collectionUuids) === 0) {
            return collect();
        }

        return collect($collectionUuids)
            ->map(fn(string $collectionUuid) => $this->getUnsplashCollectionByUuid($collectionUuid, $userUuid))
            ->filter()
            ->values();
    }

    private function getUnsplashCollectionByUuid(string $collectionUuid, ?string $userUuid = null): ?array
    {
        $response = $this->callUnsplash('/collections/' . $collectionUuid);
        if ($response !== null && $response->successful() && is_array($response->json())) {
            $collection = $response->json();
            return $this->buildUnsplashCollectionItem($collection, $userUuid);
        }

        $response = $this->callUnsplash('/photos/' . $collectionUuid);
        if ($response !== null && $response->successful() && is_array($response->json())) {
            $photo = $response->json();
            return $this->buildUnsplashPhotoItem($photo, $userUuid);
        }

        return null;
    }

    private function buildUnsplashPhotoItem(array $photo, ?string $userUuid = null): ?array
    {
        $photoUuid = $this->nullableString($photo['id'] ?? null);
        if ($photoUuid === null) {
            return null;
        }

        $isLiked = false;
        if ($userUuid !== null) {
            $isLiked = DB::table('likes')
                ->where('user_uuid', $userUuid)
                ->where('collection_uuid', $photoUuid)
                ->exists();
        }

        $images = collect([
            [
                'uuid' => $photoUuid,
                'color' => $photo['color'] ?? null,
                'width' => isset($photo['width']) ? (int) $photo['width'] : null,
                'height' => isset($photo['height']) ? (int) $photo['height'] : null,
                'url_small' => $this->nullableString(($photo['urls'] ?? [])['small'] ?? null),
                'url_regular' => $this->nullableString(($photo['urls'] ?? [])['regular'] ?? null),
                'url_full' => $this->nullableString(($photo['urls'] ?? [])['full'] ?? null),
                'download_url' => $this->nullableString(($photo['links'] ?? [])['download_location'] ?? ($photo['links'] ?? [])['download'] ?? ($photo['urls'] ?? [])['full'] ?? null),
            ]
        ]);

        $user = $photo['user'] ?? [];
        $userProfileImage = $user['profile_image'] ?? [];

        return [
            'uuid' => $photoUuid,
            'title' => $this->nullableString($photo['alt_description'] ?? $photo['description'] ?? null),
            'description' => $this->nullableString($photo['description'] ?? $photo['alt_description'] ?? null),
            'total_likes' => isset($photo['likes']) ? (int) $photo['likes'] : 0,
            'total_comments' => 0,
            'is_liked' => $isLiked,
            'images' => $images,
            'author' => [
                'uuid' => $this->nullableString($user['id'] ?? null),
                'name' => $this->nullableString($user['name'] ?? null),
                'avatar_url' => $this->resolveAvatarUrl($userProfileImage['medium'] ?? null),
                'bio' => $this->nullableString($user['bio'] ?? null),
                'username' => $this->nullableString($user['username'] ?? null),
                'is_followed' => false,
                'followers' => 0,
                'following' => 0,
            ],
            'topic' => [
                'id' => null,
                'name' => null,
            ],
            'created_at' => $this->nullableString($photo['created_at'] ?? null),
            'created_at_human' => $this->humanizeDateTime($photo['created_at'] ?? null),
            'updated_at' => $this->nullableString($photo['updated_at'] ?? null),
            'updated_at_human' => $this->humanizeDateTime($photo['updated_at'] ?? null),
            'latest_comment' => null,
        ];
    }

    private function buildUnsplashCollectionItem(array $collection, ?string $userUuid = null): ?array
    {
        $collectionUuid = $this->nullableString($collection['id'] ?? null);
        if ($collectionUuid === null) {
            return null;
        }

        $isLiked = false;
        if ($userUuid !== null) {
            $isLiked = DB::table('likes')
                ->where('user_uuid', $userUuid)
                ->where('collection_uuid', $collectionUuid)
                ->exists();
        }

        $images = collect();
        $coverPhoto = null;

        if (isset($collection['cover_photo']) && is_array($collection['cover_photo'])) {
            $coverPhoto = $collection['cover_photo'];
        } elseif (isset($collection['preview_photos'][0]) && is_array($collection['preview_photos'][0])) {
            $coverPhoto = $collection['preview_photos'][0];
        }

        if ($coverPhoto !== null) {
            $images = collect([$coverPhoto])->map(function (array $photo): array {
                return [
                    'uuid' => $this->nullableString($photo['id'] ?? null),
                    'color' => $photo['color'] ?? null,
                    'width' => isset($photo['width']) ? (int) $photo['width'] : null,
                    'height' => isset($photo['height']) ? (int) $photo['height'] : null,
                    'url_small' => $this->nullableString(($photo['urls'] ?? [])['small'] ?? null),
                    'url_regular' => $this->nullableString(($photo['urls'] ?? [])['regular'] ?? null),
                    'url_full' => $this->nullableString(($photo['urls'] ?? [])['full'] ?? null),
                    'download_url' => $this->nullableString(($photo['links'] ?? [])['download_location'] ?? ($photo['links'] ?? [])['download'] ?? ($photo['urls'] ?? [])['full'] ?? null),
                ];
            })->values();
        }

        $user = $collection['user'] ?? [];
        $userProfileImage = $user['profile_image'] ?? [];

        return [
            'uuid' => $collectionUuid,
            'title' => $this->nullableString($collection['title'] ?? null),
            'description' => $this->nullableString($collection['description'] ?? null),
            'total_likes' => isset($collection['total_photos']) ? (int) $collection['total_photos'] : 0,
            'total_comments' => 0,
            'is_liked' => $isLiked,
            'images' => $images,
            'author' => [
                'uuid' => $this->nullableString($user['id'] ?? null),
                'name' => $this->nullableString($user['name'] ?? null),
                'avatar_url' => $this->resolveAvatarUrl($userProfileImage['medium'] ?? null),
                'bio' => $this->nullableString($user['bio'] ?? null),
                'username' => $this->nullableString($user['username'] ?? null),
                'is_followed' => false,
                'followers' => 0,
                'following' => 0,
            ],
            'topic' => [
                'id' => null,
                'name' => null,
            ],
            'created_at' => $this->nullableString($collection['published_at'] ?? $collection['created_at'] ?? null),
            'created_at_human' => $this->humanizeDateTime($collection['published_at'] ?? $collection['created_at'] ?? null),
            'updated_at' => $this->nullableString($collection['updated_at'] ?? null),
            'updated_at_human' => $this->humanizeDateTime($collection['updated_at'] ?? null),
            'latest_comment' => null,
        ];
    }

    /**
     * @return array{r: int, g: int, b: int}|null
     */
    private function hexToRgb(?string $hex): ?array
    {
        if ($hex === null) {
            return null;
        }

        $hex = ltrim($hex, '#');

        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }

        if (strlen($hex) !== 6) {
            return null;
        }

        return [
            'r' => hexdec(substr($hex, 0, 2)),
            'g' => hexdec(substr($hex, 2, 2)),
            'b' => hexdec(substr($hex, 4, 2)),
        ];
    }

    /**
     * @param array{r: int, g: int, b: int} $a
     * @param array{r: int, g: int, b: int} $b
     */
    private function colorDistance(array $a, array $b): float
    {
        return sqrt(
            ($a['r'] - $b['r']) ** 2 +
            ($a['g'] - $b['g']) ** 2 +
            ($a['b'] - $b['b']) ** 2
        );
    }

    private function hexToColorKeyword(?string $hex): ?string
    {
        $rgb = $this->hexToRgb($hex);
        if ($rgb === null) {
            return null;
        }

        $r = $rgb['r'];
        $g = $rgb['g'];
        $b = $rgb['b'];
        $max = max($r, $g, $b);
        $min = min($r, $g, $b);
        $lightness = ($max + $min) / 2;

        if ($lightness < 40) {
            return 'dark';
        }

        if ($lightness > 200) {
            return 'light';
        }

        if ($r > $g && $r > $b) {
            return 'red';
        }

        if ($g > $r && $g > $b) {
            return 'green';
        }

        if ($b > $r && $b > $g) {
            return 'blue';
        }

        if ($r > 180 && $g > 180 && $b < 100) {
            return 'yellow';
        }

        if ($r > 180 && $g < 100 && $b > 180) {
            return 'purple';
        }

        if ($r > 180 && $g > 100 && $b < 80) {
            return 'orange';
        }

        return null;
    }

    private function hexToUnsplashColor(?string $hex): ?string
    {
        $keyword = $this->hexToColorKeyword($hex);

        $map = [
            'dark' => 'black',
            'light' => 'white',
            'red' => 'red',
            'green' => 'green',
            'blue' => 'blue',
            'yellow' => 'yellow',
            'purple' => 'purple',
            'orange' => 'orange',
        ];

        return $keyword !== null ? ($map[$keyword] ?? null) : null;
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $stringValue = trim((string) $value);
        return $stringValue === '' ? null : $stringValue;
    }

    private function humanizeDateTime(mixed $value): ?string
    {
        $dateTime = $this->nullableString($value);
        if ($dateTime === null) {
            return null;
        }

        return Carbon::parse($dateTime)->diffForHumans();
    }

    private function resolveAvatarUrl(?string $avatarUrl): ?string
    {
        $url = $this->nullableString($avatarUrl);
        if ($url === null) {
            return null;
        }

        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            return $url;
        }

        $localPath = public_path(ltrim($url, '/'));
        if (file_exists($localPath)) {
            return $url;
        }

        $avatarDir = public_path('uploads/avatars');
        if (file_exists($avatarDir)) {
            $files = array_diff(scandir($avatarDir), ['.', '..']);
            $files = array_values($files);
            if (count($files) > 0) {
                $index = abs(crc32($url)) % count($files);
                return '/uploads/avatars/' . $files[$index];
            }
        }

        $seed = basename($url, '.jpg');
        return 'https://picsum.photos/seed/' . rawurlencode($seed) . '/300/300.jpg';
    }

    private function buildUnsplashQueryFromUser(?string $userUuid): ?string
    {
        if ($userUuid === null) {
            return null;
        }

        $likedCollectionUuids = DB::table('likes')
            ->where('user_uuid', $userUuid)
            ->pluck('collection_uuid')
            ->map(fn($id) => $this->nullableString($id))
            ->filter()
            ->values()
            ->all();

        $topicIds = $this->getPreferredTopicIds($userUuid, $likedCollectionUuids);
        if (count($topicIds) === 0) {
            return null;
        }

        $topicNames = DB::table('topics')
            ->whereIn('id', $topicIds)
            ->pluck('name')
            ->filter()
            ->values();

        if ($topicNames->isEmpty()) {
            return null;
        }

        return (string) $topicNames->random();
    }

    private function extractUnsplashTopicName(array $photo): ?string
    {
        $topicSubmissions = $photo['topic_submissions'] ?? [];
        if (!is_array($topicSubmissions) || count($topicSubmissions) === 0) {
            return null;
        }

        $firstKey = array_key_first($topicSubmissions);
        if ($firstKey === null) {
            return null;
        }

        return str_replace('-', ' ', (string) $firstKey);
    }

    /**
     * @param list<string> $collectionUuids
     * @return \Illuminate\Support\Collection<string, \Illuminate\Support\Collection<int, array<string, mixed>>>
     */
    private function getCommentsByCollection(array $collectionUuids): Collection
    {
        if (count($collectionUuids) === 0) {
            return collect();
        }

        return DB::table('comments as cm')
            ->join('users as u', 'u.uuid', '=', 'cm.user_uuid')
            ->select([
                'cm.id',
                'cm.collection_uuid',
                'cm.user_uuid',
                'cm.parent_id',
                'cm.context',
                'cm.created_at',
                'cm.updated_at',
                'u.name as user_name',
                'u.avatar_url as user_avatar_url',
                'u.bio as user_bio',
            ])
            ->whereIn('cm.collection_uuid', $collectionUuids)
            ->orderBy('cm.created_at')
            ->get()
            ->groupBy('collection_uuid')
            ->map(function (Collection $comments): Collection {
                return $comments->map(function (object $comment): array {
                    return [
                        'id' => (int) $comment->id,
                        'collection_uuid' => $this->nullableString($comment->collection_uuid),
                        'parent_id' => $comment->parent_id !== null ? (int) $comment->parent_id : null,
                        'context' => $this->nullableString($comment->context),
                        'user' => [
                            'uuid' => $this->nullableString($comment->user_uuid),
                            'name' => $this->nullableString($comment->user_name),
                            'avatar_url' => $this->resolveAvatarUrl($comment->user_avatar_url),
                            'bio' => $this->nullableString($comment->user_bio),
                        ],
                        'created_at' => $this->nullableString($comment->created_at),
                        'created_at_human' => $this->humanizeDateTime($comment->created_at),
                        'updated_at' => $this->nullableString($comment->updated_at),
                        'updated_at_human' => $this->humanizeDateTime($comment->updated_at),
                    ];
                })->values();
            });
    }

    /**
     * @param list<string> $collectionUuids
     * @return \Illuminate\Support\Collection<string, array<string, mixed>|null>
     */
    private function getLatestCommentsByCollections(array $collectionUuids): Collection
    {
        if (count($collectionUuids) === 0) {
            return collect();
        }

        $comments = DB::table('comments as cm')
            ->join('users as u', 'u.uuid', '=', 'cm.user_uuid')
            ->select([
                'cm.id',
                'cm.collection_uuid',
                'cm.user_uuid',
                'cm.parent_id',
                'cm.context',
                'cm.created_at',
                'cm.updated_at',
                'u.name as user_name',
                'u.avatar_url as user_avatar_url',
                'u.bio as user_bio',
            ])
            ->whereIn('cm.collection_uuid', $collectionUuids)
            ->orderByDesc('cm.created_at')
            ->get()
            ->groupBy('collection_uuid')
            ->map(function (Collection $itemComments): ?array {
                $latestComment = $itemComments->first();
                if ($latestComment === null) {
                    return null;
                }

                return [
                    'id' => (int) $latestComment->id,
                    'collection_uuid' => $this->nullableString($latestComment->collection_uuid),
                    'parent_id' => $latestComment->parent_id !== null ? (int) $latestComment->parent_id : null,
                    'context' => $this->nullableString($latestComment->context),
                    'user' => [
                        'uuid' => $this->nullableString($latestComment->user_uuid),
                        'name' => $this->nullableString($latestComment->user_name),
                        'avatar_url' => $this->resolveAvatarUrl($latestComment->user_avatar_url),
                        'bio' => $this->nullableString($latestComment->user_bio),
                    ],
                    'created_at' => $this->nullableString($latestComment->created_at),
                    'created_at_human' => $this->humanizeDateTime($latestComment->created_at),
                    'updated_at' => $this->nullableString($latestComment->updated_at),
                    'updated_at_human' => $this->humanizeDateTime($latestComment->updated_at),
                ];
            });

        $result = collect();
        foreach ($collectionUuids as $uuid) {
            $result[$uuid] = $comments->get($uuid, null);
        }

        return $result;
    }

    public function getCollectionCommentsByUuid(string $collectionUuid): ?Collection
    {
        return $this->getCommentsByCollection([$collectionUuid])
            ->get($collectionUuid, collect())
            ->values();
    }

    /**
     * @return array{items: \Illuminate\Support\Collection<int, array<string, mixed>>, total: int}
     */
    public function searchCollections(string $searchKey, int $limit = 12, int $offset = 0, ?string $userUuid = null): array
    {
        $searchKey = trim($searchKey);
        if ($searchKey === '') {
            return ['items' => collect(), 'total' => 0];
        }

        $like = '%' . $searchKey . '%';

        $totalQuery = DB::table('collections as c')
            ->join('users as u', 'u.uuid', '=', 'c.user_uuid')
            ->leftJoin('topics as t', 't.id', '=', 'c.topic_id')
            ->leftJoin('images as i', 'i.collection_uuid', '=', 'c.uuid')
            ->where(function ($q) use ($searchKey, $like) {
                $q->where('c.title', 'like', $like)
                    ->orWhere('c.description', 'like', $like)
                    ->orWhere('u.name', 'like', $like)
                    ->orWhere('t.name', 'like', $like)
                    ->orWhere('i.url_regular', 'like', $like);
            });

        $totalCollections = (int) $totalQuery->distinct('c.uuid')->count('c.uuid');

        $rowsQuery = DB::table('collections as c')
            ->join('users as u', 'u.uuid', '=', 'c.user_uuid')
            ->leftJoin('topics as t', 't.id', '=', 'c.topic_id')
            ->select([
                'c.uuid',
                'c.title',
                'c.description',
                'c.total_likes',
                DB::raw('(SELECT COUNT(*) FROM comments cm WHERE cm.collection_uuid = c.uuid) as total_comments'),
                'c.created_at',
                'c.updated_at',
                'u.uuid as author_uuid',
                'u.name as author_name',
                'u.avatar_url as author_avatar_url',
                'u.bio as author_bio',
                't.id as topic_id',
                't.name as topic_name',
            ])
            ->where(function ($q) use ($like) {
                $q->where('c.title', 'like', $like)
                    ->orWhere('c.description', 'like', $like)
                    ->orWhere('u.name', 'like', $like)
                    ->orWhere('t.name', 'like', $like);
            })
            ->orderByDesc('c.created_at')
            ->orderByDesc('c.uuid')
            ->offset($offset)
            ->limit($limit)
            ->get();

        $collections = collect();

        if ($rowsQuery->isNotEmpty()) {
            $collectionUuids = $rowsQuery->pluck('uuid')->map(fn($u) => $this->nullableString($u))->filter()->values()->all();

            $imagesByCollection = DB::table('images')
                ->select(['uuid', 'color', 'width', 'height', 'url_small', 'url_regular', 'url_full', 'download_url', 'collection_uuid'])
                ->whereIn('collection_uuid', $collectionUuids)
                ->orderBy('created_at')
                ->get()
                ->groupBy('collection_uuid');

            $likedCollectionUuids = $userUuid !== null
                ? DB::table('likes')->where('user_uuid', $userUuid)->pluck('collection_uuid')->map(fn($uuid) => $this->nullableString($uuid))->filter()->unique()->values()->all()
                : [];

            $likedCollectionMap = collect($likedCollectionUuids)->flip();

            $authorUuids = $rowsQuery->pluck('author_uuid')->map(fn($uuid) => $this->nullableString($uuid))->filter()->unique()->values()->all();
            $followCounts = $this->getFollowCountsBatch($authorUuids);

            $collections = $rowsQuery->map(function (object $row) use ($imagesByCollection, $likedCollectionMap, $followCounts) {
                $collectionImages = $imagesByCollection->get($row->uuid, collect())->map(function (object $image): array {
                    return [
                        'uuid' => $this->nullableString($image->uuid),
                        'color' => $image->color,
                        'width' => $image->width !== null ? (int) $image->width : null,
                        'height' => $image->height !== null ? (int) $image->height : null,
                        'url_small' => $this->nullableString($image->url_small),
                        'url_regular' => $this->nullableString($image->url_regular),
                        'url_full' => $this->nullableString($image->url_full),
                        'download_url' => $this->nullableString($image->download_url ?? $image->url_full),
                    ];
                })->values();

                return [
                    '_source' => 'db',
                    'uuid' => $this->nullableString($row->uuid),
                    'title' => $this->nullableString($row->title),
                    'description' => $this->nullableString($row->description),
                    'total_likes' => (int) $row->total_likes,
                    'total_comments' => (int) ($row->total_comments ?? 0),
                    'is_liked' => $likedCollectionMap->has((string) $row->uuid),
                    'images' => $collectionImages,
                    'author' => [
                        'uuid' => $this->nullableString($row->author_uuid),
                        'name' => $this->nullableString($row->author_name),
                        'avatar_url' => $this->nullableString($row->author_avatar_url),
                        'bio' => $this->nullableString($row->author_bio),
                        'username' => null,
                        'is_followed' => false,
                        'followers' => $followCounts[(string) $row->author_uuid]['followers'] ?? 0,
                        'following' => $followCounts[(string) $row->author_uuid]['following'] ?? 0,
                    ],
                    'topic' => [
                        'id' => $row->topic_id !== null ? (int) $row->topic_id : null,
                        'name' => $this->nullableString($row->topic_name),
                    ],
                    'created_at' => $this->nullableString($row->created_at),
                    'created_at_human' => $this->humanizeDateTime($row->created_at),
                    'updated_at' => $this->nullableString($row->updated_at),
                    'updated_at_human' => $this->humanizeDateTime($row->updated_at),
                ];
            })->values();
        }

        $photos = collect();
        if ($collections->count() < $limit) {
            $remaining = $limit - $collections->count();
            $photos = $this->searchUnsplashPhotos($searchKey, $remaining, $offset, $userUuid);
        }

        $merged = $collections->concat($photos)->values()->map(function ($item) {
            if (($item['_source'] ?? null) === 'unsplash') {
                $images = $item['images'] ?? [];
                $singleUuid = is_array($images) ? ($images[0]['uuid'] ?? null) : null;
                if ($singleUuid !== null) {
                    $item['uuid'] = $singleUuid;
                }
            }

            unset($item['_source']);
            return $item;
        })->values();

        return [
            'collections' => $merged->take($limit)->values(),
            'totals' => [
                'collections' => $totalCollections,
            ],
        ];
    }

    /**
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    private function searchUnsplashPhotos(string $queryText, int $limit, int $offset = 0, ?string $userUuid = null): Collection
    {
        $params = [
            'query' => $queryText,
            'per_page' => min($limit, 30),
            'page' => max(1, (int) floor($offset / max(1, $limit)) + 1),
        ];

        $response = $this->callUnsplash('/search/photos', $params);
        if ($response === null || $response->failed()) {
            return collect();
        }

        $json = $response->json();
        if (!is_array($json) || !isset($json['results'])) {
            return collect();
        }

        $rows = $json['results'];

        return collect($rows)->map(function (array $photo) use ($userUuid): array {
            $rawId = $this->nullableString($photo['id'] ?? null);
            $createdAt = $this->nullableString($photo['created_at'] ?? null);

            $isLiked = false;
            if ($userUuid !== null && $rawId !== null) {
                $isLiked = DB::table('likes')
                    ->where('user_uuid', $userUuid)
                    ->where('collection_uuid', $rawId)
                    ->exists();
            }

            return [
                '_source' => 'unsplash',
                'uuid' => $rawId,
                'title' => $this->nullableString($photo['alt_description'] ?? $photo['description'] ?? null),
                'description' => $this->nullableString($photo['description'] ?? $photo['alt_description'] ?? null),
                'total_likes' => isset($photo['likes']) ? (int) $photo['likes'] : null,
                'total_comments' => null,
                'is_liked' => $isLiked,
                'images' => [
                    [
                        'uuid' => $rawId,
                        'color' => $photo['color'] ?? null,
                        'width' => isset($photo['width']) ? (int) $photo['width'] : null,
                        'height' => isset($photo['height']) ? (int) $photo['height'] : null,
                        'url_small' => $this->nullableString($photo['urls']['small'] ?? null),
                        'url_regular' => $this->nullableString($photo['urls']['regular'] ?? null),
                        'url_full' => $this->nullableString($photo['urls']['full'] ?? null),
                        'download_url' => $this->nullableString($photo['links']['download_location'] ?? $photo['links']['download'] ?? $photo['urls']['full'] ?? null),
                    ]
                ],
                'author' => [
                    'uuid' => $this->nullableString($photo['user']['id'] ?? null),
                    'name' => $this->nullableString($photo['user']['name'] ?? null),
                    'avatar_url' => $this->nullableString($photo['user']['profile_image']['medium'] ?? null),
                    'bio' => $this->nullableString($photo['user']['bio'] ?? null),
                    'is_followed' => false,
                    'followers' => 0,
                    'following' => 0,
                ],
                'topic' => [
                    'id' => null,
                    'name' => $this->extractUnsplashTopicName($photo),
                ],
                'created_at' => $createdAt,
                'created_at_human' => $this->humanizeDateTime($createdAt),
                'updated_at' => $this->nullableString($photo['updated_at'] ?? null),
                'updated_at_human' => $this->humanizeDateTime($photo['updated_at'] ?? null),
            ];
        })->values();
    }

    public function getImageDetailByUuid(string $uuid): ?array
    {
        $row = DB::table('images as i')
            ->join('users as u', 'u.uuid', '=', 'i.user_uuid')
            ->join('collections as c', 'c.uuid', '=', 'i.collection_uuid')
            ->leftJoin('topics as t', 't.id', '=', 'c.topic_id')
            ->select([
                'i.uuid',
                'i.color',
                'i.width',
                'i.height',
                'i.url_small',
                'i.url_regular',
                'i.url_full',
                'i.download_url',
                'i.created_at as image_created_at',
                DB::raw('(SELECT COUNT(*) FROM likes l WHERE l.collection_uuid = i.collection_uuid) as image_total_likes'),
                'u.uuid as author_uuid',
                'u.name as author_name',
                'u.avatar_url as author_avatar_url',
                'u.bio as author_bio',
                'u.username as author_username',
                'c.uuid as collection_uuid',
                'c.title as collection_title',
                'c.description as collection_description',
                'c.total_likes as collection_total_likes',
                DB::raw('(SELECT COUNT(*) FROM comments cm WHERE cm.collection_uuid = c.uuid) as collection_total_comments'),
                'c.created_at as collection_created_at',
                'c.updated_at as collection_updated_at',
                't.id as topic_id',
                't.name as topic_name',
            ])
            ->where('i.uuid', $uuid)
            ->first();

        if ($row === null) {
            return null;
        }

        $followCounts = $this->getFollowCountsBatch([$row->author_uuid]);

        return [
            'uuid' => $this->nullableString($row->uuid),
            'color' => $row->color,
            'width' => $row->width !== null ? (int) $row->width : null,
            'height' => $row->height !== null ? (int) $row->height : null,
            'total_likes' => (int) $row->image_total_likes,
            'url_small' => $this->nullableString($row->url_small),
            'url_regular' => $this->nullableString($row->url_regular),
            'url_full' => $this->nullableString($row->url_full),
            'download_url' => $this->nullableString($row->download_url ?? $row->url_full),
            'author' => [
                'uuid' => $this->nullableString($row->author_uuid),
                'name' => $this->nullableString($row->author_name),
                'avatar_url' => $this->resolveAvatarUrl($row->author_avatar_url),
                'bio' => $this->nullableString($row->author_bio),
                'username' => $this->nullableString($row->author_username),
                'followers' => $followCounts[(string) $row->author_uuid]['followers'] ?? 0,
                'following' => $followCounts[(string) $row->author_uuid]['following'] ?? 0,
            ],
            'collection' => [
                'uuid' => $this->nullableString($row->collection_uuid),
                'title' => $this->nullableString($row->collection_title),
                'description' => $this->nullableString($row->collection_description),
                'total_likes' => $row->collection_total_likes !== null ? (int) $row->collection_total_likes : null,
                'total_comments' => $row->collection_total_comments !== null ? (int) $row->collection_total_comments : null,
                'created_at' => $this->nullableString($row->collection_created_at),
                'created_at_human' => $this->humanizeDateTime($row->collection_created_at),
                'updated_at' => $this->nullableString($row->collection_updated_at),
                'updated_at_human' => $this->humanizeDateTime($row->collection_updated_at),
            ],
            'topic' => [
                'id' => $row->topic_id !== null ? (int) $row->topic_id : null,
                'name' => $this->nullableString($row->topic_name),
            ],
            'created_at' => $this->nullableString($row->image_created_at),
        ];
    }

    public function getRandomUnsplashPhotoUrlForTopic(?int $topicId): ?string
    {
        if ($topicId === null) {
            return null;
        }

        $topicName = $this->nullableString(DB::table('topics')->where('id', $topicId)->value('name'));
        if ($topicName === null) {
            return null;
        }

        return 'https://picsum.photos/seed/' . rawurlencode($topicName) . '/600/400';
    }

    public static function getUserCollections(?string $userUuid, ?string $viewerUuid = null): Collection
    {
        if ($userUuid === null) {
            return collect();
        }

        $collectionUuids = DB::table('collections')
            ->where('user_uuid', $userUuid)
            ->orderByDesc('created_at')
            ->pluck('uuid')
            ->map(fn($uuid) => (string) $uuid)
            ->values()
            ->all();

        if (count($collectionUuids) === 0) {
            return collect();
        }

        return app(self::class)->getCollectionsByUuids($collectionUuids, $viewerUuid);
    }

    public function getAuthorCollections(string $authorUuid, ?int $limit = 12, ?int $offset = 0, ?string $viewerUuid = null): array
    {
        $user = DB::table('users')->where('uuid', $authorUuid)->first();

        if ($user !== null) {
            $collectionUuids = DB::table('collections')
                ->where('user_uuid', $authorUuid)
                ->orderByDesc('created_at')
                ->offset($offset)
                ->limit($limit)
                ->pluck('uuid')
                ->map(fn($uuid) => (string) $uuid)
                ->values()
                ->all();

            $total = DB::table('collections')->where('user_uuid', $authorUuid)->count();

            return [
                'items' => $this->getCollectionsByUuids($collectionUuids, $viewerUuid),
                'total' => $total,
            ];
        }

        $username = null;

        $searchResponse = $this->callUnsplash('/search/users', ['query' => $authorUuid, 'per_page' => 10]);
        if ($searchResponse !== null && $searchResponse->successful() && is_array($searchResponse->json())) {
            foreach ($searchResponse->json()['results'] ?? [] as $result) {
                if (($result['id'] ?? null) === $authorUuid || ($result['username'] ?? null) === $authorUuid) {
                    $username = $result['username'] ?? null;
                    break;
                }
            }
        }

        if ($username === null) {
            $username = $authorUuid;
        }

        $collectionsResponse = $this->callUnsplash('/users/' . $username . '/collections', [
            'per_page' => min($limit, 30),
            'page' => max(1, (int) floor($offset / max(1, $limit)) + 1),
        ]);

        if ($collectionsResponse !== null && $collectionsResponse->successful() && is_array($collectionsResponse->json()) && count($collectionsResponse->json()) > 0) {
            $items = collect($collectionsResponse->json())->map(fn(array $collection) => $this->buildUnsplashCollectionItem($collection))->filter()->values();

            if ($items->isNotEmpty()) {
                return ['items' => $items, 'total' => $items->count()];
            }
        }

        $photosResponse = $this->callUnsplash('/users/' . $username . '/photos', [
            'per_page' => min($limit, 30),
            'page' => max(1, (int) floor($offset / max(1, $limit)) + 1),
        ]);

        if ($photosResponse !== null && $photosResponse->successful() && is_array($photosResponse->json()) && count($photosResponse->json()) > 0) {
            $items = collect($photosResponse->json())->map(fn(array $photo) => $this->buildUnsplashPhotoItem($photo))->filter()->values();

            if ($items->isNotEmpty()) {
                return ['items' => $items, 'total' => $items->count()];
            }
        }

        return ['items' => collect(), 'total' => 0];
    }

    public function getFollowedAuthorCollections(string $userUuid, int $limit = 12, int $offset = 0): array
    {
        $followedAuthors = DB::table('followers')
            ->where('user_uuid', $userUuid)
            ->get(['author_uuid', 'username'])
            ->map(fn($row) => [
                'author_uuid' => $this->nullableString($row->author_uuid),
                'username' => $this->nullableString($row->username),
            ])
            ->filter(fn($row) => $row['author_uuid'] !== null)
            ->unique('author_uuid')
            ->values();

        if ($followedAuthors->isEmpty()) {
            return ['items' => collect(), 'total' => 0];
        }

        $followedAuthorUuids = $followedAuthors->pluck('author_uuid')->all();

        $dbAuthorUuids = DB::table('users')
            ->whereIn('uuid', $followedAuthorUuids)
            ->pluck('uuid')
            ->values()
            ->all();

        $unsplashAuthors = $followedAuthors->filter(fn($row) => !in_array($row['author_uuid'], $dbAuthorUuids, true))->values();

        $total = DB::table('collections')
            ->whereIn('user_uuid', $dbAuthorUuids)
            ->count();

        $collectionUuids = DB::table('collections')
            ->whereIn('user_uuid', $dbAuthorUuids)
            ->orderByDesc('created_at')
            ->orderByDesc('uuid')
            ->offset($offset)
            ->limit($limit)
            ->pluck('uuid')
            ->map(fn($uuid) => $this->nullableString($uuid))
            ->filter()
            ->values()
            ->all();

        $dbItems = $this->getCollectionsByUuids($collectionUuids, $userUuid);

        $unsplashItems = collect();
        if ($unsplashAuthors->isNotEmpty() && $dbItems->count() < $limit) {
            $remaining = $limit - $dbItems->count();
            $perAuthor = (int) ceil($remaining / $unsplashAuthors->count());

            foreach ($unsplashAuthors as $author) {
                $identifier = $author['username'] ?? $author['author_uuid'];
                $result = $this->getAuthorCollections($identifier, $perAuthor, 0, $userUuid);
                $unsplashItems = $unsplashItems->concat($result['items']);
            }

            $unsplashItems = $unsplashItems
                ->sortByDesc(fn($item) => $item['created_at'] ?? '')
                ->values()
                ->take($remaining);
        }

        $items = $dbItems->concat($unsplashItems)
            ->sortByDesc(fn($item) => $item['created_at'] ?? '')
            ->values();

        return [
            'items' => $items,
            'total' => $total + $unsplashItems->count(),
        ];
    }

    public function callUnsplashForUser(string $username): ?array
    {
        $response = $this->callUnsplash('/users/' . $username);
        if ($response !== null && $response->successful() && is_array($response->json()) && !isset($response->json()['errors'])) {
            return $response->json();
        }

        return null;
    }

    public function uploadCollection(
        string $userUuid,
        string $title,
        ?string $description,
        ?int $topicId,
        array $imageFiles,
    ): ?array {
        return DB::transaction(function () use ($userUuid, $title, $description, $topicId, $imageFiles): ?array {
            $collection = \App\Models\Collection::create([
                'user_uuid' => $userUuid,
                'title' => $title,
                'description' => $description,
                'topic_id' => $topicId,
                'total_likes' => 0,
            ]);

            $collectionId = $collection->uuid;

            foreach ($imageFiles as $index => $file) {
                [$width, $height] = @getimagesize($file->getRealPath()) ?: [null, null];

                $ext = $file->getClientOriginalExtension() ?: $file->extension();
                $filename = "collection-{$collectionId}-image-" . ($index + 1) . ".{$ext}";

                $destDir = public_path('uploads/images/regular');
                if (!is_dir($destDir)) {
                    mkdir($destDir, 0755, true);
                }

                $file->move($destDir, $filename);

                $url = '/uploads/images/regular/' . $filename;

                Image::create([
                    'uuid' => (string) Str::uuid(),
                    'user_uuid' => $userUuid,
                    'collection_uuid' => $collectionId,
                    'width' => $width,
                    'height' => $height,
                    'url_small' => $url,
                    'url_regular' => $url,
                    'url_full' => $url,
                    'download_url' => $url,
                    'color' => null,
                ]);
            }

            DB::table('users')
                ->where('uuid', $userUuid)
                ->increment('total_images', count($imageFiles));

            DB::table('users')
                ->where('uuid', $userUuid)
                ->increment('total_collections');

            return $this->getCollectionsByUuids([$collectionId], $userUuid)->first();
        });
    }

    /**
     * @return true|false|null
     */
    public function deleteCollection(string $collectionUuid, string $userUuid): ?bool
    {
        $collection = \App\Models\Collection::where('uuid', $collectionUuid)->first();

        if ($collection === null) {
            return null;
        }

        if ((string) $collection->user_uuid !== $userUuid) {
            return false;
        }

        return DB::transaction(function () use ($collection, $userUuid): bool {
            $images = Image::where('collection_uuid', $collection->uuid)->get();

            foreach ($images as $image) {
                foreach (['url_small', 'url_regular', 'url_full'] as $field) {
                    $url = $this->nullableString($image->{$field});
                    if ($url === null) {
                        continue;
                    }

                    if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
                        continue;
                    }

                    $filePath = public_path(ltrim($url, '/'));
                    if (file_exists($filePath)) {
                        @unlink($filePath);
                    }

                    break;
                }
            }

            Image::where('collection_uuid', $collection->uuid)->delete();
            DB::table('likes')->where('collection_uuid', $collection->uuid)->delete();
            DB::table('comments')->where('collection_uuid', $collection->uuid)->delete();

            DB::table('users')
                ->where('uuid', $userUuid)
                ->decrement('total_images', $images->count());

            DB::table('users')
                ->where('uuid', $userUuid)
                ->decrement('total_collections');

            $collection->delete();

            return true;
        });
    }
}