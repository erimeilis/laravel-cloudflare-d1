<?php

namespace EriMeilis\CloudflareD1;

use EriMeilis\CloudflareD1\Database\D1Connection;
use EriMeilis\CloudflareD1\Database\D1Connector;
use Illuminate\Support\ServiceProvider;

class D1ServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
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
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Publish configuration file
        $this->publishes([
            __DIR__.'/config/cloudflare-d1.php' => config_path('cloudflare-d1.php'),
        ], 'config');

        // Register D1 database driver using extend() - only ONE registration method
        $this->app['db']->extend('d1', function ($config, $name) {
            $d1Config = $this->app['config']->get('cloudflare-d1', []);

            $connector = new D1Connector();
            $connection = new D1Connection(
                $connector->connect(array_merge($config, [
                    'batch_size'       => $config['batch_size'] ?? ($d1Config['batch']['size'] ?? 50),
                    'batch_enabled'    => $config['batch_enabled'] ?? ($d1Config['batch']['enabled'] ?? true),
                    'use_raw_endpoint' => $config['use_raw_endpoint'] ?? ($d1Config['api']['use_raw_endpoint'] ?? true),
                ])),
                $config['database'] ?? $name,
                $config['prefix'] ?? '',
                $config
            );

            // Enable foreign keys by default unless explicitly disabled
            $autoFk = $d1Config['foreign_keys']['auto_enable'] ?? true;
            if ($autoFk) {
                $connection->enableForeignKeyConstraints();
            }

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
