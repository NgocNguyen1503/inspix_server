<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\CommentController;
use App\Http\Controllers\ImageController;
use App\Http\Controllers\TopicController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::get('topics', [TopicController::class, 'topics']);

    Route::get('collections/random', [ImageController::class, 'random']);
    Route::get('collections/{collectionUuid}/explore', [ImageController::class, 'explore']);
    Route::get('collections/{collectionUuid}/comments', [CommentController::class, 'comments']);
    Route::post('collections/{collectionUuid}/comments', [CommentController::class, 'store'])->middleware('auth:sanctum');
    Route::get('collections/search', [ImageController::class, 'search']);

    Route::post('auth/sign-in', [AuthController::class, 'signIn']);
});
