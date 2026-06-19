<?php

namespace App\Http\Controllers;

use App\Helpers\ApiResponse;
use App\Services\ImageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Auth;

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

        $userUuid = Auth::guard('sanctum')->user()?->getAuthIdentifier();
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
            'user_uuid' => ['sometimes', 'uuid'],
        ]);

        if ($validator->fails()) {
            return ApiResponse::unprocessableContent($validator->errors()->toArray(), 'Validation failed.');
        }

        $validated = $validator->validated();
        $limit = (int) ($validated['limit'] ?? 12);
        $offset = (int) ($validated['offset'] ?? 0);

        $userUuid = Auth::guard('sanctum')->user()?->getAuthIdentifier();
        if ($userUuid === null && isset($validated['user_uuid'])) {
            $userUuid = (string) $validated['user_uuid'];
        }

        $items = $this->imageService->getExploreByCollection($collectionUuid, $limit, $offset, $userUuid);

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
            'user_uuid' => ['sometimes', 'uuid'],
        ]);

        if ($validator->fails()) {
            return ApiResponse::unprocessableContent($validator->errors()->toArray(), 'Validation failed.');
        }

        $validated = $validator->validated();
        $searchKey = (string) $validated['searchKey'];
        $limit = (int) ($validated['limit'] ?? 12);
        $offset = (int) ($validated['offset'] ?? 0);

        $userUuid = Auth::guard('sanctum')->user()?->getAuthIdentifier();
        if ($userUuid === null && isset($validated['user_uuid'])) {
            $userUuid = (string) $validated['user_uuid'];
        }

        $result = $this->imageService->searchCollections($searchKey, $limit, $offset, $userUuid);

        return ApiResponse::success(
            $result['collections'],
            'Search results fetched successfully.',
            [
                'limit' => $limit,
                'offset' => $offset,
                'counts' => [
                    'collections' => $result['totals']['collections'] ?? 0,
                ],
            ]
        );
    }

    public function upload(Request $request): JsonResponse
    {
        $allFiles = $request->allFiles();
        $files = [];

        if (isset($allFiles['images']) && is_array($allFiles['images'])) {
            $files = array_values($allFiles['images']);
        } elseif ($request->file('images') !== null) {
            $f = $request->file('images');
            $files = is_array($f) ? array_values($f) : [$f];
        }

        $validator = Validator::make(
            array_merge($request->all(), ['images' => $files]),
            [
                'title' => ['required', 'string', 'max:255'],
                'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
                'topic_id' => ['sometimes', 'nullable', 'integer', 'exists:topics,id'],
                'images' => ['required', 'array', 'min:1', 'max:20'],
                'images.*' => ['required', 'file', 'image', 'mimes:jpeg,png,webp,gif', 'max:10240'],
            ]
        );

        if ($validator->fails()) {
            return ApiResponse::unprocessableContent($validator->errors()->toArray(), 'Validation failed.');
        }

        $validated = $validator->validated();
        $userUuid = Auth::guard('sanctum')->user()->getAuthIdentifier();

        try {
            $collection = $this->imageService->uploadCollection(
                userUuid: $userUuid,
                title: $validated['title'],
                description: $validated['description'] ?? null,
                topicId: isset($validated['topic_id']) ? (int) $validated['topic_id'] : null,
                imageFiles: $files,
            );
        } catch (\Throwable $e) {
            return ApiResponse::internalServerError(
                ['exception' => $e->getMessage()],
                'Failed to upload collection.'
            );
        }

        return ApiResponse::success($collection, 'Collection uploaded successfully.');
    }

    public function delete(string $collectionUuid): JsonResponse
    {
        $userUuid = Auth::guard('sanctum')->user()->getAuthIdentifier();

        try {
            $deleted = $this->imageService->deleteCollection($collectionUuid, $userUuid);
        } catch (\Throwable $e) {
            return ApiResponse::internalServerError(
                ['exception' => $e->getMessage()],
                'Failed to delete collection.'
            );
        }

        if ($deleted === false) {
            return ApiResponse::forbidden('You do not have permission to delete this collection.');
        }

        if ($deleted === null) {
            return ApiResponse::dataNotfound(['collection_uuid' => ['Collection not found.']], 'Collection not found.');
        }

        return ApiResponse::success(null, 'Collection deleted successfully.');
    }
}
