<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Sanctum CSRF Cookie route
Route::get('/sanctum/csrf-cookie', function () {
    return response()->json();
});