<?php

namespace Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;

trait CreatesApplication
{
    /**
     * Creates the application.
     */
    public function createApplication(): Application
    {
        $app = require __DIR__.'/../bootstrap/app.php';

        $app->make(Kernel::class)->bootstrap();

        if ($app->environment('testing') && config('database.connections.mysql.database') !== 'quanlivanchuyen_testing') {
            throw new \RuntimeException('Test chỉ được chạy trên database quanlivanchuyen_testing.');
        }

        return $app;
    }
}
