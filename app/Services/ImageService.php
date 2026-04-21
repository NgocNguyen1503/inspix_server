<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class ImageService
{
    /**
     * @return array{items: \Illuminate\Support\Collection<int, array<string, mixed>>, total: int}
     */
    public function getCollectionFeed(int $limit = 12, int $offset = 0, ?string $userUuid = null): array
    {
        $serverFeed = $this->getServerCollectionFeed($limit, $offset, $userUuid);
        $serverItems = $serverFeed['items'];
        $serverTotal = (int) $serverFeed['total'];

        $remaining = max(0, $limit - $serverItems->count());
        $unsplashItems = $remaining > 0
            ? $this->getUnsplashCollectionFeed($remaining, $userUuid)
            : collect();

        return [
            'items' => $serverItems->concat($unsplashItems)->values(),
            'total' => $serverTotal + $unsplashItems->count(),
        ];
    }

    /**
     * @return array{items: \Illuminate\Support\Collection<int, array<string, mixed>>, total: int}
     */
    private function getServerCollectionFeed(int $limit, int $offset, ?string $userUuid): array
    {
        $total = (int) DB::table('collections')->count();

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

        $likedCollectionMap = collect($likedCollectionUuids)->flip();
        $followedAuthorMap = collect($followedAuthorUuids)->flip();

        $items = $rows->map(function (object $row) use ($imagesByCollection, $userUuid, $likedCollectionMap, $followedAuthorMap): array {
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
                    'avatar_url' => $this->nullableString($row->author_avatar_url),
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
    private function getUnsplashCollectionFeed(int $limit, ?string $userUuid): Collection
    {
        $accessKey = (string) config('services.unsplash.access_key');
        $apiUrl = rtrim((string) config('services.unsplash.api_url', 'https://api.unsplash.com'), '/');

        if ($accessKey === '') {
            return collect();
        }

        $params = [
            'count' => min($limit, 30),
        ];

        $queryText = $this->buildUnsplashQueryFromUser($userUuid);
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

        // Unsplash returns an object when count=1.
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
                        // Unsplash guideline: use download_location to track downloads.
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
                'avatar_url' => $this->nullableString($row->author_avatar_url),
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
}
