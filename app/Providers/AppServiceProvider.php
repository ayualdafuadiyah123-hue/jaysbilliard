<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Fix CURL SSL Certificate issues in Laragon
        // Root cause: php.ini points to a non-existent D: path.
        // Solution: Force CURL to use the correct cacert.pem from Laragon C:
        $cacertPath = 'C:\laragon\etc\ssl\cacert.pem';
        if (file_exists($cacertPath)) {
            ini_set('curl.cainfo', $cacertPath);
            ini_set('openssl.cafile', $cacertPath);
            putenv("CURL_CA_BUNDLE=$cacertPath");
        }
    }
}
