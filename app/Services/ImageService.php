<?php

namespace App\Services;

use Carbon\Carbon;
/**
 * @method array searchCollections(string $searchKey, int $limit = 12, int $offset = 0, ?string $userUuid = null)
 */
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class ImageService
{
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

        $items = $rows->map(function (object $row) use ($imagesByCollection, $userUuid, $likedCollectionMap, $followedAuthorMap, $latestCommentsByCollection): array {
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
                    'is_followed' => $userUuid !== null ? $followedAuthorMap->has((string) $row->author_uuid) : false,
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
        $accessKey = (string) config('services.unsplash.access_key');
        $apiUrl = rtrim((string) config('services.unsplash.api_url', 'https://api.unsplash.com'), '/');

        if ($accessKey === '') {
            return collect();
        }

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

        $response = Http::retry(2, 500)
            ->timeout(20)
            ->acceptJson()
            ->withHeaders([
                'Authorization' => 'Client-ID ' . $accessKey,
            ])
            ->get($apiUrl . '/photos/random', $params);

        if ($response->failed()) {
            return collect();
        }

        $rows = $response->json();
        if (!is_array($rows)) {
            return collect();
        }

        if (isset($rows['id'])) {
            $rows = [$rows];
        }

        return collect($rows)->map(function (array $photo): array {
            $rawId = $this->nullableString($photo['id'] ?? null);
            $createdAt = $this->nullableString($photo['created_at'] ?? null);
            $updatedAt = $this->nullableString($photo['updated_at'] ?? null);

            $collections = $photo['collections'] ?? [];
            if (is_array($collections) && count($collections) > 0) {
                $firstCollection = $collections[0];
                return [
                    'uuid' => $this->nullableString($firstCollection['id'] ?? null),
                    'title' => $this->nullableString($firstCollection['title'] ?? null),
                    'description' => $this->nullableString($firstCollection['description'] ?? null),
                    'total_likes' => isset($firstCollection['total_likes']) ? (int) $firstCollection['total_likes'] : 0,
                    'total_comments' => 0,
                    'is_liked' => false,
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
                        'avatar_url' => $this->resolveAvatarUrl($photo['user']['profile_image']['medium'] ?? null),
                        'bio' => $this->nullableString($photo['user']['bio'] ?? null),
                        'is_followed' => false,
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

            return [
                'uuid' => $rawId,
                'title' => $this->nullableString($photo['alt_description'] ?? $photo['description'] ?? null),
                'description' => $this->nullableString($photo['description'] ?? $photo['alt_description'] ?? null),
                'total_likes' => isset($photo['likes']) ? (int) $photo['likes'] : null,
                'total_comments' => 0,
                'is_liked' => false,
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
                    'avatar_url' => $this->resolveAvatarUrl($photo['user']['profile_image']['medium'] ?? null),
                    'bio' => $this->nullableString($photo['user']['bio'] ?? null),
                    'is_followed' => false,
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
    public function getExploreByCollection(string $collectionUuid, int $limit = 12, int $offset = 0): ?Collection
    {
        $collection = DB::table('collections')
            ->where('uuid', $collectionUuid)
            ->first();

        if ($collection === null) {
            $unsplashCollectionImages = $this->getUnsplashCollectionSourceImages($collectionUuid);

            if ($unsplashCollectionImages !== null) {
                return $this->buildExploreFromSourceImages($unsplashCollectionImages, $collectionUuid, null, $limit, $offset);
            }

            $unsplashPhotoSource = $this->getUnsplashPhotoSourceImage($collectionUuid);

            if ($unsplashPhotoSource !== null) {
                return $this->buildExploreFromSourceImages(collect([$unsplashPhotoSource]), $collectionUuid, null, $limit, $offset);
            }

            return null;
        }

        $topicId = $collection->topic_id !== null ? (int) $collection->topic_id : null;

        $sourceImages = DB::table('images')
            ->select(['uuid', 'color'])
            ->where('collection_uuid', $collectionUuid)
            ->get();

        return $this->buildExploreFromSourceImages($sourceImages, $collectionUuid, $topicId, $limit, $offset);
    }

    /**
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    private function buildExploreFromSourceImages(Collection $sourceImages, string $excludeCollectionUuid, ?int $topicId, int $limit, int $offset): Collection
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
            $unsplashItems = $this->getExploreFromUnsplash($topicId, $sourceColor, $perImage);

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

        $items = $this->getCollectionsByUuids($pageUuids->all());

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

        return $rows->map(function (object $row): array {
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
    private function getExploreFromUnsplash(?int $topicId, ?string $sourceColor, int $limit): Collection
    {
        $accessKey = (string) config('services.unsplash.access_key');
        $apiUrl = rtrim((string) config('services.unsplash.api_url', 'https://api.unsplash.com'), '/');

        if ($accessKey === '') {
            return collect();
        }

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

        $response = Http::retry(2, 500)
            ->timeout(20)
            ->acceptJson()
            ->withHeaders([
                'Authorization' => 'Client-ID ' . $accessKey,
            ])
            ->get($apiUrl . '/photos/random', $params);

        if ($response->failed()) {
            return collect();
        }

        $rows = $response->json();
        if (!is_array($rows)) {
            return collect();
        }

        if (isset($rows['id'])) {
            $rows = [$rows];
        }

        return collect($rows)->map(function (array $photo): array {
            $rawId = $this->nullableString($photo['id'] ?? null);
            $createdAt = $this->nullableString($photo['created_at'] ?? null);
            $updatedAt = $this->nullableString($photo['updated_at'] ?? null);

            return [
                'uuid' => $rawId,
                'title' => $this->nullableString($photo['alt_description'] ?? $photo['description'] ?? null),
                'description' => $this->nullableString($photo['description'] ?? $photo['alt_description'] ?? null),
                'total_likes' => isset($photo['likes']) ? (int) $photo['likes'] : null,
                'total_comments' => null,
                'is_liked' => false,
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
        $accessKey = (string) config('services.unsplash.access_key');
        $apiUrl = rtrim((string) config('services.unsplash.api_url', 'https://api.unsplash.com'), '/');

        if ($accessKey === '') {
            return null;
        }

        $response = Http::retry(2, 500, null, false)
            ->timeout(20)
            ->acceptJson()
            ->withHeaders([
                'Authorization' => 'Client-ID ' . $accessKey,
            ])
            ->get($apiUrl . '/collections/' . $collectionUuid . '/photos', [
                'per_page' => 30,
                'page' => 1,
            ]);

        if ($response->failed()) {
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

    private function getUnsplashPhotoSourceImage(string $photoId): ?object
    {
        $accessKey = (string) config('services.unsplash.access_key');
        $apiUrl = rtrim((string) config('services.unsplash.api_url', 'https://api.unsplash.com'), '/');

        if ($accessKey === '') {
            return null;
        }

        $response = Http::retry(2, 500, null, false)
            ->timeout(20)
            ->acceptJson()
            ->withHeaders([
                'Authorization' => 'Client-ID ' . $accessKey,
            ])
            ->get($apiUrl . '/photos/' . $photoId);

        if ($response->failed()) {
            return null;
        }

        $photo = $response->json();
        if (!is_array($photo)) {
            return null;
        }

        $uuid = $this->nullableString($photo['id'] ?? null);
        if ($uuid === null) {
            return null;
        }

        return (object) [
            'uuid' => $uuid,
            'color' => $photo['color'] ?? null,
        ];
    }

    /**
     * @param list<string> $collectionUuids
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    private function getCollectionsByUuids(array $collectionUuids): Collection
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

        return collect($collectionUuids)->map(function (string $uuid) use ($rowsMap, $imagesByCollection): ?array {
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
                'is_liked' => false,
                'images' => $collectionImages,
                'author' => [
                    'uuid' => $this->nullableString($row->author_uuid),
                    'name' => $this->nullableString($row->author_name),
                    'avatar_url' => $this->resolveAvatarUrl($row->author_avatar_url),
                    'bio' => $this->nullableString($row->author_bio),
                    'is_followed' => false,
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

        // Check if it's an absolute URL (from external service)
        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            return $url;
        }

        // Check if local file exists with the specified path
        $localPath = public_path(ltrim($url, '/'));
        if (file_exists($localPath)) {
            return $url;
        }

        // Try to find any available avatar on the server as fallback
        $avatarDir = public_path('uploads/avatars');
        if (file_exists($avatarDir)) {
            $files = array_diff(scandir($avatarDir), ['.', '..']);
            $files = array_values($files);
            if (count($files) > 0) {
                // Use a consistent fallback based on URL hash to ensure same user gets same avatar
                $index = abs(crc32($url)) % count($files);
                return '/uploads/avatars/' . $files[$index];
            }
        }

        // Last resort: external fallback using Picsum Photos
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
     * Get the latest comment for each collection
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

        // Ensure all collection UUIDs have an entry in the result (null if no comment)
        $result = collect();
        foreach ($collectionUuids as $uuid) {
            $result[$uuid] = $comments->get($uuid, null);
        }

        return $result;
    }

    public function getCollectionCommentsByUuid(string $collectionUuid): ?Collection
    {
        $exists = DB::table('collections')
            ->where('uuid', $collectionUuid)
            ->exists();

        if ($exists) {
            return $this->getCommentsByCollection([$collectionUuid])
                ->get($collectionUuid, collect())
                ->values();
        }

        return collect();
    }

    /**
     * Search collections/photos by a free text key across collections title/description, topic name, author name, and unsplash.
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

        // artists total
        $artistsTotal = (int) DB::table('users')->where('name', 'like', $like)->count();

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

            $collections = $rowsQuery->map(function (object $row) use ($imagesByCollection, $likedCollectionMap) {
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
                    'is_liked' => $likedCollectionMap->has((string) $row->uuid),
                    'images' => $collectionImages,
                    'author' => [
                        'uuid' => $this->nullableString($row->author_uuid),
                        'name' => $this->nullableString($row->author_name),
                        'avatar_url' => $this->nullableString($row->author_avatar_url),
                        'bio' => $this->nullableString($row->author_bio),
                        'is_followed' => false,
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

        // artists
        $artistRows = DB::table('users')
            ->select(['uuid', 'name', 'avatar_url', 'bio'])
            ->where('name', 'like', $like)
            ->orderByDesc('created_at')
            ->orderByDesc('uuid')
            ->offset($offset)
            ->limit($limit)
            ->get();

        $artists = $artistRows->map(function (object $row): array {
            return [
                'uuid' => $this->nullableString($row->uuid),
                'name' => $this->nullableString($row->name),
                'avatar_url' => $this->nullableString($row->avatar_url),
                'bio' => $this->nullableString($row->bio),
            ];
        })->values();

        // photos from unsplash to fill
        $photos = collect();
        if ($collections->count() < $limit) {
            $remaining = $limit - $collections->count();

            $photos = $this->searchUnsplashPhotos($searchKey, $remaining, $offset);
        }

        return [
            'artists' => $artists,
            'collections' => $collections->take($limit)->values(),
            'photos' => $photos->take($limit)->values(),
            'totals' => [
                'collections' => $totalCollections,
                'artists' => $artistsTotal,
                'photos' => isset($json) && is_array($json) && isset($json['total']) ? (int) $json['total'] : 0,
            ],
        ];
    }

    /**
     * Search photos on Unsplash and map to item shape.
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    private function searchUnsplashPhotos(string $queryText, int $limit, int $offset = 0): Collection
    {
        $accessKey = (string) config('services.unsplash.access_key');
        $apiUrl = rtrim((string) config('services.unsplash.api_url', 'https://api.unsplash.com'), '/');

        if ($accessKey === '') {
            return collect();
        }

        $params = [
            'query' => $queryText,
            'per_page' => min($limit, 30),
            'page' => max(1, (int) floor($offset / max(1, $limit)) + 1),
        ];

        $response = Http::retry(2, 500)
            ->timeout(20)
            ->acceptJson()
            ->withHeaders(['Authorization' => 'Client-ID ' . $accessKey])
            ->get($apiUrl . '/search/photos', $params);

        if ($response->failed()) {
            return collect();
        }

        $json = $response->json();
        if (!is_array($json) || !isset($json['results'])) {
            return collect();
        }

        $rows = $json['results'];

        return collect($rows)->map(function (array $photo): array {
            $rawId = $this->nullableString($photo['id'] ?? null);
            $createdAt = $this->nullableString($photo['created_at'] ?? null);

            return [
                'uuid' => $rawId,
                'title' => $this->nullableString($photo['alt_description'] ?? $photo['description'] ?? null),
                'description' => $this->nullableString($photo['description'] ?? $photo['alt_description'] ?? null),
                'total_likes' => isset($photo['likes']) ? (int) $photo['likes'] : null,
                'total_comments' => null,
                'is_liked' => false,
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

        $accessKey = (string) config('services.unsplash.access_key');
        $apiUrl = rtrim((string) config('services.unsplash.api_url', 'https://api.unsplash.com'), '/');

        if ($accessKey !== '') {
            try {
                $response = Http::retry(2, 500)
                    ->timeout(20)
                    ->acceptJson()
                    ->withHeaders([
                        'Authorization' => 'Client-ID ' . $accessKey,
                    ])
                    ->get($apiUrl . '/photos/random', [
                        'query' => $topicName,
                        'count' => 1,
                    ]);

                if ($response->successful()) {
                    $photo = $response->json();
                    if (is_array($photo) && isset($photo['urls']['regular'])) {
                        return $this->nullableString($photo['urls']['regular']);
                    }
                }
            } catch (\Exception $e) {
            }
        }

        return 'https://picsum.photos/seed/' . rawurlencode($topicName) . '/600/400';
    }
}