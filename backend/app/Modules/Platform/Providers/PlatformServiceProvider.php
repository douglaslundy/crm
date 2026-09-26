<?php

declare(strict_types=1);

namespace App\Modules\Platform\Providers;

use App\Modules\Platform\Application\RegistroDeContadores;
use App\Modules\Platform\Console\ExpirarTestesCommand;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class PlatformServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(RegistroDeContadores::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([ExpirarTestesCommand::class]);
        }

        Route::middleware('api')->prefix('api/app')->group(__DIR__.'/../Http/routes-app.php');
        Route::middleware('api')->prefix('api/admin')->group(__DIR__.'/../Http/routes-admin.php');
        Route::middleware('api')->prefix('api/publico')->group(__DIR__.'/../Http/routes-publico.php');
    }
}
