<?php

use App\Helpers\ApiResponse;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return ApiResponse::unauthorized();
})->name('login');
