<?php

namespace App\Http\Controllers\Api;

use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class CategoryController extends ApiController
{
    public function index()
    {
        $categories = Category::all();
        return $this->success($categories, 'Categories retrieved successfully');
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255|unique:categories,name',
            'description' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return $this->error('Validation Error', 422, $validator->errors());
        }

        $category = Category::create($validator->validated());

        return $this->success($category, 'Category created successfully', 201);
    }

    public function show($id)
    {
        $category = Category::with(['products'])->find($id);

        if (!$category) {
            return $this->error('Category not found', 404);
        }

        return $this->success($category, 'Category retrieved successfully');
    }

    public function update(Request $request, $id)
    {
        $category = Category::find($id);

        if (!$category) {
            return $this->error('Category not found', 404);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255|unique:categories,name,' . $id . ',category_id',
            'description' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return $this->error('Validation Error', 422, $validator->errors());
        }

        $category->update($validator->validated());

        return $this->success($category, 'Category updated successfully');
    }

    public function destroy($id)
    {
        $category = Category::find($id);

        if (!$category) {
            return $this->error('Category not found', 404);
        }

        // Prevent deletion if category has products
        if ($category->products()->count() > 0) {
            return $this->error('Cannot delete category with associated products', 422);
        }

        $category->delete();

        return $this->success(null, 'Category deleted successfully');
    }
}
