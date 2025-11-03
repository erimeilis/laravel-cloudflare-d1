<?php

namespace EriMeilis\CloudflareD1;

use Illuminate\Support\ServiceProvider;
use EriMeilis\CloudflareD1\Database\D1Connection;
use EriMeilis\CloudflareD1\Database\D1Connector;

class D1ServiceProvider extends ServiceProvider
{
    /**
     * Register any application services
     */
    public function register(): void
    {
        // Merge package configuration
        $this->mergeConfigFrom(
            __DIR__.'/config/cloudflare-d1.php',
            'cloudflare-d1'
        );
    }

    /**
     * Bootstrap any application services
     */
    public function boot(): void
    {
        // Publish configuration file
        $this->publishes([
            __DIR__.'/config/cloudflare-d1.php' => config_path('cloudflare-d1.php'),
        ], 'config');

        // Register D1 database driver using extend() - only ONE registration method
        $this->app['db']->extend('d1', function ($config, $name) {
            $connector = new D1Connector;
            $connection = new D1Connection(
                $connector->connect($config),
                $config['database'] ?? $name,
                $config['prefix'] ?? '',
                $config
            );

            // Enable foreign keys by default (SQLite has them disabled by default)
            $connection->enableForeignKeyConstraints();

            return $connection;
        });

        // Register Artisan commands if running in console
        if ($this->app->runningInConsole()) {
            $this->commands([
                Console\Commands\MigrateFromMysqlCommand::class,
            ]);
        }
    }
}
