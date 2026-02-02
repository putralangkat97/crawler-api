<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::post('/scrape', App\Http\Controllers\SpiderController::class);
Route::post('/scrape/images', App\Http\Controllers\ScrapeImageController::class);
