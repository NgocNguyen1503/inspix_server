<?php

namespace App\Http\Controllers;

use App\Helpers\ApiResponse;
use App\Services\ImageService;
use Illuminate\Http\JsonResponse;

class CommentController extends Controller
{
    public function __construct(private readonly ImageService $imageService)
    {
    }

    public function comments(string $collectionUuid): JsonResponse
    {
        $comments = $this->imageService->getCollectionCommentsByUuid($collectionUuid);

        if ($comments === null) {
            return ApiResponse::dataNotfound(['collection_uuid' => ['Collection not found.']], 'Collection not found.');
        }

        return ApiResponse::success(
            $comments,
            'Collection comments fetched successfully.',
            ['count' => $comments->count()]
        );
    }
}
