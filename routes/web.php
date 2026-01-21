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
    Route::post('/forgot-password', [AuthController::class, 'sendResetLink']);
});

// Password reset link route used in reset emails
// This redirects to the SPA frontend, carrying token & email in the query string
Route::get('/reset-password/{token}', function (string $token) {
    $email = request('email');

    $frontendUrl = env('FRONTEND_URL', 'http://localhost:5173');

    return redirect()->away($frontendUrl . '/reset-password?token=' . urlencode($token) . '&email=' . urlencode($email));
})->name('password.reset');