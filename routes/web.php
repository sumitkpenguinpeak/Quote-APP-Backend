<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Review Pro App is an API-driven Shopify app - the React frontend is a
| separate SPA. This file simply exposes a friendly root response so
| visiting the backend URL directly does not 404.
|
*/

Route::get('/', function () {
    return response()->json([
        'app' => 'Review Pro App',
        'status' => 'ok',
    ]);
});
