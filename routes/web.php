<?php

use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Http\Controllers\CsrfCookieController;
use App\Http\Controllers\Api\AuthController;

Route::get('/', function () {
    return view('welcome');
});

// Sanctum CSRF Cookie route - issues XSRF-TOKEN cookie for SPA
Route::get('/sanctum/csrf-cookie', [CsrfCookieController::class, 'show']);

// Auth routes for SPA using web guard and sessions, but under /api prefix
Route::prefix('api')->middleware('web')->group(function () {
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/user', [AuthController::class, 'user']);
});