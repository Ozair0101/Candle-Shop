<?php

use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\CartController;
use App\Http\Controllers\Api\OrderController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

// Categories API Routes
Route::prefix('categories')->group(function () {
    Route::get('/', [CategoryController::class, 'index']);
    Route::post('/', [CategoryController::class, 'store']);
    Route::get('/{id}', [CategoryController::class, 'show']);
    Route::put('/{id}', [CategoryController::class, 'update']);
    Route::delete('/{id}', [CategoryController::class, 'destroy']);
});

// Products API Routes
Route::prefix('products')->group(function () {
    Route::get('/', [ProductController::class, 'index']);
    Route::post('/', [ProductController::class, 'store']);
    Route::get('/{id}', [ProductController::class, 'show']);
    Route::put('/{id}', [ProductController::class, 'update']);
    Route::delete('/{id}', [ProductController::class, 'destroy']);
    Route::get('/search', [ProductController::class, 'search']);
});

// Cart API Routes
Route::prefix('cart')->group(function () {
    Route::get('/', [CartController::class, 'index']); // Get cart by user_id (query param)
    Route::post('/items', [CartController::class, 'addItem']); // Add item to cart
    Route::put('/items/{cartItemId}', [CartController::class, 'updateItem']); // Update item quantity
    Route::delete('/items/{cartItemId}', [CartController::class, 'removeItem']); // Remove item from cart
    Route::delete('/{cartId}/clear', [CartController::class, 'clearCart']); // Clear all items
    Route::delete('/{cartId}', [CartController::class, 'destroy']); // Delete entire cart
});

// Order API Routes
Route::prefix('orders')->group(function () {
    Route::get('/', [OrderController::class, 'index']); // Get all orders (with filters)
    Route::post('/', [OrderController::class, 'store']); // Create new order
    Route::get('/{orderId}', [OrderController::class, 'show']); // Get specific order
    Route::put('/{orderId}', [OrderController::class, 'update']); // Update order status
    Route::delete('/{orderId}', [OrderController::class, 'destroy']); // Delete order
    Route::post('/{orderId}/cancel', [OrderController::class, 'cancel']); // Cancel order
});
