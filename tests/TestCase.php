<?php

namespace XMultibyte\PackageGenerator\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use XMultibyte\PackageGenerator\PackageServiceProvider;

/**
 * Base test case for Laravel Package Generator tests.
 */
abstract class TestCase extends Orchestra
{
    /**
     * Setup the test environment.
     */
    protected function setUp(): void
    {
        parent::setUp();
        
        // Additional setup can be added here
    }
    
    /**
     * Get package providers.
     *
     * @param  \Illuminate\Foundation\Application  $app
     *
     * @return array<int, class-string>
     */
    protected function getPackageProviders( $app ): array
    {
        return [
            PackageServiceProvider::class,
        ];
    }
    
    /**
     * Define environment setup.
     */
    protected function defineEnvironment( $app ): void
    {
        // Define environment configuration
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver'   => 'sqlite',
            'database' => ':memory:',
            'prefix'   => '',
        ]);
        
        // Load the package configuration
        $configPath = __DIR__ . '/../config/package-generator.php';
        if (file_exists($configPath)) {
            $config = require $configPath;
            $app['config']->set('laravel-package-generator', $config);
        }
    }
}
