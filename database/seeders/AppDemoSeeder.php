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
        $writeLocalFiles = $this->shouldWriteLocalFiles();
        if ($writeLocalFiles) {
            $this->prepareUploadDirectories();
        }

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
            $avatarSeed = 'avatar-' . ($index + 1);
            $avatarPublicUrl = $this->resolveDemoImageUrl($avatarRelativePath, 300, 300, $avatarSeed, $writeLocalFiles);

            $userRows[] = [
                'uuid' => (string) Str::uuid(),
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

        $users = DB::table('users')->select('uuid')->get();
        $userUuids = $users->pluck('uuid')->all();

        if (count($userUuids) === 0) {
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
        foreach ($userUuids as $userUuid) {
            $collectionsPerUser = random_int(1, 2);
            for ($i = 0; $i < $collectionsPerUser; $i++) {
                $collectionRows[] = [
                    'uuid' => (string) Str::uuid(),
                    'user_uuid' => $userUuid,
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

        $collections = DB::table('collections')->select('uuid', 'user_uuid')->get();
        $collectionUuids = $collections->pluck('uuid')->all();
        $existingImageNames = $this->getExistingImageBaseNames();
        $existingImageCursor = 0;

        $imageRows = [];
        foreach ($collections as $collection) {
            $imagesPerCollection = random_int(1, 8);
            for ($i = 0; $i < $imagesPerCollection; $i++) {
                $uuid = (string) Str::uuid();
                if (count($existingImageNames) > 0) {
                    $pickedName = $existingImageNames[$existingImageCursor % count($existingImageNames)];
                    $existingImageCursor++;

                    $smallRelativePath = '/uploads/images/small/' . $pickedName;
                    $regularRelativePath = '/uploads/images/regular/' . $pickedName;
                    $fullRelativePath = '/uploads/images/full/' . $pickedName;

                    $smallPublicUrl = $this->toPublicUrl($smallRelativePath);
                    $regularPublicUrl = $this->toPublicUrl($regularRelativePath);
                    $fullPublicUrl = $this->toPublicUrl($fullRelativePath);
                    $fullDimensions = $this->getImageDimensions(public_path(ltrim($fullRelativePath, '/')));
                } else {
                    $stableImageKey = 'collection-' . $collection->uuid . '-image-' . ($i + 1);
                    $imageSizes = $this->getCollectionImageSizes($stableImageKey);
                    $smallRelativePath = '/uploads/images/small/' . $stableImageKey . '.jpg';
                    $regularRelativePath = '/uploads/images/regular/' . $stableImageKey . '.jpg';
                    $fullRelativePath = '/uploads/images/full/' . $stableImageKey . '.jpg';
                    $smallPublicUrl = $this->resolveDemoImageUrl(
                        $smallRelativePath,
                        $imageSizes['small']['width'],
                        $imageSizes['small']['height'],
                        $stableImageKey . '-small',
                        $writeLocalFiles
                    );
                    $regularPublicUrl = $this->resolveDemoImageUrl(
                        $regularRelativePath,
                        $imageSizes['regular']['width'],
                        $imageSizes['regular']['height'],
                        $stableImageKey . '-regular',
                        $writeLocalFiles
                    );
                    $fullPublicUrl = $this->resolveDemoImageUrl(
                        $fullRelativePath,
                        $imageSizes['full']['width'],
                        $imageSizes['full']['height'],
                        $stableImageKey . '-full',
                        $writeLocalFiles
                    );

                    $fullDimensions = $this->getImageDimensions(public_path(ltrim($fullRelativePath, '/')))
                        ?? [
                            'width' => $imageSizes['full']['width'],
                            'height' => $imageSizes['full']['height'],
                        ];
                }

                $imageRows[] = [
                    'uuid' => $uuid,
                    'color' => $colorPool[array_rand($colorPool)],
                    'width' => $fullDimensions['width'] ?? null,
                    'height' => $fullDimensions['height'] ?? null,
                    'url_small' => $smallPublicUrl,
                    'url_regular' => $regularPublicUrl,
                    'url_full' => $fullPublicUrl,
                    'user_uuid' => $collection->user_uuid,
                    'collection_uuid' => $collection->uuid,
                    'download_url' => $fullPublicUrl,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }
        DB::table('images')->insert($imageRows);

        $followerRows = [];
        $followerPairs = [];
        $targetFollowerCount = min(30, count($userUuids) * 3);
        while (count($followerRows) < $targetFollowerCount) {
            $userUuid = $userUuids[array_rand($userUuids)];
            $authorUuid = $userUuids[array_rand($userUuids)];
            if ($userUuid === $authorUuid) {
                continue;
            }

            $pairKey = $userUuid . '-' . $authorUuid;
            if (isset($followerPairs[$pairKey])) {
                continue;
            }

            $followerPairs[$pairKey] = true;
            $followerRows[] = [
                'user_uuid' => $userUuid,
                'author_uuid' => $authorUuid,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        DB::table('followers')->insert($followerRows);

        $likeRows = [];
        $likePairs = [];
        foreach ($collectionUuids as $collectionUuid) {
            $likesCount = random_int(0, min(5, count($userUuids)));
            $pickedUsers = [];

            for ($i = 0; $i < $likesCount; $i++) {
                $userUuid = $userUuids[array_rand($userUuids)];
                if (isset($pickedUsers[$userUuid])) {
                    continue;
                }
                $pickedUsers[$userUuid] = true;

                $pairKey = $userUuid . '-' . $collectionUuid;
                if (isset($likePairs[$pairKey])) {
                    continue;
                }

                $likePairs[$pairKey] = true;
                $likeRows[] = [
                    'user_uuid' => $userUuid,
                    'collection_uuid' => $collectionUuid,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }
        if (count($likeRows) > 0) {
            DB::table('likes')->insert($likeRows);
        }

        $commentRows = [];
        foreach ($collectionUuids as $collectionUuid) {
            $baseCommentCount = random_int(1, 3);
            $createdCommentIds = [];

            for ($i = 0; $i < $baseCommentCount; $i++) {
                $commentRows[] = [
                    'user_uuid' => $userUuids[array_rand($userUuids)],
                    'collection_uuid' => $collectionUuid,
                    'parent_id' => null,
                    'context' => $commentPool[array_rand($commentPool)],
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            DB::table('comments')->insert($commentRows);
            $commentRows = [];

            $createdCommentIds = DB::table('comments')
                ->where('collection_uuid', $collectionUuid)
                ->whereNull('parent_id')
                ->pluck('id')
                ->all();

            $replyRows = [];
            foreach ($createdCommentIds as $commentId) {
                if (random_int(0, 1) === 0) {
                    continue;
                }

                $replyRows[] = [
                    'user_uuid' => $userUuids[array_rand($userUuids)],
                    'collection_uuid' => $collectionUuid,
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
        foreach ($userUuids as $userUuid) {
            $take = random_int(2, min(6, count($topicIds)));
            $picked = collect($topicIds)->shuffle()->take($take)->values()->all();
            $interestRows[] = [
                'user_uuid' => $userUuid,
                'topic_ids' => implode(',', $picked),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        DB::table('user_interested')->insert($interestRows);

        $collectionLikes = DB::table('likes')
            ->select('collection_uuid', DB::raw('COUNT(*) as likes_count'))
            ->groupBy('collection_uuid')
            ->get()
            ->keyBy('collection_uuid');

        foreach ($collections as $collection) {
            $likesCount = (int) ($collectionLikes[$collection->uuid]->likes_count ?? 0);
            DB::table('collections')->where('uuid', $collection->uuid)->update([
                'total_likes' => $likesCount,
                'updated_at' => now(),
            ]);
        }

        $userCollectionCounts = DB::table('collections')
            ->select('user_uuid', DB::raw('COUNT(*) as total_collections'))
            ->groupBy('user_uuid')
            ->get()
            ->keyBy('user_uuid');

        $userImageCounts = DB::table('images')
            ->select('user_uuid', DB::raw('COUNT(*) as total_images'))
            ->groupBy('user_uuid')
            ->get()
            ->keyBy('user_uuid');

        $userLikeCounts = DB::table('likes')
            ->select('user_uuid', DB::raw('COUNT(*) as total_likes'))
            ->groupBy('user_uuid')
            ->get()
            ->keyBy('user_uuid');

        foreach ($userUuids as $userUuid) {
            $avatarFileName = 'avatar-' . $userUuid . '.jpg';
            $avatarRelativePath = '/uploads/avatars/' . $avatarFileName;
            $avatarPublicUrl = $this->resolveDemoImageUrl($avatarRelativePath, 300, 300, 'avatar-user-' . $userUuid, $writeLocalFiles);

            DB::table('users')->where('uuid', $userUuid)->update([
                'bio' => $bioPool[array_rand($bioPool)],
                'avatar_url' => $avatarPublicUrl,
                'total_collections' => (int) ($userCollectionCounts[$userUuid]->total_collections ?? 0),
                'total_likes' => (int) ($userLikeCounts[$userUuid]->total_likes ?? 0),
                'total_images' => (int) ($userImageCounts[$userUuid]->total_images ?? 0),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * @return list<string>
     */
    private function getExistingImageBaseNames(): array
    {
        $smallDir = public_path('uploads/images/small');
        $regularDir = public_path('uploads/images/regular');
        $fullDir = public_path('uploads/images/full');

        if (!File::exists($smallDir) || !File::exists($regularDir) || !File::exists($fullDir)) {
            return [];
        }

        $smallNames = collect(File::files($smallDir))
            ->map(fn($file) => $file->getFilename())
            ->values()
            ->all();
        $regularNames = collect(File::files($regularDir))
            ->map(fn($file) => $file->getFilename())
            ->values()
            ->all();
        $fullNames = collect(File::files($fullDir))
            ->map(fn($file) => $file->getFilename())
            ->values()
            ->all();

        if (count($smallNames) === 0 || count($regularNames) === 0 || count($fullNames) === 0) {
            return [];
        }

        $intersected = array_values(array_intersect($smallNames, $regularNames, $fullNames));
        sort($intersected);

        return $intersected;
    }

    /**
     * @return array{
     *   small: array{width: int, height: int},
     *   regular: array{width: int, height: int},
     *   full: array{width: int, height: int}
     * }
     */
    private function getCollectionImageSizes(string $seed): array
    {
        $variants = [
            [
                'small' => ['width' => 480, 'height' => 320],
                'regular' => ['width' => 1080, 'height' => 720],
                'full' => ['width' => 1920, 'height' => 1280],
            ],
            [
                'small' => ['width' => 320, 'height' => 480],
                'regular' => ['width' => 720, 'height' => 1080],
                'full' => ['width' => 1280, 'height' => 1920],
            ],
            [
                'small' => ['width' => 400, 'height' => 400],
                'regular' => ['width' => 1000, 'height' => 1000],
                'full' => ['width' => 1800, 'height' => 1800],
            ],
            [
                'small' => ['width' => 512, 'height' => 288],
                'regular' => ['width' => 1280, 'height' => 720],
                'full' => ['width' => 1920, 'height' => 1080],
            ],
            [
                'small' => ['width' => 288, 'height' => 512],
                'regular' => ['width' => 720, 'height' => 1280],
                'full' => ['width' => 1080, 'height' => 1920],
            ],
            [
                'small' => ['width' => 384, 'height' => 480],
                'regular' => ['width' => 864, 'height' => 1080],
                'full' => ['width' => 1536, 'height' => 1920],
            ],
            [
                'small' => ['width' => 500, 'height' => 400],
                'regular' => ['width' => 1250, 'height' => 1000],
                'full' => ['width' => 2000, 'height' => 1600],
            ],
        ];

        $index = abs((int) crc32($seed)) % count($variants);
        return $variants[$index];
    }

    private function shouldWriteLocalFiles(): bool
    {
        return filter_var((string) env('DEMO_SEED_WRITE_FILES', 'false'), FILTER_VALIDATE_BOOL);
    }

    private function resolveDemoImageUrl(string $relativePath, int $width, int $height, string $seed, bool $writeLocalFiles): string
    {
        if (!$writeLocalFiles) {
            return $this->toPublicUrl($relativePath);
        }

        $this->writeRealImage(public_path(ltrim($relativePath, '/')), $width, $height, $seed);
        return $this->toPublicUrl($relativePath);
    }

    private function toRemoteImageUrl(string $seed, int $width, int $height): string
    {
        return sprintf('https://picsum.photos/seed/%s/%d/%d.jpg', rawurlencode($seed), $width, $height);
    }

    private function toPublicUrl(string $relativePath): string
    {
        if (Str::startsWith($relativePath, ['http://', 'https://'])) {
            return $relativePath;
        }

        return '/' . ltrim($relativePath, '/');
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

        $url = $this->toRemoteImageUrl($seed, $width, $height);

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

    /**
     * @return array{width: int, height: int}|null
     */
    private function getImageDimensions(string $absolutePath): ?array
    {
        if (!File::exists($absolutePath)) {
            return null;
        }

        $size = @getimagesize($absolutePath);
        if ($size === false) {
            return null;
        }

        $width = isset($size[0]) ? (int) $size[0] : 0;
        $height = isset($size[1]) ? (int) $size[1] : 0;

        if ($width <= 0 || $height <= 0) {
            return null;
        }

        return [
            'width' => $width,
            'height' => $height,
        ];
    }
}
