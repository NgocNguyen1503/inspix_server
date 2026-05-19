<?php

use App\Http\Controllers\ImageController;
use App\Http\Controllers\TopicController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::get('topics', [TopicController::class, 'topics']);

    Route::get('collections/random', [ImageController::class, 'random']);
});
