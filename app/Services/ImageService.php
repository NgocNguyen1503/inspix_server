<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ImageService
{
    /**
     * @return array{items: \Illuminate\Support\Collection<int, array<string, mixed>>, total: int}
     */
    public function getCollectionFeed(int $limit = 12, int $offset = 0): array
    {
        $total = (int) DB::table('collections')->count();

        $rows = DB::table('collections as c')
            ->join('users as u', 'u.id', '=', 'c.user_id')
            ->leftJoin('topics as t', 't.id', '=', 'c.topic_id')
            ->select([
                'c.id',
                'c.title',
                'c.description',
                'c.total_likes',
                'c.created_at',
                'c.updated_at',
                'u.id as author_id',
                'u.name as author_name',
                'u.avatar_url as author_avatar_url',
                'u.bio as author_bio',
                't.id as topic_id',
                't.name as topic_name',
            ])
            ->inRandomOrder()
            ->offset($offset)
            ->limit($limit)
            ->get();

        if ($rows->isEmpty()) {
            return [
                'items' => collect(),
                'total' => $total,
            ];
        }

        $collectionIds = $rows->pluck('id')->map(fn($id) => (int) $id)->all();

        $imagesByCollection = DB::table('images')
            ->select([
                'uuid',
                'color',
                'url_small',
                'url_regular',
                'url_full',
                'download_url',
                'collection_id',
                'created_at',
                'updated_at',
            ])
            ->whereIn('collection_id', $collectionIds)
            ->orderBy('created_at')
            ->get()
            ->groupBy('collection_id');

        $items = $rows->map(function (object $row) use ($imagesByCollection): array {
            $collectionImages = $imagesByCollection->get($row->id, collect())->map(function (object $image): array {
                return [
                    'uuid' => (string) $image->uuid,
                    'color' => $image->color,
                    'urls' => [
                        'small' => (string) $image->url_small,
                        'regular' => (string) $image->url_regular,
                        'full' => (string) $image->url_full,
                        'download' => (string) ($image->download_url ?? $image->url_full),
                    ],
                    'created_at' => (string) $image->created_at,
                    'updated_at' => (string) $image->updated_at,
                    'created_at_human' => Carbon::parse($image->created_at)->diffForHumans(),
                    'updated_at_human' => Carbon::parse($image->updated_at)->diffForHumans(),
                ];
            })->values();

            return [
                'id' => (int) $row->id,
                'title' => (string) $row->title,
                'description' => (string) $row->description,
                'total_likes' => (int) $row->total_likes,
                'images' => $collectionImages,
                'author' => [
                    'id' => (int) $row->author_id,
                    'name' => (string) $row->author_name,
                    'avatar_url' => $row->author_avatar_url,
                    'bio' => $row->author_bio,
                ],
                'topic' => [
                    'id' => $row->topic_id !== null ? (int) $row->topic_id : null,
                    'name' => $row->topic_name,
                ],
                'created_at' => (string) $row->created_at,
                'updated_at' => (string) $row->updated_at,
                'created_at_human' => Carbon::parse($row->created_at)->diffForHumans(),
                'updated_at_human' => Carbon::parse($row->updated_at)->diffForHumans(),
            ];
        })->values();

        return [
            'items' => $items,
            'total' => $total,
        ];
    }

    public function getImageDetailByUuid(string $uuid): ?array
    {
        $row = DB::table('images as i')
            ->join('users as u', 'u.id', '=', 'i.user_id')
            ->join('collections as c', 'c.id', '=', 'i.collection_id')
            ->leftJoin('topics as t', 't.id', '=', 'c.topic_id')
            ->select([
                'i.uuid',
                'i.color',
                'i.url_small',
                'i.url_regular',
                'i.url_full',
                'i.download_url',
                'i.created_at as image_created_at',
                DB::raw('(SELECT COUNT(*) FROM likes l WHERE l.collection_id = i.collection_id) as image_total_likes'),
                'u.id as author_id',
                'u.name as author_name',
                'u.avatar_url as author_avatar_url',
                'u.bio as author_bio',
                'c.id as collection_id',
                'c.title as collection_title',
                'c.description as collection_description',
                't.id as topic_id',
                't.name as topic_name',
            ])
            ->where('i.uuid', $uuid)
            ->first();

        if ($row === null) {
            return null;
        }

        return [
            'uuid' => (string) $row->uuid,
            'color' => $row->color,
            'total_likes' => (int) $row->image_total_likes,
            'urls' => [
                'small' => (string) $row->url_small,
                'regular' => (string) $row->url_regular,
                'full' => (string) $row->url_full,
                'download' => (string) ($row->download_url ?? $row->url_full),
            ],
            'author' => [
                'id' => (int) $row->author_id,
                'name' => (string) $row->author_name,
                'avatar_url' => $row->author_avatar_url,
                'bio' => $row->author_bio,
            ],
            'collection' => [
                'id' => (int) $row->collection_id,
                'title' => (string) $row->collection_title,
                'description' => (string) $row->collection_description,
            ],
            'topic' => [
                'id' => $row->topic_id !== null ? (int) $row->topic_id : null,
                'name' => $row->topic_name,
            ],
            'created_at' => (string) $row->image_created_at,
        ];
    }
}
