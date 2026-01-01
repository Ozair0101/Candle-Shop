<?php

namespace App\Http\Controllers\Api;

use App\Models\Product;
use App\Models\ProductImage;
use App\Services\ImageOptimizationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Intervention\Image\ImageManager;

class ProductController extends ApiController
{
    protected $imageOptimizationService;

    public function __construct(ImageOptimizationService $imageOptimizationService)
    {
        $this->imageOptimizationService = $imageOptimizationService;
    }

    public function index(Request $request)
    {
        $query = Product::with('images')
            ->withCount('reviews as reviews_count')
            ->withAvg('reviews as average_rating', 'rating');

        $perPage = (int) $request->query('per_page', 12);
        if ($perPage <= 0) {
            $perPage = 12;
        }

        $products = $query->paginate($perPage);

        return $this->success($products, 'Products retrieved successfully');
    }

    public function featured()
    {
        $products = Product::with([
                'images' => function ($q) {
                    $q->orderByDesc('is_primary');
                },
            ])
            ->withCount('reviews as reviews_count')
            ->withAvg('reviews as average_rating', 'rating')
            ->whereNotNull('discount_price')
            ->whereColumn('discount_price', '<', 'price')
            ->where('is_active', true)
            ->where('stock_quantity', '>', 0)
            ->orderByDesc('updated_at')
            ->limit(8)
            ->get();

        return $this->success($products, 'Featured discounted products retrieved successfully');
    }

    public function latest()
    {
        $products = Product::with([
                'images' => function ($q) {
                    $q->orderByDesc('is_primary');
                },
            ])
            ->withCount('reviews as reviews_count')
            ->withAvg('reviews as average_rating', 'rating')
            ->where('is_active', true)
            ->orderByDesc('created_at')
            ->limit(12)
            ->get();

        return $this->success($products, 'Latest products retrieved successfully');
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'price' => 'required|numeric|min:0',
            'discount_price' => 'nullable|numeric|min:0',
            'is_active' => 'boolean',
            'stock_quantity' => 'integer|min:0',
            'category_id' => 'required|exists:categories,category_id',
            'video' => 'sometimes|file|mimetypes:video/mp4,video/quicktime,video/x-msvideo,video/x-ms-wmv|max:51200',
            // Optional legacy URL-based images
            'images' => 'sometimes|array',
            'images.*.url' => 'nullable|url',
            'images.*.is_primary' => 'sometimes|boolean',
            // New file-based image uploads
            'images_files' => 'sometimes|array',
            'images_files.*' => 'image|mimes:jpeg,png,jpg,gif,webp|max:10240',
            'primary_index' => 'sometimes|integer|min:0',
        ]);

        // Validate that discount_price is less than price if provided
        if ($request->has('discount_price') && $request->discount_price >= $request->price) {
            return $this->error('Discount price must be less than the regular price', 422);
        }

        if ($validator->fails()) {
            return $this->error('Validation Error', 422, $validator->errors());
        }

        try {
            DB::beginTransaction();

            // Create the product
            $product = Product::create($request->only([
                'name',
                'description',
                'price',
                'discount_price',
                'is_active',
                'stock_quantity',
                'category_id',
            ]));

            if ($request->hasFile('video')) {
                $videoPath = $request->file('video')->store('product_videos', 'public');
                $videoUrl = Storage::url($videoPath);
                $product->video = $videoUrl;
                $product->save();
            }

            // Handle product images if provided via file upload (preferred)
            if ($request->hasFile('images_files')) {
                $files = $request->file('images_files');
                $primaryIndex = (int) $request->input('primary_index', 0);

                $images = [];
                foreach ($files as $index => $file) {
                    // Optimize the image
                    $optimizedImage = $this->imageOptimizationService->optimize($file);
                    $path = $optimizedImage->store('products', 'public');
                    
                    // Store relative URL (e.g. /storage/products/...) and let frontend prepend backend origin
                    $url = Storage::url($path);

                    $images[] = new ProductImage([
                        'url' => $url,
                        'is_primary' => $index === $primaryIndex,
                    ]);
                }

                // Ensure exactly one primary if we have any images
                if (!empty($images) && !$images[$primaryIndex]->is_primary) {
                    $images[0]->is_primary = true;
                }

                $product->images()->saveMany($images);
            }
            // Fallback: legacy URL-based images array
            elseif ($request->has('images') && is_array($request->images)) {
                $hasPrimary = false;
                $images = [];

                foreach ($request->images as $imageData) {
                    if (empty($imageData['url'])) {
                        continue;
                    }

                    $isPrimary = $imageData['is_primary'] ?? false;
                    
                    // Only one primary image is allowed
                    if ($isPrimary) {
                        if ($hasPrimary) {
                            $isPrimary = false;
                        } else {
                            $hasPrimary = true;
                        }
                    }

                    $images[] = new ProductImage([
                        'url' => $imageData['url'],
                        'is_primary' => $isPrimary
                    ]);
                }

                // If no primary image was specified, make the first one primary
                if (!empty($images) && !$hasPrimary) {
                    $images[0]->is_primary = true;
                }

                // Save all images
                $product->images()->saveMany($images);
            }

            DB::commit();

            // Reload the product with its images
            $product->load('images');

            return $this->success($product, 'Product created successfully', 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return $this->error('Failed to create product: ' . $e->getMessage(), 500);
        }
    }

    public function show($id)
    {
        $product = Product::with('images', 'category')->find($id);

        if (!$product) {
            return $this->error('Product not found', 404);
        }

        return $this->success($product, 'Product retrieved successfully');
    }

    public function update(Request $request, $id)
    {
        $product = Product::find($id);

        if (!$product) {
            return $this->error('Product not found', 404);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string',
            'price' => 'sometimes|required|numeric|min:0',
            'discount_price' => 'nullable|numeric|min:0',
            'is_active' => 'sometimes|boolean',
            'stock_quantity' => 'sometimes|integer|min:0',
            'category_id' => 'sometimes|required|exists:categories,category_id',
            'video' => 'sometimes|file|mimetypes:video/mp4,video/quicktime,video/x-msvideo,video/x-ms-wmv|max:51200',
            // Optional legacy URL-based images
            'images' => 'sometimes|array',
            'images.*.id' => 'sometimes|exists:product_images,id,product_id,' . $id,
            'images.*.url' => 'required_without:images.*.id|url',
            'images.*.is_primary' => 'sometimes|boolean',
            // New file-based image uploads
            'images_files' => 'sometimes|array',
            'images_files.*' => 'image|mimes:jpeg,png,jpg,gif,webp|max:10240',
            'primary_index' => 'sometimes|integer|min:0',
            'deleted_image_ids' => 'sometimes|array',
            'deleted_image_ids.*' => 'exists:product_images,id,product_id,' . $id
        ]);

        // Validate that discount_price is less than price if provided
        if ($request->has('discount_price') && $request->has('price') && $request->discount_price >= $request->price) {
            return $this->error('Discount price must be less than the regular price', 422);
        }

        if ($validator->fails()) {
            return $this->error('Validation Error', 422, $validator->errors());
        }

        try {
            DB::beginTransaction();

            // Update the product
            $product->update($request->only([
                'name',
                'description',
                'price',
                'discount_price',
                'is_active',
                'stock_quantity',
                'category_id',
            ]));

            if ($request->hasFile('video')) {
                $videoPath = $request->file('video')->store('product_videos', 'public');
                $videoUrl = Storage::url($videoPath);
                $product->video = $videoUrl;
                $product->save();
            }

            // Handle image deletions
            if ($request->has('deleted_image_ids') && is_array($request->deleted_image_ids)) {
                // Don't allow deleting the last image if it's the only one
                $remainingImages = $product->images()->whereNotIn('id', $request->deleted_image_ids)->count();
                if ($remainingImages === 0 && $product->images()->count() === count($request->deleted_image_ids)) {
                    return $this->error('Cannot delete all images. A product must have at least one image.', 422);
                }

                // Delete the specified images
                $product->images()->whereIn('id', $request->deleted_image_ids)->delete();
            }

            // Handle new image file uploads
            if ($request->hasFile('images_files')) {
                $files = $request->file('images_files');
                $primaryIndex = (int) $request->input('primary_index', 0);

                foreach ($files as $index => $file) {
                    // Optimize the image
                    $optimizedImage = $this->imageOptimizationService->optimize($file);
                    $path = $optimizedImage->store('products', 'public');
                    
                    // Store relative URL (e.g. /storage/products/...) and let frontend prepend backend origin
                    $url = Storage::url($path);

                    $isPrimary = $index === $primaryIndex;

                    if ($isPrimary) {
                        // Clear existing primary flags
                        $product->images()->update(['is_primary' => false]);
                    }

                    $product->images()->create([
                        'url' => $url,
                        'is_primary' => $isPrimary,
                    ]);
                }
            }

            // Handle image updates and additions from legacy URL-based payload
            if ($request->has('images') && is_array($request->images)) {
                $hasPrimary = $product->images()->where('is_primary', true)->exists();
                
                foreach ($request->images as $imageData) {
                    if (empty($imageData['url']) && empty($imageData['id'])) {
                        continue;
                    }

                    $isPrimary = $imageData['is_primary'] ?? false;
                    
                    // If this is a new image
                    if (!isset($imageData['id'])) {
                        // Only one primary image is allowed
                        if ($isPrimary && $hasPrimary) {
                            $product->images()->update(['is_primary' => false]);
                            $hasPrimary = true;
                        } elseif ($isPrimary) {
                            $hasPrimary = true;
                        }

                        $product->images()->create([
                            'url' => $imageData['url'],
                            'is_primary' => $isPrimary
                        ]);
                    } 
                    // If this is an existing image being updated
                    else {
                        $image = $product->images()->find($imageData['id']);
                        if ($image) {
                            // If this image is being set as primary, unset any existing primary
                            if ($isPrimary && !$image->is_primary) {
                                $product->images()->where('id', '!=', $image->id)->update(['is_primary' => false]);
                                $hasPrimary = true;
                            }

                            $image->update([
                                'url' => $imageData['url'] ?? $image->url,
                                'is_primary' => $isPrimary
                            ]);
                        }
                    }
                }
            }

            DB::commit();

            // Reload the product with its updated images
            $product->load('images');

            return $this->success($product, 'Product updated successfully');

        } catch (\Exception $e) {
            DB::rollBack();
            return $this->error('Failed to update product: ' . $e->getMessage(), 500);
        }
    }

    public function destroy($id)
    {
        $product = Product::find($id);

        if (!$product) {
            return $this->error('Product not found', 404);
        }

        $product->delete();

        return $this->success(null, 'Product deleted successfully');
    }

    public function search(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'query' => 'nullable|string',
            'category_id' => 'nullable|exists:categories,category_id',
            'min_price' => 'nullable|numeric|min:0',
            'max_price' => 'nullable|numeric|min:0',
            'is_active' => 'boolean',
        ]);

        if ($validator->fails()) {
            return $this->error('Validation Error', 422, $validator->errors());
        }

        $query = Product::query();

        if ($request->has('query')) {
            $searchTerm = $request->input('query');
            $query->where(function($q) use ($searchTerm) {
                $q->where('name', 'like', "%{$searchTerm}%")
                  ->orWhere('description', 'like', "%{$searchTerm}%");
            });
        }

        if ($request->has('category_id')) {
            $query->where('category_id', $request->input('category_id'));
        }

        if ($request->has('min_price')) {
            $query->where('price', '>=', $request->input('min_price'));
        }

        if ($request->has('max_price')) {
            $query->where('price', '<=', $request->input('max_price'));
        }

        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        $products = $query
            ->with('images')
            ->withCount('reviews as reviews_count')
            ->withAvg('reviews as average_rating', 'rating')
            ->get();

        return $this->success($products, 'Products retrieved successfully');
    }
}