<?php

namespace App\Http\Controllers;

use App\Helpers\ApiResponse;
use App\Models\Follower;
use App\Models\User;
use App\Services\ImageService;
use App\Services\LikeService;
use App\Services\UserService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class UserController extends Controller
{
    public function __construct(
        private readonly ImageService $imageService,
        private readonly LikeService $likeService,
        private readonly UserService $userService
    ) {
    }

    public function profile(Request $request, string $userUuid)
    {
        $viewerUuid = Auth::guard('sanctum')->check() ? Auth::guard('sanctum')->user()?->getAuthIdentifier() : null;

        $profile = $this->userService->getProfile($userUuid, $viewerUuid);

        if ($profile === null) {
            return ApiResponse::dataNotfound('User not found.');
        }

        return ApiResponse::success($profile);
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
