<?php

namespace App\Http\Controllers\Api;

use App\Models\Product;
use App\Models\ProductReview;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ProductReviewController extends ApiController
{
    public function index(Request $request, $productId)
    {
        $product = Product::find($productId);
        if (!$product) {
            return $this->error('Product not found', 404);
        }

        $perPage = (int) $request->input('per_page', 3);
        $perPage = $perPage > 0 && $perPage <= 20 ? $perPage : 3;

        $reviewsQuery = ProductReview::where('product_id', $productId)->orderByDesc('created_at');
        $paginator = $reviewsQuery->paginate($perPage);

        $avgRating = (float) ProductReview::where('product_id', $productId)->avg('rating');
        $total = (int) ProductReview::where('product_id', $productId)->count();

        return $this->success([
            'data' => $paginator->items(),
            'current_page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
            'last_page' => $paginator->lastPage(),
            'total' => $total,
            'average_rating' => $avgRating,
        ], 'Product reviews retrieved successfully');
    }

    public function store(Request $request, $productId)
    {
        $product = Product::find($productId);
        if (!$product) {
            return $this->error('Product not found', 404);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'rating' => 'required|integer|min:1|max:5',
            'comment' => 'required|string|max:2000',
        ]);

        if ($validator->fails()) {
            return $this->error('Validation Error', 422, $validator->errors());
        }

        $review = ProductReview::create([
            'product_id' => $product->product_id,
            'user_id' => auth('sanctum')->id() ?? null,
            'name' => $request->input('name'),
            'rating' => $request->input('rating'),
            'comment' => $request->input('comment'),
        ]);

        return $this->success($review, 'Review submitted successfully', 201);
    }

    public function destroy($productId, $reviewId)
    {
        $product = Product::find($productId);
        if (!$product) {
            return $this->error('Product not found', 404);
        }

        $review = ProductReview::where('product_id', $product->product_id)->find($reviewId);
        if (!$review) {
            return $this->error('Review not found', 404);
        }

        $review->delete();

        return $this->success(null, 'Review deleted successfully');
    }
}
