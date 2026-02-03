<?php

use App\Http\Controllers\HealthController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::get('/health', [HealthController::class, 'health']);
Route::get('/ready', [HealthController::class, 'ready']);

// Legacy endpoints (keep for backward compatibility)
Route::post('/scrape', App\Http\Controllers\SpiderController::class);
Route::post('/scrape/images', App\Http\Controllers\ScrapeImageController::class);

// V1 API - Spider.cloud-like endpoints
Route::prefix('v1')->group(function () {
    Route::middleware(['api.key', 'tenant.rate'])->group(function () {
        Route::post('/scrape', App\Http\Controllers\V1\ScrapeController::class);
        Route::post('/crawl', [App\Http\Controllers\V1\CrawlController::class, 'store']);
        Route::get('/crawl/{job_id}', [App\Http\Controllers\V1\CrawlController::class, 'show']);
        Route::get('/crawl/{job_id}/results', [App\Http\Controllers\V1\CrawlController::class, 'results']);
        Route::delete('/crawl/{job_id}', [App\Http\Controllers\V1\CrawlController::class, 'destroy']);
    });
});
