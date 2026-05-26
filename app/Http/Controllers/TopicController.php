<?php

namespace App\Http\Controllers;

use App\Helpers\ApiResponse;
use App\Models\Topic;
use App\Services\ImageService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TopicController extends Controller
{
    public function __construct(private ImageService $imageService) {}

    public function topics(Request $request)
    {
        $params = $request->all();
        $topics = Topic::all();

        if ($topics === null) {
            return ApiResponse::dataNotfound();
        }

        $topics = $topics->map(function (Topic $topic) {
            $thumbnail = DB::table('images as i')
                ->join('collections as c', 'c.uuid', '=', 'i.collection_uuid')
                ->where('c.topic_id', $topic->id)
                ->inRandomOrder()
                ->value('i.url_regular');

            if ($thumbnail === null) {
                $thumbnail = $this->imageService->getRandomUnsplashPhotoUrlForTopic($topic->id);
            }

            $topic->thumbnail_url = $thumbnail;
            return $topic;
        });

        return ApiResponse::success($topics, 'Get list topics successfully.');
    }
}
