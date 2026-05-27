<?php

namespace App\Services;

use App\Models\Comment;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

class CommentService
{
    public function createComment(string $userUuid, string $collectionUuid, string $context, ?int $parentId = null): Comment
    {
        $comment = Comment::create([
            'user_uuid' => $userUuid,
            'collection_uuid' => $collectionUuid,
            'parent_id' => $parentId,
            'context' => $context,
        ]);

        $comment->load('user');

        return $comment;
    }

    /**
     * @param string $collectionUuid
     * @return EloquentCollection<int, Comment>
     */
    public function listByCollection(string $collectionUuid): EloquentCollection
    {
        return Comment::where('collection_uuid', $collectionUuid)
            ->orderBy('created_at')
            ->with('user')
            ->get();
    }
}
