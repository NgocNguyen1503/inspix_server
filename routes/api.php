<?php

use App\Http\Controllers\ImageController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::get('images/random', [ImageController::class, 'random']);
    Route::get('images/{uuid}', [ImageController::class, 'show']);
});
