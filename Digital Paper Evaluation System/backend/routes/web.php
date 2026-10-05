<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Since this project is an API service, we display a message instead of a webpage.
|
*/

// Default message for root URL
Route::get('/', function () {
    return response()->json([
        'status' => true,
        'message' => 'Welcome to the API Service. No web interface is available.',
        // 'documentation' => 'https://your-api-docs-url.com' // Optional
    ], 200);
});

// Uploaded PDFs/images (question papers, answer sheets, branding) are fetched
// cross-origin by the frontend's own domain — see resolveStorageUrl() in the
// frontend's utils/api.js. Some production hosts serve these as plain static
// files with no way to attach a CORS header at the web-server level (no
// mod_headers), so this route exists specifically to add it in PHP instead.
// public/.htaccess forces every /storage/* request through here rather than
// letting Apache serve the file directly.
Route::get('storage/{path}', function (string $path) {
    $disk = Storage::disk('public');

    if (str_contains($path, '..') || ! $disk->exists($path)) {
        abort(404);
    }

    return response()->file($disk->path($path), [
        'Access-Control-Allow-Origin' => config('app.frontend_url'),
        'Access-Control-Allow-Methods' => 'GET, HEAD, OPTIONS',
    ]);
})->where('path', '.*');

// Fallback route for any unknown routes
Route::fallback(function () {
    return response()->json([
        'status' => false,
        'message' => 'Invalid endpoint. This is an API service.',
        // 'documentation' => 'https://your-api-docs-url.com' // Optional
    ], 404);
});
