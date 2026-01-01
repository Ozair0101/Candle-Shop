<?php

namespace App\Services;

use Intervention\Image\ImageManager;
use Illuminate\Http\UploadedFile;

class ImageOptimizationService
{
    protected $imageManager;

    public function __construct(ImageManager $imageManager)
    {
        $this->imageManager = $imageManager;
    }

    /**
     * Optimize an image by resizing and compressing it
     */
    public function optimize(UploadedFile $file, int $maxWidth = 800, int $maxHeight = 800, int $quality = 80)
    {
        // Read the image file
        $image = $this->imageManager->read($file->getRealPath());
        
        // Resize the image to a maximum size to reduce file size
        $image->scaleDown($maxWidth, $maxHeight);
        
        // Create a temporary file with optimized image
        $extension = $file->getClientOriginalExtension() ?: $file->guessExtension() ?: 'jpg';
        $tempPath = storage_path('app/temp/' . uniqid() . '.' . $extension);
        
        // Save the optimized image with quality compression
        $image->save($tempPath, $quality);
        
        // Return the temporary file
        return new UploadedFile(
            $tempPath,
            $file->getClientOriginalName(),
            $file->getMimeType(),
            $file->getError(),
            true // $test = true to indicate this is a temporary file
        );
    }
    
    /**
     * Create multiple image sizes (thumbnails) for responsive loading
     */
    public function createResponsiveSizes(UploadedFile $file, array $sizes = [
        ['width' => 200, 'height' => 200, 'quality' => 80, 'suffix' => '_thumb'],
        ['width' => 400, 'height' => 400, 'quality' => 85, 'suffix' => '_small'],
        ['width' => 800, 'height' => 800, 'quality' => 90, 'suffix' => '_medium'],
    ])
    {
        $results = [];
        $originalImage = $this->imageManager->read($file->getRealPath());
        
        foreach ($sizes as $size) {
            $image = clone $originalImage;
            $image->scaleDown($size['width'], $size['height']);
            
            $extension = $file->getClientOriginalExtension() ?: $file->guessExtension() ?: 'jpg';
            $filename = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
            $tempPath = storage_path('app/temp/' . $filename . $size['suffix'] . '.' . $extension);
            
            $image->save($tempPath, $size['quality']);
            
            $results[$size['suffix']] = new UploadedFile(
                $tempPath,
                $filename . $size['suffix'] . '.' . $extension,
                $file->getMimeType(),
                $file->getError(),
                true
            );
        }
        
        return $results;
    }
}