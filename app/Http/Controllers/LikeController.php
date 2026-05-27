<?php

namespace App\Http\Controllers;

use App\Helpers\ApiResponse;
use App\Services\LikeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

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

    public function likedCollections(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user === null) {
            return ApiResponse::unauthorized();
        }

        $validator = Validator::make($request->all(), [
            'limit' => ['sometimes', 'integer', 'min:1', 'max:30'],
            'offset' => ['sometimes', 'integer', 'min:0'],
        ]);

        if ($validator->fails()) {
            return ApiResponse::unprocessableContent($validator->errors()->toArray(), 'Validation failed.');
        }

        $validated = $validator->validated();
        $limit = (int) ($validated['limit'] ?? 12);
        $offset = (int) ($validated['offset'] ?? 0);

        $feed = $this->likeService->getLikedCollections($user->uuid, $limit, $offset);
        $items = $feed['items'];
        $total = (int) $feed['total'];

        return ApiResponse::success(
            $items,
            'Liked collections fetched successfully.',
            [
                'limit' => $limit,
                'offset' => $offset,
                'count' => $items->count(),
                'total' => $total,
                'has_more' => ($offset + $items->count()) < $total,
            ]
        );
    }
}
