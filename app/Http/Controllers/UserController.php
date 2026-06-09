<?php

namespace App\Http\Controllers;

use App\Helpers\ApiResponse;
use App\Models\User;
use App\Services\ImageService;
use App\Services\LikeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

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

    public function authorCollections(Request $request, string $authorUuid)
    {
        $validator = Validator::make($request->all(), [
            'limit' => ['sometimes', 'integer', 'min:1', 'max:60'],
            'offset' => ['sometimes', 'integer', 'min:0'],
        ]);

        if ($validator->fails()) {
            return ApiResponse::unprocessableContent($validator->errors()->toArray(), 'Validation failed.');
        }

        $validated = $validator->validated();
        $limit = (int) ($validated['limit'] ?? 12);
        $offset = (int) ($validated['offset'] ?? 0);

        $viewerUuid = Auth::guard('sanctum')->user()?->getAuthIdentifier();

        $result = $this->imageService->getAuthorCollections($authorUuid, $limit, $offset, $viewerUuid);

        return ApiResponse::success(
            $result['items'],
            'Author collections fetched successfully.',
            [
                'limit' => $limit,
                'offset' => $offset,
                'total' => $result['total'],
            ]
        );
    }
}
