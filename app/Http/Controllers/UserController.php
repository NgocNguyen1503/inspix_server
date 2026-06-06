<?php

namespace App\Http\Controllers;

use App\Helpers\ApiResponse;
use App\Services\ImageService;
use App\Services\LikeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class UserController extends Controller
{
    public function __construct(
        private readonly ImageService $imageService,
        private readonly LikeService $likeService
    ) {
    }

    public function profile(Request $request)
    {
        $params = $request->all();
        $userId = Auth::id();

        $collections = $this->imageService->getUserCollections($userId);

        $liked = $this->likeService->getLikedCollections($userId, $params['limit'] ?? null, $params['offset'] ?? null);

        return ApiResponse::success([
            'owned' => $collections,
            'liked' => $liked['items'],
        ]);
    }
}
