<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ImageOptimizationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\ImageManager;

class ImageController extends Controller
{
    protected $imageOptimizationService;
    protected $imageManager;

    public function __construct(ImageOptimizationService $imageOptimizationService, ImageManager $imageManager)
    {
        $this->imageOptimizationService = $imageOptimizationService;
        $this->imageManager = $imageManager;
    }

    /**
     * Serve an optimized version of an image
     */
    public function serve(Request $request, $path)
    {
        // Sanitize the path to prevent directory traversal
        $path = $this->sanitizePath($path);
        
        // Check if the file exists in storage
        if (!Storage::disk('public')->exists($path)) {
            abort(404);
        }

        // Get the image file
        $filePath = Storage::disk('public')->path($path);
        
        // Get requested dimensions and quality from query parameters
        $width = $request->query('w', null);
        $height = $request->query('h', null);
        $quality = $request->query('q', 80);
        $fit = $request->query('fit', 'contain'); // contain, crop, or stretch

        // Read the image
        $image = $this->imageManager->read($filePath);

        // Apply transformations if dimensions are specified
        if ($width || $height) {
            if ($fit === 'crop') {
                // Crop to exact dimensions
                $image->cover($width ?: $image->width(), $height ?: $image->height());
            } elseif ($fit === 'contain') {
                // Resize to fit within dimensions while maintaining aspect ratio
                $image->scaleDown($width ?: PHP_INT_MAX, $height ?: PHP_INT_MAX);
            } else {
                // Resize to exact dimensions (may distort)
                $image->resize($width, $height);
            }
        }

        // Determine content type based on file extension
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $mimeTypes = [
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'avif' => 'image/avif',
        ];

        $contentType = $mimeTypes[$extension] ?? 'image/jpeg';

        // Create response with optimized image
        $response = response($image->encode($extension, $quality)->toBuffer())
            ->header('Content-Type', $contentType)
            ->header('Cache-Control', 'public, max-age=86400'); // Cache for 1 day

        return $response;
    }

    /**
     * Sanitize the path to prevent directory traversal attacks
     */
    private function sanitizePath($path)
    {
        // Remove any attempts to go up directories
        $path = str_replace(['../', '..\\', './', '.\\'], '', $path);
        
        // Ensure path starts with allowed directories
        if (!preg_match('/^(products|product_videos)\//', $path)) {
            abort(400, 'Invalid path');
        }
        
        return $path;
    }
}