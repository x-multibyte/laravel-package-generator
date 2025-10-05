<?php

namespace XMultibyte\PackageGenerator;

use Illuminate\Support\ServiceProvider;
use XMultibyte\PackageGenerator\Commands\PackageGeneratorCommand;

/**
 * Laravel Package Generator Service Provider.
 *
 * This service provider registers the package generator command and publishes
 * configuration files and stubs for customization.
 */
class PackageServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Merge package configuration
        $this->mergeConfigFrom(
            __DIR__.'/../config/package-generator.php',
            'laravel-package-generator'
        );

        // Register the command
        $this->commands([
            PackageGeneratorCommand::class,
        ]);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Publish configuration file
        $this->publishes([
            __DIR__.'/../config/package-generator.php' => config_path('laravel-package-generator.php'),
        ], 'laravel-package-generator-config');

        // Publish stub files
        $this->publishes([
            __DIR__.'/../stubs' => resource_path('stubs/laravel-package-generator'),
        ], 'laravel-package-generator-stubs');

        // Publish all assets
        $this->publishes([
            __DIR__.'/../config/package-generator.php' => config_path('laravel-package-generator.php'),
            __DIR__.'/../stubs' => resource_path('stubs/laravel-package-generator'),
        ], 'laravel-package-generator');
    }

    /**
     * Get the services provided by the provider.
     *
     * @return array<int, string>
     */
    public function provides(): array
    {
        return [
            PackageGeneratorCommand::class,
        ];
    }
}
