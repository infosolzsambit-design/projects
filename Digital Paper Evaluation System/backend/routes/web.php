<?php

use Illuminate\Support\Facades\Route;

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

// Fallback route for any unknown routes
Route::fallback(function () {
    return response()->json([
        'status' => false,
        'message' => 'Invalid endpoint. This is an API service.',
        // 'documentation' => 'https://your-api-docs-url.com' // Optional
    ], 404);
});
