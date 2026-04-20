<?php

namespace App\Http\Controllers;

use App\Helpers\ApiResponse;
use App\Services\ImageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ImageController extends Controller
{
    public function __construct(private readonly ImageService $imageService)
    {
    }

    public function random(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'limit' => ['sometimes', 'integer', 'min:1', 'max:30'],
            'offset' => ['sometimes', 'integer', 'min:0'],
            'user_uuid' => ['sometimes', 'uuid'],
        ]);

        if ($validator->fails()) {
            return ApiResponse::unprocessableContent($validator->errors()->toArray(), 'Validation failed.');
        }

        $validated = $validator->validated();
        $limit = (int) ($validated['limit'] ?? 12);
        $offset = (int) ($validated['offset'] ?? 0);
        $userUuid = $request->user()?->getAuthIdentifier();
        if ($userUuid === null && isset($validated['user_uuid'])) {
            $userUuid = (string) $validated['user_uuid'];
        }

        $feed = $this->imageService->getCollectionFeed($limit, $offset, $userUuid);
        $items = $feed['items'];
        $total = (int) $feed['total'];

        return ApiResponse::success(
            ['items' => $items],
            'Collections fetched successfully.',
            [
                'limit' => $limit,
                'offset' => $offset,
                'count' => $items->count(),
                'total' => $total,
                'has_more' => ($offset + $items->count()) < $total,
            ]
        );
    }

    public function show(string $uuid): JsonResponse
    {
        $data = $this->imageService->getImageDetailByUuid($uuid);

        if ($data === null) {
            return ApiResponse::dataNotfound(['uuid' => ['Image not found.']], 'Image not found.');
        }

        return ApiResponse::success($data, 'Image detail fetched successfully.');
    }
}
