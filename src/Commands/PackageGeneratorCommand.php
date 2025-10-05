<?php

namespace XMultibyte\PackageGenerator\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;

/**
 * Laravel Package Generator Command.
 *
 * This command generates a complete Laravel package structure with all necessary files
 * including composer.json, service provider, facade, config, tests, and documentation.
 */
class PackageGeneratorCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'package:new {vendor?} {package?} {--facade} {--config} {--path=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create a new Laravel package from scratch with complete structure and files.';

    /**
     * The filesystem instance.
     *
     * @var \Illuminate\Filesystem\Filesystem
     */
    protected $files;

    /**
     * Configuration cache.
     */
    private array $config;

    /**
     * Create a new command instance.
     */
    public function __construct(Filesystem $files)
    {
        parent::__construct();
        $this->files = $files;
        $this->config = config('laravel-package-generator', []);
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        try {
            // Gather package information
            $packageData = $this->gatherPackageInformation();

            // Validate package data
            if (! $this->validatePackageData($packageData)) {
                return Command::FAILURE;
            }

            // Display package information
            $this->displayPackageInfo($packageData);

            // Generate package
            if (! $this->generatePackage($packageData)) {
                $this->error('❌ Package generation failed.');

                return Command::FAILURE;
            }

            $this->info('🎉 Package generated successfully!');

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error("❌ An error occurred: {$e->getMessage()}");

            return Command::FAILURE;
        }
    }

    /**
     * Gather package information from arguments, config, or user input.
     */
    private function gatherPackageInformation(): array
    {
        $packageData = [
            'vendor' => $this->argument('vendor')
                ?? $this->config['defaults']['vendor'] ?? null
                ?? $this->ask('What is the vendor name? (e.g., "laravel")'),
            'package' => $this->argument('package')
                ?? $this->config['defaults']['package'] ?? null
                ?? $this->ask('What is the package name? (e.g., "cashier")'),
            'facade' => $this->option('facade')
                ?? $this->config['features']['facade'] ?? false
                ?? $this->confirm('Do you want to generate a Facade?', true),
            'config' => $this->option('config')
                ?? $this->config['features']['config'] ?? false
                ?? $this->confirm('Do you want to generate a configuration file?', true),
        ];

        // Add additional features from config
        $features = $this->config['features'] ?? [];
        foreach (['migrations', 'views', 'routes', 'tests', 'github_actions'] as $feature) {
            if (isset($features[$feature])) {
                $packageData[$feature] = $features[$feature];
            } else {
                $packageData[$feature] = $this->confirm("Do you want to generate {$feature}?", false);
            }
        }

        // Add metadata
        $authorConfig = $this->config['defaults']['author'] ?? null;
        if (is_array($authorConfig)) {
            $packageData['author'] = $authorConfig['name'] ?? 'Your Name';
            $packageData['author_email'] = $authorConfig['email'] ?? 'your.email@example.com';
        } else {
            $packageData['author'] = $authorConfig ?? $this->ask('Author name?', 'Your Name');
            $packageData['author_email'] = $this->ask('Author email?', 'your.email@example.com');
        }

        $packageData['description'] = $this->config['defaults']['description'] ?? $this->ask('Package description?', 'A Laravel package');
        $packageData['license'] = $this->config['defaults']['license'] ?? 'MIT';
        $packageData['php_version'] = $this->config['defaults']['minimum_php'] ?? '^8.1';
        $packageData['laravel_version'] = $this->config['defaults']['minimum_laravel'] ?? '^10.0';

        return $packageData;
    }

    /**
     * Validate package data.
     */
    private function validatePackageData(array $packageData): bool
    {
        $errors = [];

        // Validate vendor name
        if (empty($packageData['vendor'])) {
            $errors[] = 'Vendor name is required.';
        } elseif (! $this->isValidPackageName($packageData['vendor'])) {
            $errors[] = 'Vendor name must contain only lowercase letters, numbers, and hyphens.';
        }

        // Validate package name
        if (empty($packageData['package'])) {
            $errors[] = 'Package name is required.';
        } elseif (! $this->isValidPackageName($packageData['package'])) {
            $errors[] = 'Package name must contain only lowercase letters, numbers, and hyphens.';
        }

        // Check if package directory already exists
        if (! empty($packageData['vendor']) && ! empty($packageData['package'])) {
            $packagePath = $this->getPackagePath($packageData['vendor'], $packageData['package']);
            if ($this->files->exists($packagePath)) {
                $errors[] = "Package directory already exists: {$packagePath}";
            }
        }

        // Display all errors if any
        if (! empty($errors)) {
            $this->error('❌ Validation failed:');
            foreach ($errors as $error) {
                $this->line("   • {$error}");
            }

            return false;
        }

        return true;
    }

    /**
     * Validate package name format.
     */
    private function isValidPackageName(string $name): bool
    {
        $pattern = $this->config['validation']['package_pattern'] ?? '/^[a-z0-9-]+$/';
        $minLength = $this->config['validation']['min_name_length'] ?? 2;
        $maxLength = $this->config['validation']['max_name_length'] ?? 50;
        $reservedNames = $this->config['validation']['reserved_names'] ?? [];

        // Check pattern
        if (preg_match($pattern, $name) !== 1) {
            return false;
        }

        // Check length
        if (strlen($name) < $minLength || strlen($name) > $maxLength) {
            return false;
        }

        // Check reserved names
        if (in_array(strtolower($name), array_map('strtolower', $reservedNames))) {
            return false;
        }

        return true;
    }

    /**
     * Get stub file path with validation.
     *
     * @throws \InvalidArgumentException
     */
    private function getStubPath(string $stubName): string
    {
        $stubFiles = $this->config['stubs']['files'] ?? [];

        if (! array_key_exists($stubName, $stubFiles)) {
            throw new \InvalidArgumentException("Unknown stub file: {$stubName}");
        }

        // Use package's stub directory
        $stubBasePath = __DIR__.'/../../stubs';
        $stubPath = $stubBasePath.'/'.$stubFiles[$stubName];

        if (! $this->files->exists($stubPath)) {
            throw new \RuntimeException("Stub file not found: {$stubPath}");
        }

        return $stubPath;
    }

    /**
     * Create directory with error handling.
     */
    private function createDirectoryWithErrorHandling(string $path, ?int $permissions = null): bool
    {
        $permissions = $permissions ?? $this->config['directories']['permissions'] ?? 0755;

        try {
            if (! $this->files->isDirectory($path)) {
                $this->files->makeDirectory($path, $permissions, true);
            }

            return true;
        } catch (\Exception $e) {
            $this->error("Failed to create directory {$path}: {$e->getMessage()}");

            return false;
        }
    }

    /**
     * Write file with error handling.
     */
    private function writeFileWithErrorHandling(string $path, string $content): bool
    {
        try {
            $this->files->put($path, $content);

            return true;
        } catch (\Exception $e) {
            $this->error("Failed to write file {$path}: {$e->getMessage()}");

            return false;
        }
    }

    /**
     * Display package information to the user.
     */
    private function displayPackageInfo(array $packageData): void
    {
        $this->info('📦 Package Information:');
        $this->table(
            ['Property', 'Value'],
            [
                ['Vendor', $packageData['vendor']],
                ['Package', $packageData['package']],
                ['Author', $packageData['author']],
                ['Description', $packageData['description']],
                ['License', $packageData['license']],
                ['PHP Version', $packageData['php_version']],
                ['Laravel Version', $packageData['laravel_version']],
                ['Facade', $packageData['facade'] ? '✅ Yes' : '❌ No'],
                ['Config', $packageData['config'] ? '✅ Yes' : '❌ No'],
                ['Migrations', $packageData['migrations'] ? '✅ Yes' : '❌ No'],
                ['Views', $packageData['views'] ? '✅ Yes' : '❌ No'],
                ['Routes', $packageData['routes'] ? '✅ Yes' : '❌ No'],
                ['Tests', $packageData['tests'] ? '✅ Yes' : '❌ No'],
                ['GitHub Actions', $packageData['github_actions'] ? '✅ Yes' : '❌ No'],
            ]
        );
    }

    /**
     * Generate the complete package structure.
     */
    private function generatePackage(array $packageData): bool
    {
        $this->info('🚀 Generating package...');

        // Create directory structure
        if (! $this->createDirectoryStructure($packageData)) {
            return false;
        }

        // Generate core files
        if (! $this->createComposerJson($packageData)) {
            return false;
        }

        if (! $this->createPackageClass($packageData)) {
            return false;
        }

        if (! $this->createServiceProvider($packageData)) {
            return false;
        }

        // Generate optional files based on configuration
        if ($packageData['facade'] && ! $this->createFacade($packageData)) {
            return false;
        }

        if ($packageData['config'] && ! $this->createConfigFile($packageData)) {
            return false;
        }

        if ($packageData['migrations'] && ! $this->createMigrations($packageData)) {
            return false;
        }

        if ($packageData['views'] && ! $this->createViews($packageData)) {
            return false;
        }

        if ($packageData['routes'] && ! $this->createRoutes($packageData)) {
            return false;
        }

        if ($packageData['tests'] && ! $this->createTestFramework($packageData)) {
            return false;
        }

        if ($packageData['github_actions'] && ! $this->createGithubAction($packageData)) {
            return false;
        }

        // Generate documentation
        if (! $this->createReadme($packageData)) {
            return false;
        }

        if (! $this->createGitignore($packageData)) {
            return false;
        }

        return true;
    }

    /**
     * Get the full package path.
     */
    private function getPackagePath(string $vendor, string $package): string
    {
        // Check if path option is provided
        if ($this->option('path')) {
            return $this->option('path') . "/{$vendor}/{$package}";
        }
        
        $basePath = $this->config['directories']['base_path'] ?? 'packages';

        return base_path($basePath."/{$vendor}/{$package}");
    }

    /**
     * Process stub content with replacements.
     */
    private function processStub(string $stub, array $replacements): string
    {
        return str_replace(array_keys($replacements), array_values($replacements), $stub);
    }

    /**
     * Create directory structure for the package.
     */
    protected function createDirectoryStructure(array $packageData): bool
    {
        $vendor = $packageData['vendor'];
        $package = $packageData['package'];
        $packagePath = $this->getPackagePath($vendor, $package);

        $directories = $this->config['directories']['structure'] ?? [
            'src',
            'config',
            'database/migrations',
            'resources/views',
            'tests',
        ];

        foreach ($directories as $directory) {
            $fullPath = $packagePath.'/'.$directory;
            if (! $this->createDirectoryWithErrorHandling($fullPath)) {
                return false;
            }
        }

        $this->info('✅ Directory structure created successfully.');

        return true;
    }

    /**
     * Create migrations directory and files.
     */
    protected function createMigrations(array $packageData): bool
    {
        try {
            $migrationsPath = $this->getPackagePath($packageData['vendor'], $packageData['package']).'/database/migrations';

            if ($this->createDirectoryWithErrorHandling($migrationsPath)) {
                $this->info('✅ Migrations directory created successfully.');

                return true;
            }

            return false;
        } catch (\Exception $e) {
            $this->error("Failed to create migrations: {$e->getMessage()}");

            return false;
        }
    }

    /**
     * Create views directory and files.
     */
    protected function createViews(array $packageData): bool
    {
        try {
            $viewsPath = $this->getPackagePath($packageData['vendor'], $packageData['package']).'/resources/views';

            if ($this->createDirectoryWithErrorHandling($viewsPath)) {
                $this->info('✅ Views directory created successfully.');

                return true;
            }

            return false;
        } catch (\Exception $e) {
            $this->error("Failed to create views: {$e->getMessage()}");

            return false;
        }
    }

    /**
     * Create routes directory and files.
     */
    protected function createRoutes(array $packageData): bool
    {
        try {
            $routesPath = $this->getPackagePath($packageData['vendor'], $packageData['package']).'/routes';

            if ($this->createDirectoryWithErrorHandling($routesPath)) {
                // Create web.php route file
                $webRoutesContent = "<?php\n\nuse Illuminate\Support\Facades\Route;\n\n// Add your routes here\n";
                $webRoutesFile = $routesPath.'/web.php';

                if ($this->writeFileWithErrorHandling($webRoutesFile, $webRoutesContent)) {
                    $this->info('✅ Routes created successfully.');

                    return true;
                }
            }

            return false;
        } catch (\Exception $e) {
            $this->error("Failed to create routes: {$e->getMessage()}");

            return false;
        }
    }

    /**
     * Create composer.json file.
     */
    protected function createComposerJson(array $packageData): bool
    {
        try {
            $stub = $this->files->get($this->getStubPath('composer'));

            $replacements = [
                '{{vendor}}' => $packageData['vendor'],
                '{{package}}' => $packageData['package'],
                '{{Vendor}}' => Str::studly($packageData['vendor']),
                '{{Package}}' => Str::studly($packageData['package']),
                '{{author}}' => $packageData['author'],
                '{{description}}' => $packageData['description'],
                '{{license}}' => $packageData['license'],
                '{{php_version}}' => $packageData['php_version'],
                '{{laravel_version}}' => $packageData['laravel_version'],
            ];

            $content = $this->processStub($stub, $replacements);
            $filePath = $this->getPackagePath($packageData['vendor'], $packageData['package']).'/composer.json';

            if ($this->writeFileWithErrorHandling($filePath, $content)) {
                $this->info('✅ composer.json created successfully.');

                return true;
            }

            return false;
        } catch (\Exception $e) {
            $this->error("Failed to create composer.json: {$e->getMessage()}");

            return false;
        }
    }

    /**
     * Create main package class.
     */
    protected function createPackageClass(array $packageData): bool
    {
        try {
            $stub = $this->files->get($this->getStubPath('package'));

            $replacements = [
                '{{vendor}}' => $packageData['vendor'],
                '{{package}}' => $packageData['package'],
                '{{Vendor}}' => Str::studly($packageData['vendor']),
                '{{Package}}' => Str::studly($packageData['package']),
            ];

            $content = $this->processStub($stub, $replacements);
            $filePath = $this->getPackagePath($packageData['vendor'], $packageData['package']).'/src/'.Str::studly($packageData['package']).'.php';

            if ($this->writeFileWithErrorHandling($filePath, $content)) {
                $this->info('✅ Main package class created successfully.');

                return true;
            }

            return false;
        } catch (\Exception $e) {
            $this->error("Failed to create package class: {$e->getMessage()}");

            return false;
        }
    }

    /**
     * Create service provider.
     */
    protected function createServiceProvider(array $packageData): bool
    {
        try {
            $stub = $this->files->get($this->getStubPath('service_provider'));

            $replacements = [
                '{{vendor}}' => $packageData['vendor'],
                '{{package}}' => $packageData['package'],
                '{{Vendor}}' => Str::studly($packageData['vendor']),
                '{{Package}}' => Str::studly($packageData['package']),
                '{{boot_loads}}' => $this->generateBootLoads($packageData),
                '{{boot_publishes}}' => $this->generateBootPublishes($packageData),
                '{{register_merges}}' => $this->generateRegisterMerges($packageData),
                '{{register_binds}}' => $this->generateRegisterBinds($packageData),
            ];

            $content = $this->processStub($stub, $replacements);
            $filePath = $this->getPackagePath($packageData['vendor'], $packageData['package']).'/src/'.Str::studly($packageData['package']).'ServiceProvider.php';

            if ($this->writeFileWithErrorHandling($filePath, $content)) {
                $this->info('✅ Service provider created successfully.');

                return true;
            }

            return false;
        } catch (\Exception $e) {
            $this->error("Failed to create service provider: {$e->getMessage()}");

            return false;
        }
    }

    /**
     * Create facade class.
     */
    protected function createFacade(array $packageData): bool
    {
        try {
            $stub = $this->files->get($this->getStubPath('facade'));

            $replacements = [
                '{{vendor}}' => $packageData['vendor'],
                '{{package}}' => $packageData['package'],
                '{{Vendor}}' => Str::studly($packageData['vendor']),
                '{{Package}}' => Str::studly($packageData['package']),
            ];

            $content = $this->processStub($stub, $replacements);
            $facadesPath = $this->getPackagePath($packageData['vendor'], $packageData['package']).'/src/Facades';

            if (! $this->createDirectoryWithErrorHandling($facadesPath)) {
                return false;
            }

            $filePath = $facadesPath.'/'.Str::studly($packageData['package']).'.php';

            if ($this->writeFileWithErrorHandling($filePath, $content)) {
                $this->info('✅ Facade created successfully.');

                return true;
            }

            return false;
        } catch (\Exception $e) {
            $this->error("Failed to create facade: {$e->getMessage()}");

            return false;
        }
    }

    /**
     * Create configuration file.
     */
    protected function createConfigFile(array $packageData): bool
    {
        try {
            $stub = $this->files->get($this->getStubPath('config'));

            $replacements = [
                '{{package}}' => $packageData['package'],
            ];

            $content = $this->processStub($stub, $replacements);
            $filePath = $this->getPackagePath($packageData['vendor'], $packageData['package']).'/config/'.$packageData['package'].'.php';

            if ($this->writeFileWithErrorHandling($filePath, $content)) {
                $this->info('✅ Configuration file created successfully.');

                return true;
            }

            return false;
        } catch (\Exception $e) {
            $this->error("Failed to create config file: {$e->getMessage()}");

            return false;
        }
    }

    /**
     * Create test framework files based on configuration.
     */
    protected function createTestFramework(array $packageData): bool
    {
        $testingFramework = $this->config['testing']['framework'] ?? 'pest';

        // Always create phpunit.xml as it's needed by both PHPUnit and Pest
        $success = $this->createPhpUnitXml($packageData) && $this->createTestCase($packageData);

        if ($testingFramework === 'pest') {
            return $success
                && $this->createPestConfig($packageData)
                && $this->createPestTests($packageData);
        }

        // For PHPUnit, we already created the necessary files
        return $success;
    }

    /**
     * Create PHPUnit configuration.
     */
    protected function createPhpUnitXml(array $packageData): bool
    {
        try {
            $stub = $this->files->get($this->getStubPath('phpunit'));

            $replacements = [
                '{{package}}' => $packageData['package'],
            ];

            $content = $this->processStub($stub, $replacements);
            $filePath = $this->getPackagePath($packageData['vendor'], $packageData['package']).'/phpunit.xml';

            if ($this->writeFileWithErrorHandling($filePath, $content)) {
                $this->info('✅ PHPUnit configuration created successfully.');

                return true;
            }

            return false;
        } catch (\Exception $e) {
            $this->error("Failed to create PHPUnit configuration: {$e->getMessage()}");

            return false;
        }
    }

    /**
     * Create Pest configuration.
     */
    protected function createPestConfig(array $packageData): bool
    {
        try {
            $stub = $this->files->get($this->getStubPath('pest_config'));

            $replacements = [
                '{{vendor}}' => $packageData['vendor'],
                '{{package}}' => $packageData['package'],
                '{{Vendor}}' => Str::studly($packageData['vendor']),
                '{{Package}}' => Str::studly($packageData['package']),
            ];

            $content = $this->processStub($stub, $replacements);
            $filePath = $this->getPackagePath($packageData['vendor'], $packageData['package']).'/tests/Pest.php';

            if ($this->writeFileWithErrorHandling($filePath, $content)) {
                $this->info('✅ Pest configuration created successfully.');

                return true;
            }

            return false;
        } catch (\Exception $e) {
            $this->error("Failed to create Pest configuration: {$e->getMessage()}");

            return false;
        }
    }

    /**
     * Create test case.
     */
    protected function createTestCase(array $packageData): bool
    {
        try {
            $stub = $this->files->get($this->getStubPath('test_case'));

            $replacements = [
                '{{vendor}}' => $packageData['vendor'],
                '{{package}}' => $packageData['package'],
                '{{Vendor}}' => Str::studly($packageData['vendor']),
                '{{Package}}' => Str::studly($packageData['package']),
            ];

            $content = $this->processStub($stub, $replacements);
            $filePath = $this->getPackagePath($packageData['vendor'], $packageData['package']).'/tests/TestCase.php';

            if ($this->writeFileWithErrorHandling($filePath, $content)) {
                $this->info('✅ Test case created successfully.');

                return true;
            }

            return false;
        } catch (\Exception $e) {
            $this->error("Failed to create test case: {$e->getMessage()}");

            return false;
        }
    }

    /**
     * Create README.md file.
     */
    protected function createReadme(array $packageData): bool
    {
        try {
            $stub = $this->files->get($this->getStubPath('readme'));

            $replacements = [
                '{{vendor}}' => $packageData['vendor'],
                '{{package}}' => $packageData['package'],
                '{{Vendor}}' => Str::studly($packageData['vendor']),
                '{{Package}}' => Str::studly($packageData['package']),
                '{{author}}' => $packageData['author'],
                '{{description}}' => $packageData['description'],
                '{{license}}' => $packageData['license'],
            ];

            $content = $this->processStub($stub, $replacements);
            $filePath = $this->getPackagePath($packageData['vendor'], $packageData['package']).'/README.md';

            if ($this->writeFileWithErrorHandling($filePath, $content)) {
                $this->info('✅ README.md created successfully.');

                return true;
            }

            return false;
        } catch (\Exception $e) {
            $this->error("Failed to create README.md: {$e->getMessage()}");

            return false;
        }
    }

    /**
     * Create .gitignore file.
     */
    protected function createGitignore(array $packageData): bool
    {
        try {
            $stub = $this->files->get($this->getStubPath('gitignore'));
            $filePath = $this->getPackagePath($packageData['vendor'], $packageData['package']).'/.gitignore';

            if ($this->writeFileWithErrorHandling($filePath, $stub)) {
                $this->info('✅ .gitignore created successfully.');

                return true;
            }

            return false;
        } catch (\Exception $e) {
            $this->error("Failed to create .gitignore: {$e->getMessage()}");

            return false;
        }
    }

    /**
     * Create GitHub Actions workflow.
     */
    protected function createGithubAction(array $packageData): bool
    {
        try {
            $stub = $this->files->get($this->getStubPath('ci'));

            $replacements = [
                '{{package}}' => $packageData['package'],
                '{{php_version}}' => $packageData['php_version'],
                '{{laravel_version}}' => $packageData['laravel_version'],
            ];

            $content = $this->processStub($stub, $replacements);
            $workflowPath = $this->getPackagePath($packageData['vendor'], $packageData['package']).'/.github/workflows';

            if (! $this->createDirectoryWithErrorHandling($workflowPath)) {
                return false;
            }

            $filePath = $workflowPath.'/ci.yml';

            if ($this->writeFileWithErrorHandling($filePath, $content)) {
                $this->info('✅ GitHub Actions workflow created successfully.');

                // Create additional configuration files for code quality tools
                $this->createCodeQualityConfigs($packageData);

                return true;
            }

            return false;
        } catch (\Exception $e) {
            $this->error("Failed to create GitHub Actions workflow: {$e->getMessage()}");

            return false;
        }
    }

    /**
     * Create Pest test files.
     */
    protected function createPestTests(array $packageData): bool
    {
        $success = true;

        // Create Feature test if enabled
        if ($this->config['testing']['create_feature_tests'] ?? true) {
            $success = $success && $this->createPestFeatureTest($packageData);
        }

        // Create Unit test if enabled
        if ($this->config['testing']['create_unit_tests'] ?? true) {
            $success = $success && $this->createPestUnitTest($packageData);
        }

        return $success;
    }

    /**
     * Create Pest Feature test.
     */
    protected function createPestFeatureTest(array $packageData): bool
    {
        try {
            $stub = $this->files->get($this->getStubPath('pest_feature_test'));

            $replacements = [
                '{{vendor}}' => $packageData['vendor'],
                '{{package}}' => $packageData['package'],
                '{{Vendor}}' => Str::studly($packageData['vendor']),
                '{{Package}}' => Str::studly($packageData['package']),
            ];

            $content = $this->processStub($stub, $replacements);
            $filePath = $this->getPackagePath($packageData['vendor'], $packageData['package']).'/tests/Feature/'.Str::studly($packageData['package']).'Test.php';

            if ($this->writeFileWithErrorHandling($filePath, $content)) {
                $this->info('✅ Pest Feature test created successfully.');

                return true;
            }

            return false;
        } catch (\Exception $e) {
            $this->error("Failed to create Pest Feature test: {$e->getMessage()}");

            return false;
        }
    }

    /**
     * Create Pest Unit test.
     */
    protected function createPestUnitTest(array $packageData): bool
    {
        try {
            $stub = $this->files->get($this->getStubPath('pest_unit_test'));

            $replacements = [
                '{{vendor}}' => $packageData['vendor'],
                '{{package}}' => $packageData['package'],
                '{{Vendor}}' => Str::studly($packageData['vendor']),
                '{{Package}}' => Str::studly($packageData['package']),
            ];

            $content = $this->processStub($stub, $replacements);
            $filePath = $this->getPackagePath($packageData['vendor'], $packageData['package']).'/tests/Unit/'.Str::studly($packageData['package']).'Test.php';

            if ($this->writeFileWithErrorHandling($filePath, $content)) {
                $this->info('✅ Pest Unit test created successfully.');

                return true;
            }

            return false;
        } catch (\Exception $e) {
            $this->error("Failed to create Pest Unit test: {$e->getMessage()}");

            return false;
        }
    }

    /**
     * Generate boot loads content for service provider.
     */
    protected function generateBootLoads(array $packageData): string
    {
        $loads = [];

        if ($packageData['views'] ?? false) {
            $loads[] = "\$this->loadViewsFrom(__DIR__.'/../resources/views', '".$packageData['package']."');";
        }

        if ($packageData['migrations'] ?? false) {
            $loads[] = "\$this->loadMigrationsFrom(__DIR__.'/../database/migrations');";
        }

        if ($packageData['routes'] ?? false) {
            $loads[] = "\$this->loadRoutesFrom(__DIR__.'/../routes/web.php');";
        }

        return implode("\n        ", $loads);
    }

    /**
     * Generate boot publishes content for service provider.
     */
    protected function generateBootPublishes(array $packageData): string
    {
        $publishes = [];

        if ($packageData['config'] ?? false) {
            $publishes[] = '$this->publishes([';
            $publishes[] = "    __DIR__.'/../config/".$packageData['package'].".php' => config_path('".$packageData['package'].".php'),";
            $publishes[] = "], 'config');";
        }

        if ($packageData['views'] ?? false) {
            $publishes[] = '$this->publishes([';
            $publishes[] = "    __DIR__.'/../resources/views' => resource_path('views/vendor/".$packageData['package']."'),";
            $publishes[] = "], 'views');";
        }

        if ($packageData['migrations'] ?? false) {
            $publishes[] = '$this->publishes([';
            $publishes[] = "    __DIR__.'/../database/migrations' => database_path('migrations'),";
            $publishes[] = "], 'migrations');";
        }

        return implode("\n            ", $publishes);
    }

    /**
     * Generate register merges content for service provider.
     */
    protected function generateRegisterMerges(array $packageData): string
    {
        $merges = [];

        if ($packageData['config'] ?? false) {
            $merges[] = "\$this->mergeConfigFrom(__DIR__.'/../config/".$packageData['package'].".php', '".$packageData['package']."');";
        }

        return implode("\n        ", $merges);
    }

    /**
     * Generate register binds content for service provider.
     */
    protected function generateRegisterBinds(array $packageData): string
    {
        $binds = [];

        // Add any service bindings here if needed
        // For now, return empty string

        return implode("\n        ", $binds);
    }

    /**
     * Create code quality configuration files for the package.
     */
    protected function createCodeQualityConfigs(array $packageData): void
    {
        $packagePath = $this->getPackagePath($packageData['vendor'], $packageData['package']);

        // Create Laravel Pint configuration
        $this->createPintConfig($packagePath);

        // Create PHPStan configuration
        $this->createPhpStanConfig($packagePath);
    }

    /**
     * Create Laravel Pint configuration file.
     */
    protected function createPintConfig(string $packagePath): void
    {
        $pintConfig = [
            'preset' => 'laravel',
            'rules' => [
                'binary_operator_spaces' => [
                    'default' => 'single_space',
                    'operators' => ['=>' => null],
                ],
                'blank_line_after_namespace' => true,
                'blank_line_after_opening_tag' => true,
                'blank_line_before_statement' => [
                    'statements' => ['return'],
                ],
                'braces' => true,
                'cast_spaces' => true,
                'class_attributes_separation' => [
                    'elements' => [
                        'method' => 'one',
                    ],
                ],
                'no_unused_imports' => true,
                'ordered_imports' => [
                    'sort_algorithm' => 'alpha',
                ],
                'single_trait_insert_per_statement' => true,
            ],
        ];

        $content = json_encode($pintConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $this->writeFileWithErrorHandling($packagePath.'/pint.json', $content);
    }

    /**
     * Create PHPStan configuration file.
     */
    protected function createPhpStanConfig(string $packagePath): void
    {
        $phpstanConfig = <<<'NEON'
includes:
    - ./vendor/larastan/larastan/extension.neon

parameters:
    paths:
        - src
        - config
        - database
    
    # Rule level (0-9, higher is stricter)
    level: 6
    
    ignoreErrors:
        - '#PHPDoc tag @var#'
    
    excludePaths:
        - ./*/*/FileToBeExcluded.php
    
    # Laravel specific configurations
    reportUnmatchedIgnoredErrors: false

NEON;

        $this->writeFileWithErrorHandling($packagePath.'/phpstan.neon', $phpstanConfig);
    }
}
