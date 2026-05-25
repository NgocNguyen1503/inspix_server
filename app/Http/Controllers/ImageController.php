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
            'topic_id' => ['sometimes', 'integer', 'min:1'],
        ]);

        if ($validator->fails()) {
            return ApiResponse::unprocessableContent($validator->errors()->toArray(), 'Validation failed.');
        }

        $validated = $validator->validated();
        $limit = (int) ($validated['limit'] ?? 12);
        $offset = (int) ($validated['offset'] ?? 0);
        $topicId = isset($validated['topic_id']) ? (int) $validated['topic_id'] : null;

        $userUuid = $request->user()?->getAuthIdentifier();
        if ($userUuid === null && isset($validated['user_uuid'])) {
            $userUuid = (string) $validated['user_uuid'];
        }

        $feed = $this->imageService->getCollectionFeed($limit, $offset, $userUuid, $topicId);
        $items = $feed['items'];
        $total = (int) $feed['total'];

        return ApiResponse::success(
            $items,
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

    public function explore(Request $request, string $collectionUuid): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'limit' => ['sometimes', 'integer', 'min:1', 'max:30'],
            'offset' => ['sometimes', 'integer', 'min:0'],
        ]);

        if ($validator->fails()) {
            return ApiResponse::unprocessableContent($validator->errors()->toArray(), 'Validation failed.');
        }

        $limit = (int) ($validator->validated()['limit'] ?? 12);
        $offset = (int) ($validator->validated()['offset'] ?? 0);

        $items = $this->imageService->getExploreByCollection($collectionUuid, $limit, $offset);

        if ($items === null) {
            return ApiResponse::dataNotfound(['collection_uuid' => ['Collection not found.']], 'Collection not found.');
        }

        return ApiResponse::success(
            $items,
            'Explore images fetched successfully.',
            ['count' => $items->count()]
        );
    }

    public function search(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'searchKey' => ['required', 'string', 'min:1'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:60'],
            'offset' => ['sometimes', 'integer', 'min:0'],
        ]);

        if ($validator->fails()) {
            return ApiResponse::unprocessableContent($validator->errors()->toArray(), 'Validation failed.');
        }

        $validated = $validator->validated();
        $searchKey = (string) $validated['searchKey'];
        $limit = (int) ($validated['limit'] ?? 12);
        $offset = (int) ($validated['offset'] ?? 0);

        $userUuid = $request->user()?->getAuthIdentifier();

        $result = $this->imageService->searchCollections($searchKey, $limit, $offset, $userUuid);

        return ApiResponse::success(
            [
                'artists' => $result['artists'],
                'collections' => $result['collections'],
                'photos' => $result['photos'],
            ],
            'Search results fetched successfully.',
            [
                'limit' => $limit,
                'offset' => $offset,
                'counts' => [
                    'artists' => $result['totals']['artists'] ?? 0,
                    'collections' => $result['totals']['collections'] ?? 0,
                    'photos' => $result['totals']['photos'] ?? 0,
                ],
            ]
        );
    }
}
