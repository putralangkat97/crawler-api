<?php

namespace App\Http\Controllers;

use App\Services\ImageExtractor;
use Illuminate\Http\Request;

class ScrapeImageController extends Controller
{
    public function __invoke(Request $request, ImageExtractor $extractor)
    {
        $request->validate([
            'urls' => 'required|array|min:1|max:50',
            'urls.*' => 'required|url',
            'block_gifs' => 'sometimes|boolean',
            'prompt' => 'sometimes|string|max:200',
            'prompt_filter' => 'sometimes|boolean',
        ]);

        $results = $extractor->extractFromUrls(
            urls: $request->urls,
            blockGifs: $request->input('block_gifs'),
            prompt: $request->input('prompt'),
            promptFilter: $request->input('prompt_filter')
        );

        return response()->json([
            'success' => true,
            'data' => $results,
            'count' => count($results),
        ]);
    }
}
