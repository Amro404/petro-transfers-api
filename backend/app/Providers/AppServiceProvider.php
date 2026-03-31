<?php

namespace App\Providers;

use App\Repositories\EloquentTransferEventRepository;
use App\Repositories\TransferEventRepositoryInterface;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(TransferEventRepositoryInterface::class, EloquentTransferEventRepository::class);
    }

    public function boot(): void
    {
    }
}
