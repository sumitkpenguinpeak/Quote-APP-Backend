<?php

namespace App\Providers;

use App\Http\Middleware\VerifyAppProxy;
use App\Http\Middleware\VerifyBilling;
use App\Http\Middleware\VerifyShop;
use Illuminate\Support\Facades\Route;
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
        // Middleware aliases used in routes/api.php. (Registered here so they work
        // even if bootstrap/app.php does not define them; defining them there too is harmless.)
        Route::aliasMiddleware('verify.shop', VerifyShop::class);
        Route::aliasMiddleware('verify.billing', VerifyBilling::class);
        Route::aliasMiddleware('verify.app-proxy', VerifyAppProxy::class);
    }
}
