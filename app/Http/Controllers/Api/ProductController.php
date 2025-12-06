<?php

namespace App\Http\Controllers\Api;

use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ProductController extends ApiController
{
    public function index()
    {
        $products = Product::with('images')->get();
        return $this->success($products, 'Products retrieved successfully');
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'price' => 'required|numeric|min:0',
            'is_active' => 'boolean',
            'category_id' => 'required|exists:categories,category_id',
            'images' => 'sometimes|array',
            'images.*.url' => 'required|url',
            'images.*.is_primary' => 'sometimes|boolean'
        ]);

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
                'is_active',
                'category_id'
            ]));

            // Handle product images if provided
            if ($request->has('images') && is_array($request->images)) {
                $hasPrimary = false;
                $images = [];

                foreach ($request->images as $imageData) {
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
        $product = Product::with('images')->find($id);

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
            'is_active' => 'sometimes|boolean',
            'category_id' => 'sometimes|required|exists:categories,category_id',
            'images' => 'sometimes|array',
            'images.*.id' => 'sometimes|exists:product_images,id,product_id,' . $id,
            'images.*.url' => 'required_without:images.*.id|url',
            'images.*.is_primary' => 'sometimes|boolean',
            'deleted_image_ids' => 'sometimes|array',
            'deleted_image_ids.*' => 'exists:product_images,id,product_id,' . $id
        ]);

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
                'is_active',
                'category_id'
            ]));

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

            // Handle image updates and additions
            if ($request->has('images') && is_array($request->images)) {
                $hasPrimary = $product->images()->where('is_primary', true)->exists();
                
                foreach ($request->images as $imageData) {
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

        $products = $query->get();

        return $this->success($products, 'Products retrieved successfully');
    }
}
