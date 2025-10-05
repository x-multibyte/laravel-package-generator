<?php

namespace XMultibyte\PackageGenerator\Tests\Unit;

use Illuminate\Support\Facades\Artisan;
use XMultibyte\PackageGenerator\Commands\PackageGeneratorCommand;
use XMultibyte\PackageGenerator\PackageServiceProvider;
use XMultibyte\PackageGenerator\Tests\TestCase;

/**
 * Test the service provider functionality.
 */
class ServiceProviderTest extends TestCase
{
    /**
     * Test that the service provider is properly loaded.
     */
    public function test_service_provider_is_loaded(): void
    {
        $providers = $this->app->getLoadedProviders();

        $this->assertArrayHasKey(PackageServiceProvider::class, $providers);
    }

    /**
     * Test that the command is registered.
     */
    public function test_command_is_registered(): void
    {
        $commands = Artisan::all();
        $this->assertArrayHasKey('package:new', $commands);
    }

    /**
     * Test that the command class is bound in the container.
     */
    public function test_command_class_is_bound(): void
    {
        // The command should be registered through the service provider
        $commands = Artisan::all();
        $this->assertArrayHasKey('package:new', $commands);

        // Check if the command instance is of the correct class
        $command = $commands['package:new'];
        $this->assertInstanceOf(PackageGeneratorCommand::class, $command);
    }

    /**
     * Test that configuration is merged correctly.
     */
    public function test_configuration_is_merged(): void
    {
        $config = config('laravel-package-generator');

        $this->assertIsArray($config);
        $this->assertArrayHasKey('defaults', $config);
        $this->assertArrayHasKey('features', $config);
        $this->assertArrayHasKey('directories', $config);
        $this->assertArrayHasKey('stubs', $config);
    }

    /**
     * Test that the provides method returns correct services.
     */
    public function test_provides_method_returns_correct_services(): void
    {
        $provider = new PackageServiceProvider($this->app);
        $provides = $provider->provides();

        $this->assertContains(PackageGeneratorCommand::class, $provides);
    }
}
