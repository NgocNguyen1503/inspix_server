<?php

namespace App\Http\Controllers;

use App\Helpers\ApiResponse;
use App\Models\Topic;
use Illuminate\Http\Request;

class TopicController extends Controller
{
    public function topics(Request $request)
    {
        $params = $request->all();
        $topics = Topic::all();

        if ($topics === null) {
            return ApiResponse::dataNotfound();
        }

        return ApiResponse::success($topics, 'Get list topics successfully.');
    }
}
