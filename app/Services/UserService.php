<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class UserService
{
    public function __construct(private readonly ImageService $imageService)
    {
    }

    public function getProfile(string $userUuid, ?string $viewerUuid = null): ?array
    {
        $user = DB::table('users')->where('uuid', $userUuid)->first();

        if ($user !== null) {
            $totalCollections = DB::table('collections')->where('user_uuid', $user->uuid)->count();
            $totalLikes = DB::table('likes')->where('user_uuid', $user->uuid)->count();
            $totalImages = DB::table('images')->where('user_uuid', $user->uuid)->count();

            $followCounts = $this->imageService->getFollowCountsBatch([$user->uuid]);
            $isFollowed = $viewerUuid !== null
                ? DB::table('followers')->where('user_uuid', $viewerUuid)->where('author_uuid', $user->uuid)->exists()
                : false;

            return [
                'uuid' => $user->uuid,
                'email' => $user->email,
                'name' => $user->name,
                'username' => $user->username ?? null,
                'avatar_url' => $user->avatar_url,
                'bio' => $user->bio,
                'total_collections' => $totalCollections,
                'total_likes' => $totalLikes,
                'total_images' => $totalImages,
                'is_followed' => $isFollowed,
                'followers' => $followCounts[$user->uuid]['followers'] ?? 0,
                'following' => $followCounts[$user->uuid]['following'] ?? 0,
                'created_at' => $user->created_at,
                'updated_at' => $user->updated_at,
            ];
        }

        $unsplashUser = $this->imageService->callUnsplashForUser($userUuid);
        if ($unsplashUser === null) {
            return null;
        }

        $unsplashUserId = $unsplashUser['id'];

        $isFollowed = $viewerUuid !== null
            ? DB::table('followers')->where('user_uuid', $viewerUuid)->where('author_uuid', $unsplashUserId)->exists()
            : false;

        return [
            'uuid' => $unsplashUserId,
            'email' => null,
            'name' => $unsplashUser['name'] ?? null,
            'username' => $unsplashUser['username'] ?? null,
            'avatar_url' => $unsplashUser['profile_image']['medium'] ?? null,
            'bio' => $unsplashUser['bio'] ?? null,
            'total_collections' => $unsplashUser['total_collections'] ?? 0,
            'total_likes' => $unsplashUser['total_likes'] ?? 0,
            'total_images' => $unsplashUser['total_photos'] ?? 0,
            'is_followed' => $isFollowed,
            'followers' => DB::table('followers')->where('author_uuid', $unsplashUserId)->count(),
            'following' => DB::table('followers')->where('user_uuid', $unsplashUserId)->count(),
            'created_at' => null,
            'updated_at' => null,
        ];
    }
}