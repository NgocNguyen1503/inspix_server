<?php

namespace App\Http\Controllers;

use App\Helpers\ApiResponse;
use App\Services\LikeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LikeController extends Controller
{
    public function __construct(private readonly LikeService $likeService)
    {
    }

    public function toggle(Request $request, string $collectionUuid): JsonResponse
    {
        $user = $request->user();
        if ($user === null) {
            return ApiResponse::unauthorized();
        }

        try {
            $result = $this->likeService->toggleLike($user->uuid, $collectionUuid);
        } catch (\Throwable $e) {
            return ApiResponse::internalServerError(null, 'Failed to toggle like.');
        }

        return ApiResponse::success($result, $result['created'] ? 'Liked.' : 'Unliked.');
    }
}
