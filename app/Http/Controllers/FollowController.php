<?php

namespace App\Http\Controllers;

use App\Helpers\ApiResponse;
use App\Services\FollowService;
use App\Services\ImageService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class FollowController extends Controller
{
    public function __construct(
        private FollowService $followService,
        private ImageService $imageService
    ) {
    }
    public function toggle(Request $request, string $authorUuid)
    {
        $params = $request->all();
        $user = Auth::user();

        if (!$user) {
            return ApiResponse::unauthorized();
        }

        $result = $this->followService->toggleFollow($user->uuid, $authorUuid, $params['username'] ?? null);

        return ApiResponse::success($result, $result['created'] == true ? 'followed' : 'unfollowed');
    }

    public function followCollections(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'limit' => ['sometimes', 'integer', 'min:1', 'max:60'],
            'offset' => ['sometimes', 'integer', 'min:0'],
        ]);

        if ($validator->fails()) {
            return ApiResponse::unprocessableContent($validator->errors()->toArray(), 'Validation failed.');
        }

        $user = Auth::user();
        if (!$user) {
            return ApiResponse::unauthorized();
        }

        $validated = $validator->validated();
        $limit = (int) ($validated['limit'] ?? 12);
        $offset = (int) ($validated['offset'] ?? 0);

        $result = $this->imageService->getFollowedAuthorCollections($user->uuid, $limit, $offset);

        return ApiResponse::success(
            $result['items'],
            'Followed author collections fetched successfully.',
            [
                'limit' => $limit,
                'offset' => $offset,
                'total' => $result['total'],
            ]
        );
    }
}
