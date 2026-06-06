<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\CommentController;
use App\Http\Controllers\ImageController;
use App\Http\Controllers\LikeController;
use App\Http\Controllers\TopicController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::get('topics', [TopicController::class, 'topics']);

    Route::get('collections/random', [ImageController::class, 'random']);
    Route::get('collections/search', [ImageController::class, 'search']);

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::get('collections/liked', [LikeController::class, 'likedCollections']);
        Route::post('collections/{collectionUuid}/comments', [CommentController::class, 'store']);
        Route::post('collections/{collectionUuid}/like', [LikeController::class, 'toggle']);

        Route::get('profile', [UserController::class, 'profile']);

        Route::get('logout', [AuthController::class, 'logout']);
    });

    Route::get('collections/{collectionUuid}/explore', [ImageController::class, 'explore']);
    Route::get('collections/{collectionUuid}/comments', [CommentController::class, 'comments']);

    Route::post('auth/sign-in', [AuthController::class, 'signIn']);
});
