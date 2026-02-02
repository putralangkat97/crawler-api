<?php

namespace App\Http\Controllers;

use App\Services\SpiderCrawler;
use Illuminate\Http\Request;

class SpiderController extends Controller
{
    public function __invoke(Request $request, SpiderCrawler $crawler)
    {
        $params = $request->validate([
            'url' => 'required|array|min:1|max:50',
            'url.*' => 'required|url',
            'limit' => 'sometimes|integer|min:1|max:50',
            'request' => 'sometimes|in:http,chrome,smart',
            'format' => 'sometimes|in:markdown,text,html',
        ]);

        $result = $crawler->scrape($params);

        return response()->json($result);
    }
}
