<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class AppDemoSeeder extends Seeder
{
    /**
     * Seed app demo data for all tables except topics.
     */
    public function run(): void
    {
        $this->prepareUploadDirectories();

        DB::table('comments')->delete();
        DB::table('likes')->delete();
        DB::table('followers')->delete();
        DB::table('images')->delete();
        DB::table('collections')->delete();
        DB::table('user_interested')->delete();
        DB::table('users')->delete();

        $profileNames = [
            'Linh Tran',
            'Minh Nguyen',
            'Bao Le',
            'An Vo',
            'Thu Pham',
            'Gia Han',
            'Emma Stone',
            'Lucas Miller',
            'Olivia Clark',
            'Noah Wilson',
            'Ava Parker',
            'Ethan Brooks',
        ];

        $bioPool = [
            'Yeu anh va thich gom bo suu tap tone mau toi gian.',
            'Nguoi chia se cam hung thiet ke va phong cach song.',
            'Me nhiep anh duong pho va cac khoanh khac doi thuong.',
            'I love collecting visual ideas for product and branding projects.',
            'I share moodboards for interior, lifestyle, and photography.',
            'Building an inspiration library for creative teams every day.',
        ];

        $now = now();
        $userRows = [];
        foreach ($profileNames as $index => $name) {
            $avatarFileName = 'avatar-' . ($index + 1) . '.jpg';
            $avatarRelativePath = '/uploads/avatars/' . $avatarFileName;
            $avatarPublicUrl = $this->toPublicUrl($avatarRelativePath);
            $this->writeRealImage(
                public_path(ltrim($avatarRelativePath, '/')),
                300,
                300,
                'avatar-' . ($index + 1)
            );

            $userRows[] = [
                'name' => $name,
                'email' => 'user' . ($index + 1) . '@inspix.local',
                'email_verified_at' => $now,
                'password' => Hash::make('password'),
                'remember_token' => Str::random(10),
                'bio' => $bioPool[array_rand($bioPool)],
                'avatar_url' => $avatarPublicUrl,
                'total_collections' => 0,
                'total_likes' => 0,
                'total_images' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        DB::table('users')->insert($userRows);

        $users = DB::table('users')->select('id')->get();
        $userIds = $users->pluck('id')->all();

        if (count($userIds) === 0) {
            return;
        }

        $topicIds = DB::table('topics')->pluck('id')->all();
        if (count($topicIds) === 0) {
            return;
        }

        $titlePool = [
            'Summer Street Light',
            'Minimal Workspace Ideas',
            'Quiet Morning Mood',
            'Bo suu tap nha xinh',
            'Cam hung thiet ke app',
            'Goc song cham va am ap',
        ];

        $descriptionPool = [
            'A small set of visuals for modern branding and clean layouts.',
            'This collection focuses on natural light and emotional storytelling.',
            'Reference images for UI, architecture, and visual hierarchy.',
            'Tong hop hinh anh cho phong cach toi gian va hien dai.',
            'Y tuong mau sac cho du an truyen thong va noi dung so.',
            'Bo anh tham khao cho cac bai dang cam hung hang ngay.',
        ];

        $commentPool = [
            'Great composition, I really like this direction.',
            'This set is clean and useful for product moodboards.',
            'Color harmony is very nice here, good job.',
            'Bo anh nay rat hop de tham khao cho du an moi.',
            'Tone mau dep va bo cuc nhin rat cuon hut.',
            'Y tuong hay, minh se luu lai de dung sau.',
        ];

        $colorPool = ['#0F172A', '#1E293B', '#334155', '#475569', '#64748B', '#94A3B8'];

        $collectionRows = [];
        foreach ($userIds as $userId) {
            $collectionsPerUser = random_int(1, 2);
            for ($i = 0; $i < $collectionsPerUser; $i++) {
                $collectionRows[] = [
                    'user_id' => $userId,
                    'title' => $titlePool[array_rand($titlePool)],
                    'description' => $descriptionPool[array_rand($descriptionPool)],
                    'topic_id' => $topicIds[array_rand($topicIds)],
                    'total_likes' => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }
        DB::table('collections')->insert($collectionRows);

        $collections = DB::table('collections')->select('id', 'user_id')->get();
        $collectionIds = $collections->pluck('id')->all();

        $imageRows = [];
        foreach ($collections as $collection) {
            $imagesPerCollection = random_int(1, 2);
            for ($i = 0; $i < $imagesPerCollection; $i++) {
                $uuid = (string) Str::uuid();
                $smallRelativePath = '/uploads/images/small/' . $uuid . '.jpg';
                $regularRelativePath = '/uploads/images/regular/' . $uuid . '.jpg';
                $fullRelativePath = '/uploads/images/full/' . $uuid . '.jpg';
                $smallPublicUrl = $this->toPublicUrl($smallRelativePath);
                $regularPublicUrl = $this->toPublicUrl($regularRelativePath);
                $fullPublicUrl = $this->toPublicUrl($fullRelativePath);

                $this->writeRealImage(public_path(ltrim($smallRelativePath, '/')), 480, 320, $uuid . '-small');
                $this->writeRealImage(public_path(ltrim($regularRelativePath, '/')), 1080, 720, $uuid . '-regular');
                $this->writeRealImage(public_path(ltrim($fullRelativePath, '/')), 1920, 1280, $uuid . '-full');

                $imageRows[] = [
                    'uuid' => $uuid,
                    'color' => $colorPool[array_rand($colorPool)],
                    'url_small' => $smallPublicUrl,
                    'url_regular' => $regularPublicUrl,
                    'url_full' => $fullPublicUrl,
                    'user_id' => $collection->user_id,
                    'collection_id' => $collection->id,
                    'download_url' => $fullPublicUrl,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }
        DB::table('images')->insert($imageRows);

        $followerRows = [];
        $followerPairs = [];
        $targetFollowerCount = min(30, count($userIds) * 3);
        while (count($followerRows) < $targetFollowerCount) {
            $userId = $userIds[array_rand($userIds)];
            $authorId = $userIds[array_rand($userIds)];
            if ($userId === $authorId) {
                continue;
            }

            $pairKey = $userId . '-' . $authorId;
            if (isset($followerPairs[$pairKey])) {
                continue;
            }

            $followerPairs[$pairKey] = true;
            $followerRows[] = [
                'user_id' => $userId,
                'author_id' => $authorId,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        DB::table('followers')->insert($followerRows);

        $likeRows = [];
        $likePairs = [];
        foreach ($collectionIds as $collectionId) {
            $likesCount = random_int(0, min(5, count($userIds)));
            $pickedUsers = [];

            for ($i = 0; $i < $likesCount; $i++) {
                $userId = $userIds[array_rand($userIds)];
                if (isset($pickedUsers[$userId])) {
                    continue;
                }
                $pickedUsers[$userId] = true;

                $pairKey = $userId . '-' . $collectionId;
                if (isset($likePairs[$pairKey])) {
                    continue;
                }

                $likePairs[$pairKey] = true;
                $likeRows[] = [
                    'user_id' => $userId,
                    'collection_id' => $collectionId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }
        if (count($likeRows) > 0) {
            DB::table('likes')->insert($likeRows);
        }

        $commentRows = [];
        foreach ($collectionIds as $collectionId) {
            $baseCommentCount = random_int(1, 3);
            $createdCommentIds = [];

            for ($i = 0; $i < $baseCommentCount; $i++) {
                $commentRows[] = [
                    'user_id' => $userIds[array_rand($userIds)],
                    'collection_id' => $collectionId,
                    'parent_id' => null,
                    'context' => $commentPool[array_rand($commentPool)],
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            DB::table('comments')->insert($commentRows);
            $commentRows = [];

            $createdCommentIds = DB::table('comments')
                ->where('collection_id', $collectionId)
                ->whereNull('parent_id')
                ->pluck('id')
                ->all();

            $replyRows = [];
            foreach ($createdCommentIds as $commentId) {
                if (random_int(0, 1) === 0) {
                    continue;
                }

                $replyRows[] = [
                    'user_id' => $userIds[array_rand($userIds)],
                    'collection_id' => $collectionId,
                    'parent_id' => $commentId,
                    'context' => $commentPool[array_rand($commentPool)],
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            if (count($replyRows) > 0) {
                DB::table('comments')->insert($replyRows);
            }
        }

        $interestRows = [];
        foreach ($userIds as $userId) {
            $take = random_int(2, min(6, count($topicIds)));
            $picked = collect($topicIds)->shuffle()->take($take)->values()->all();
            $interestRows[] = [
                'user_id' => $userId,
                'topic_ids' => implode(',', $picked),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        DB::table('user_interested')->insert($interestRows);

        $collectionLikes = DB::table('likes')
            ->select('collection_id', DB::raw('COUNT(*) as likes_count'))
            ->groupBy('collection_id')
            ->get()
            ->keyBy('collection_id');

        foreach ($collections as $collection) {
            $likesCount = (int) ($collectionLikes[$collection->id]->likes_count ?? 0);
            DB::table('collections')->where('id', $collection->id)->update([
                'total_likes' => $likesCount,
                'updated_at' => now(),
            ]);
        }

        $userCollectionCounts = DB::table('collections')
            ->select('user_id', DB::raw('COUNT(*) as total_collections'))
            ->groupBy('user_id')
            ->get()
            ->keyBy('user_id');

        $userImageCounts = DB::table('images')
            ->select('user_id', DB::raw('COUNT(*) as total_images'))
            ->groupBy('user_id')
            ->get()
            ->keyBy('user_id');

        $userLikeCounts = DB::table('likes')
            ->select('user_id', DB::raw('COUNT(*) as total_likes'))
            ->groupBy('user_id')
            ->get()
            ->keyBy('user_id');

        foreach ($userIds as $userId) {
            $avatarFileName = 'avatar-' . $userId . '.jpg';
            $avatarRelativePath = '/uploads/avatars/' . $avatarFileName;
            $avatarPublicUrl = $this->toPublicUrl($avatarRelativePath);
            $this->writeRealImage(
                public_path(ltrim($avatarRelativePath, '/')),
                300,
                300,
                'avatar-user-' . $userId
            );

            DB::table('users')->where('id', $userId)->update([
                'bio' => $bioPool[array_rand($bioPool)],
                'avatar_url' => $avatarPublicUrl,
                'total_collections' => (int) ($userCollectionCounts[$userId]->total_collections ?? 0),
                'total_likes' => (int) ($userLikeCounts[$userId]->total_likes ?? 0),
                'total_images' => (int) ($userImageCounts[$userId]->total_images ?? 0),
                'updated_at' => now(),
            ]);
        }
    }

    private function toPublicUrl(string $relativePath): string
    {
        if (Str::startsWith($relativePath, ['http://', 'https://'])) {
            return $relativePath;
        }

        $baseUrl = rtrim((string) config('app.url', 'http://localhost'), '/');

        return $baseUrl . '/' . ltrim($relativePath, '/');
    }

    private function prepareUploadDirectories(): void
    {
        $directories = [
            public_path('uploads/avatars'),
            public_path('uploads/images/small'),
            public_path('uploads/images/regular'),
            public_path('uploads/images/full'),
        ];

        foreach ($directories as $directory) {
            if (!File::exists($directory)) {
                File::makeDirectory($directory, 0755, true);
            }
        }
    }

    private function writeRealImage(string $absolutePath, int $width, int $height, string $seed): void
    {
        if (File::exists($absolutePath) && File::size($absolutePath) > 1024) {
            return;
        }

        $url = sprintf('https://picsum.photos/seed/%s/%d/%d.jpg', rawurlencode($seed), $width, $height);

        try {
            $response = Http::retry(2, 400)
                ->timeout(25)
                ->withHeaders(['Accept' => 'image/jpeg'])
                ->get($url);

            if ($response->successful() && strlen($response->body()) > 1024) {
                File::put($absolutePath, $response->body());
                return;
            }
        } catch (\Throwable) {
            // Fall through to fallback binary if remote source is unavailable.
        }

        // 1x1 transparent PNG fallback to avoid broken file paths.
        $pngBase64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO7ZQ2kAAAAASUVORK5CYII=';
        File::put($absolutePath, base64_decode($pngBase64));
    }
}
