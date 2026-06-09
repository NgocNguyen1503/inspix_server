<?php

namespace App\Http\Controllers;

use App\Helpers\ApiResponse;
use App\Services\CommentService;
use App\Services\ImageService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CommentController extends Controller
{
    public function __construct(private readonly ImageService $imageService, private readonly CommentService $commentService)
    {
    }

    public function comments(string $collectionUuid): JsonResponse
    {
        $comments = $this->imageService->getCollectionCommentsByUuid($collectionUuid);

        return ApiResponse::success(
            $comments,
            'Collection comments fetched successfully.',
            ['count' => $comments->count()]
        );
    }

    public function store(Request $request, string $collectionUuid): JsonResponse
    {
        $user = $request->user();
        if ($user === null) {
            return ApiResponse::unauthorized();
        }

        $params = $request->validate([
            'context' => 'required|string|max:500',
            'parent_id' => 'nullable|integer|exists:comments,id',
        ]);

        try {
            $comment = $this->commentService->createComment(
                $user->uuid,
                $collectionUuid,
                $params['context'],
                $params['parent_id'] ?? null
            );
            $comment->created_at_human = $this->humanizeDateTime($comment->created_at);
            $comment->updated_at_human = $this->humanizeDateTime($comment->updated_at);
        } catch (\Throwable $e) {
            return ApiResponse::internalServerError(null, 'Failed to create comment.');
        }

        return ApiResponse::success($comment, 'Comment created successfully.');
    }

    private function humanizeDateTime(mixed $value): ?string
    {
        $dateTime = $this->nullableString($value);
        if ($dateTime === null) {
            return null;
        }

        return Carbon::parse($dateTime)->diffForHumans();
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $stringValue = trim((string) $value);
        return $stringValue === '' ? null : $stringValue;
    }
}
