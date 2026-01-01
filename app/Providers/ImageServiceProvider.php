<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Intervention\Image\ImageManager;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\Drivers\Imagick\Driver as ImagickDriver;

class ImageServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        // Bind the ImageManager to the container
        $this->app->singleton(ImageManager::class, function () {
            // Check if Imagick is available, otherwise use GD
            if (extension_loaded('imagick')) {
                return new ImageManager(new ImagickDriver());
            }
            
            return new ImageManager(new GdDriver());
        });
        
        // Create an alias for easier access
        $this->app->alias(ImageManager::class, 'image');
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        //
    }
}