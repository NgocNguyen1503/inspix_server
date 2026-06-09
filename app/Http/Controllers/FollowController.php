<?php

namespace App\Http\Controllers;

use App\Helpers\ApiResponse;
use App\Services\FollowService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class FollowController extends Controller
{
    public function __construct(private FollowService $followService)
    {
    }
    public function toggle(Request $request, string $authorUuid)
    {
        $params = $request->all();
        $user = Auth::user();

        if (!$user) {
            return ApiResponse::unauthorized();
        }

        $result = $this->followService->toggleFollow($user->uuid, $authorUuid);

        return ApiResponse::success($result);
    }
}
