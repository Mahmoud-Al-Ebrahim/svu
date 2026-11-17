<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// The React app is built into public/spa (`npm run build:server` in faculty-portal). Its assets are plain files the
// web server sends directly; every other page URL (/, /login, /app/...) returns the app's index.html and React's
// router takes it from there. Unknown /api and /sanctum URLs keep their JSON 404.
Route::fallback(function (Request $request) {
    abort_if($request->is('api/*', 'sanctum/*'), 404);

    $index = public_path('spa/index.html');
    abort_unless(is_file($index), 503, 'The web app is not built yet: run `npm run build:server` in faculty-portal.');

    return response()->file($index, ['Cache-Control' => 'no-cache', 'Content-Type' => 'text/html; charset=UTF-8']);
});
