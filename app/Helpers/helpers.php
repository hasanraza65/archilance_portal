<?php 

use Illuminate\Support\Facades\File;

if (!function_exists('public_storage_copy')) {
    function public_storage_copy($relativePath)
    {
        $source = storage_path('app/public/' . $relativePath);
        $destination = public_path('storage/' . $relativePath);

        if (File::exists($source)) {
            File::ensureDirectoryExists(dirname($destination));
            File::copy($source, $destination);
        }
    }
}