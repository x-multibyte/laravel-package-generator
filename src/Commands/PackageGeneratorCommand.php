<?php

namespace XMultibyte\PackageGenerator\Commands;

use Exception;
use RuntimeException;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Illuminate\Console\Command;
use Illuminate\Config\Repository;
use Illuminate\Filesystem\Filesystem;

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
    protected Filesystem $files;
    
    protected mixed $config;
    
    
    public function __construct( Filesystem $files, Repository $config )
    {
        parent::__construct();
        
        $this->files  = $files;
        $this->config = $config->get('package-generator', []);
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
                return parent::FAILURE;
            }
            // Display package information
            $this->displayPackageInfo($packageData);
            // Generate package
            if (! $this->generatePackage($packageData)) {
                $this->error('❌ Package generation failed.');
                
                return parent::FAILURE;
            }
            $this->info('🎉 Package generated successfully!');
            
            return parent::SUCCESS;
        }
        catch (Exception $e) {
            $this->error("❌ An error occurred: {$e->getMessage()}");
            
            return parent::FAILURE;
        }
    }
    
    /**
     * Gather package information from arguments, config, or user input.
     *
     * @return array<string, mixed>
     */
    private function gatherPackageInformation(): array
    {
        $packageData = [
            'vendor'  => $this->argument('vendor')
                ?? $this->getConfigString('defaults.vendor')
                    ?? $this->ask('What is the vendor name? (e.g., "laravel")'),
            'package' => $this->argument('package')
                ?? $this->getConfigString('defaults.package')
                    ?? $this->ask('What is the package name? (e.g., "cashier")'),
            'facade'  => $this->option('facade')
                ?? $this->getConfigBool('features.facade', $this->confirm('Do you want to generate a Facade?', true)),
            'config'  => $this->option('config')
                ?? $this->getConfigBool('features.config', $this->confirm('Do you want to generate a configuration file?', true)),
        ];
        
        // Add additional features from config
        $features = $this->getConfigArray('features');
        foreach ([ 'migrations', 'views', 'routes', 'tests', 'github_actions' ] as $feature) {
            if (isset($features[$feature])) {
                $packageData[$feature] = $features[$feature];
            }
            else {
                $packageData[$feature] = $this->confirm("Do you want to generate $feature?");
            }
        }
        
        // Add metadata
        $authorConfig = $this->config['defaults']['author'] ?? null;
        if (is_array($authorConfig)) {
            $packageData['author']       = $authorConfig['name'] ?? 'Your Name';
            $packageData['author_email'] = $authorConfig['email'] ?? 'your.email@example.com';
        }
        else {
            $packageData['author']       = is_string($authorConfig) ? $authorConfig : $this->ask('Author name?', 'Your Name');
            $packageData['author_email'] = $this->ask('Author email?', 'your.email@example.com');
        }
        
        $packageData['description']     = $this->getConfigString('defaults.description') ?? $this->ask('Package description?', 'A Laravel package');
        $packageData['license']         = $this->getConfigString('defaults.license') ?? 'MIT';
        $packageData['php_version']     = $this->getConfigString('defaults.minimum_php') ?? '^8.1';
        $packageData['laravel_version'] = $this->getConfigString('defaults.minimum_laravel') ?? '^10.0';
        
        return $packageData;
    }
    
    /**
     * Safely get a string value from config with fallback.
     */
    private function getConfigString( string $key, ?string $default = null ): ?string
    {
        return $this->getConfigValue($key, $default, 'is_string');
    }
    
    /**
     * Safely get a boolean value from config with fallback.
     */
    private function getConfigBool( string $key, bool $default = false ): bool
    {
        return $this->getConfigValue($key, $default, 'is_bool');
    }
    
    /**
     * Safely get an integer value from config with fallback.
     */
    private function getConfigInt( string $key, int $default = 0 ): int
    {
        return $this->getConfigValue($key, $default, 'is_int');
    }
    
    /**
     * Safely get an array value from config with fallback.
     *
     * @param  string  $key
     * @param  array   $default
     *
     * @return array
     */
    private function getConfigArray( string $key, array $default = [] ): array
    {
        return $this->getConfigValue($key, $default, 'is_array');
    }
    
    /**
     * Generic config value getter with type validation.
     *
     * @template T
     * @param  T                      $default
     * @param  callable(mixed): bool  $validator
     *
     */
    private function getConfigValue( string $key, mixed $default, callable $validator ): mixed
    {
        $value = $this->config;
        foreach (explode('.', $key) as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }
        
        return $validator($value) ? $value : $default;
    }
    
    // ───────────────────────────────────────────────
    // 🧪 VALIDATION & HELPERS
    // ───────────────────────────────────────────────
    
    /**
     * Validate package data.
     *
     * @param  array<string, mixed>  $packageData
     */
    private function validatePackageData( array $packageData ): bool
    {
        $errors = [];
        
        // Validate vendor name
        if (empty($packageData['vendor'])) {
            $errors[] = 'Vendor name is required.';
        }
        elseif (! $this->isValidPackageName($packageData['vendor'])) {
            $errors[] = 'Vendor name must contain only lowercase letters, numbers, and hyphens.';
        }
        
        // Validate package name
        if (empty($packageData['package'])) {
            $errors[] = 'Package name is required.';
        }
        elseif (! $this->isValidPackageName($packageData['package'])) {
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
    private function isValidPackageName( string $name ): bool
    {
        $pattern       = $this->getConfigString('validation.package_pattern', '/^[a-z0-9-]+$/') ?? '/^[a-z0-9-]+$/';
        $minLength     = $this->getConfigInt('validation.min_name_length', 2);
        $maxLength     = $this->getConfigInt('validation.max_name_length', 50);
        $reservedNames = $this->getConfigArray('validation.reserved_names');
        
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
    private function getStubPath( string $stubName ): string
    {
        $stubFiles = $this->getConfigArray('stubs.files');
        if (! array_key_exists($stubName, $stubFiles)) {
            throw new InvalidArgumentException("Unknown stub file: {$stubName}");
        }
        
        // Use package's stub directory
        $stubBasePath = __DIR__ . '/../../stubs';
        $stubPath     = $stubBasePath . '/' . $stubFiles[$stubName];
        if (! $this->files->exists($stubPath)) {
            throw new RuntimeException("Stub file not found: {$stubPath}");
        }
        
        return $stubPath;
    }
    
    private function createDirectoryWithErrorHandling( string $path ): bool
    {
        $permissions = config('package-generator.directories.permissions', 0755);
        
        try {
            if (! $this->files->isDirectory($path)) {
                $this->files->makeDirectory($path, $permissions, true);
            }
            
            return true;
        }
        catch (Exception $e) {
            $this->error("Failed to create directory $path: {$e->getMessage()}");
            
            return false;
        }
    }
    
    /**
     * Write file with error handling.
     */
    private function writeFileWithErrorHandling( string $path, string | false $content ): bool
    {
        if ($content === false) {
            $this->error("Failed to read content for {$path}: source file may not exist");
            
            return false;
        }
        try {
            $this->files->put($path, $content);
            
            return true;
        }
        catch (Exception $e) {
            $this->error("Failed to write file {$path}: {$e->getMessage()}");
            
            return false;
        }
    }
    
    /**
     * Display package information to the user.
     *
     * @param  array<string, mixed>  $packageData
     */
    private function displayPackageInfo( array $packageData ): void
    {
        $this->info('📦 Package Information:');
        $this->table(
            [ 'Property', 'Value' ],
            [
                [ 'Vendor', $packageData['vendor'] ],
                [ 'Package', $packageData['package'] ],
                [ 'Author', $packageData['author'] ],
                [ 'Description', $packageData['description'] ],
                [ 'License', $packageData['license'] ],
                [ 'PHP Version', $packageData['php_version'] ],
                [ 'Laravel Version', $packageData['laravel_version'] ],
                [ 'Facade', $packageData['facade'] ? '✅ Yes' : '❌ No' ],
                [ 'Config', $packageData['config'] ? '✅ Yes' : '❌ No' ],
                [ 'Migrations', $packageData['migrations'] ? '✅ Yes' : '❌ No' ],
                [ 'Views', $packageData['views'] ? '✅ Yes' : '❌ No' ],
                [ 'Routes', $packageData['routes'] ? '✅ Yes' : '❌ No' ],
                [ 'Tests', $packageData['tests'] ? '✅ Yes' : '❌ No' ],
                [ 'GitHub Actions', $packageData['github_actions'] ? '✅ Yes' : '❌ No' ],
            ]
        );
    }
    
    /**
     * Generate the complete package structure.
     *
     * @param  array<string, mixed>  $packageData
     */
    private function generatePackage( array $packageData ): bool
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
    private function getPackagePath( string $vendor, string $package ): string
    {
        // Check if path option is provided
        if ($this->option('path')) {
            return $this->option('path') . "/{$vendor}/{$package}";
        }
        $basePath = $this->getConfigString('directories.base_path', 'packages');
        
        return base_path($basePath . "/{$vendor}/{$package}");
    }
    
    /**
     * Process stub content with replacements.
     *
     * @param  array<string, string>  $replacements
     */
    private function processStub( string $stub, array $replacements ): string
    {
        $search  = array_keys($replacements);
        $replace = array_values($replacements);
        
        return str_replace($search, $replace, $stub);
    }
    
    /**
     * Create directory structure for the package.
     *
     * @param  array<string, mixed>  $packageData
     */
    protected function createDirectoryStructure( array $packageData ): bool
    {
        $vendor      = $packageData['vendor'];
        $package     = $packageData['package'];
        $packagePath = $this->getPackagePath($vendor, $package);
        $directories = $this->getConfigArray('directories.structure', [
            'src',
            'config',
            'database/migrations',
            'resources/views',
            'tests',
        ]);
        
        foreach ($directories as $directory) {
            $fullPath = $packagePath . '/' . $directory;
            if (! $this->createDirectoryWithErrorHandling($fullPath)) {
                return false;
            }
        }
        
        $this->info('✅ Directory structure created successfully.');
        
        return true;
    }
    
    /**
     * Create migrations directory and files.
     *
     * @param  array<string, mixed>  $packageData
     */
    protected function createMigrations( array $packageData ): bool
    {
        try {
            $migrationsPath = $this->getPackagePath($packageData['vendor'], $packageData['package']) . '/database/migrations';
            if ($this->createDirectoryWithErrorHandling($migrationsPath)) {
                $this->info('✅ Migrations directory created successfully.');
                
                return true;
            }
            
            return false;
        }
        catch (Exception $e) {
            $this->error("Failed to create migrations: {$e->getMessage()}");
            
            return false;
        }
    }
    
    /**
     * Create views directory and files.
     *
     * @param  array<string, mixed>  $packageData
     */
    protected function createViews( array $packageData ): bool
    {
        try {
            $viewsPath = $this->getPackagePath($packageData['vendor'], $packageData['package']) . '/resources/views';
            if ($this->createDirectoryWithErrorHandling($viewsPath)) {
                $this->info('✅ Views directory created successfully.');
                
                return true;
            }
            
            return false;
        }
        catch (Exception $e) {
            $this->error("Failed to create views: {$e->getMessage()}");
            
            return false;
        }
    }
    
    /**
     * Create routes directory and files.
     *
     * @param  array<string, mixed>  $packageData
     */
    protected function createRoutes( array $packageData ): bool
    {
        try {
            $routesPath = $this->getPackagePath($packageData['vendor'], $packageData['package']) . '/routes';
            if ($this->createDirectoryWithErrorHandling($routesPath)) {
                // Create web.php route file
                $webRoutesContent = "<?php
use Illuminate\Support\Facades\Route;
// Add your routes here
";
                $webRoutesFile    = $routesPath . '/web.php';
                if ($this->writeFileWithErrorHandling($webRoutesFile, $webRoutesContent)) {
                    $this->info('✅ Routes created successfully.');
                    
                    return true;
                }
            }
            
            return false;
        }
        catch (Exception $e) {
            $this->error("Failed to create routes: {$e->getMessage()}");
            
            return false;
        }
    }
    
    /**
     * Create composer.json file.
     *
     * @param  array<string, mixed>  $packageData
     */
    protected function createComposerJson( array $packageData ): bool
    {
        try {
            $stub         = $this->files->get($this->getStubPath('composer'));
            $replacements = [
                '{{vendor}}'          => $packageData['vendor'],
                '{{package}}'         => $packageData['package'],
                '{{Vendor}}'          => Str::studly($packageData['vendor']),
                '{{Package}}'         => Str::studly($packageData['package']),
                '{{author}}'          => $packageData['author'],
                '{{description}}'     => $packageData['description'],
                '{{license}}'         => $packageData['license'],
                '{{php_version}}'     => $packageData['php_version'],
                '{{laravel_version}}' => $packageData['laravel_version'],
            ];
            $content      = $this->processStub($stub, $replacements);
            $filePath     = $this->getPackagePath($packageData['vendor'], $packageData['package']) . '/composer.json';
            if ($this->writeFileWithErrorHandling($filePath, $content)) {
                $this->info('✅ composer.json created successfully.');
                
                return true;
            }
            
            return false;
        }
        catch (Exception $e) {
            $this->error("Failed to create composer.json: {$e->getMessage()}");
            
            return false;
        }
    }
    
    /**
     * Create main package class.
     *
     * @param  array<string, mixed>  $packageData
     */
    protected function createPackageClass( array $packageData ): bool
    {
        try {
            $stub         = $this->files->get($this->getStubPath('package'));
            $replacements = [
                '{{vendor}}'  => $packageData['vendor'],
                '{{package}}' => $packageData['package'],
                '{{Vendor}}'  => Str::studly($packageData['vendor']),
                '{{Package}}' => Str::studly($packageData['package']),
            ];
            $content      = $this->processStub($stub, $replacements);
            $filePath     = $this->getPackagePath($packageData['vendor'], $packageData['package']) . '/src/' . Str::studly($packageData['package']) . '.php';
            if ($this->writeFileWithErrorHandling($filePath, $content)) {
                $this->info('✅ Main package class created successfully.');
                
                return true;
            }
            
            return false;
        }
        catch (Exception $e) {
            $this->error("Failed to create package class: {$e->getMessage()}");
            
            return false;
        }
    }
    
    /**
     * Create service provider.
     *
     * @param  array<string, mixed>  $packageData
     */
    protected function createServiceProvider( array $packageData ): bool
    {
        try {
            $stub         = $this->files->get($this->getStubPath('service_provider'));
            $replacements = [
                '{{vendor}}'          => $packageData['vendor'],
                '{{package}}'         => $packageData['package'],
                '{{Vendor}}'          => Str::studly($packageData['vendor']),
                '{{Package}}'         => Str::studly($packageData['package']),
                '{{boot_loads}}'      => $this->generateBootLoads($packageData),
                '{{boot_publishes}}'  => $this->generateBootPublishes($packageData),
                '{{register_merges}}' => $this->generateRegisterMerges($packageData),
            ];
            $content      = $this->processStub($stub, $replacements);
            $filePath     = $this->getPackagePath($packageData['vendor'], $packageData['package']) . '/src/' . Str::studly($packageData['package']) . 'ServiceProvider.php';
            if ($this->writeFileWithErrorHandling($filePath, $content)) {
                $this->info('✅ Service provider created successfully.');
                
                return true;
            }
            
            return false;
        }
        catch (Exception $e) {
            $this->error("Failed to create service provider: {$e->getMessage()}");
            
            return false;
        }
    }
    
    /**
     * Create facade class.
     *
     * @param  array<string, mixed>  $packageData
     */
    protected function createFacade( array $packageData ): bool
    {
        try {
            $stub         = $this->files->get($this->getStubPath('facade'));
            $replacements = [
                '{{vendor}}'  => $packageData['vendor'],
                '{{package}}' => $packageData['package'],
                '{{Vendor}}'  => Str::studly($packageData['vendor']),
                '{{Package}}' => Str::studly($packageData['package']),
            ];
            $content      = $this->processStub($stub, $replacements);
            $facadesPath  = $this->getPackagePath($packageData['vendor'], $packageData['package']) . '/src/Facades';
            if (! $this->createDirectoryWithErrorHandling($facadesPath)) {
                return false;
            }
            $filePath = $facadesPath . '/' . Str::studly($packageData['package']) . '.php';
            if ($this->writeFileWithErrorHandling($filePath, $content)) {
                $this->info('✅ Facade created successfully.');
                
                return true;
            }
            
            return false;
        }
        catch (Exception $e) {
            $this->error("Failed to create facade: {$e->getMessage()}");
            
            return false;
        }
    }
    
    /**
     * Create configuration file.
     *
     * @param  array<string, mixed>  $packageData
     */
    protected function createConfigFile( array $packageData ): bool
    {
        try {
            $stub         = $this->files->get($this->getStubPath('config'));
            $replacements = [
                '{{package}}' => $packageData['package'],
            ];
            $content      = $this->processStub($stub, $replacements);
            $filePath     = $this->getPackagePath($packageData['vendor'], $packageData['package']) . '/config/' . $packageData['package'] . '.php';
            if ($this->writeFileWithErrorHandling($filePath, $content)) {
                $this->info('✅ Configuration file created successfully.');
                
                return true;
            }
            
            return false;
        }
        catch (Exception $e) {
            $this->error("Failed to create config file: {$e->getMessage()}");
            
            return false;
        }
    }
    
    /**
     * Create test framework files based on configuration.
     *
     * @param  array<string, mixed>  $packageData
     */
    protected function createTestFramework( array $packageData ): bool
    {
        $testingFramework = $this->getConfigString('testing.framework', 'pest');
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
     *
     * @param  array<string, mixed>  $packageData
     */
    protected function createPhpUnitXml( array $packageData ): bool
    {
        try {
            $stub         = $this->files->get($this->getStubPath('phpunit'));
            $replacements = [
                '{{package}}' => $packageData['package'],
            ];
            $content      = $this->processStub($stub, $replacements);
            $filePath     = $this->getPackagePath($packageData['vendor'], $packageData['package']) . '/phpunit.xml';
            if ($this->writeFileWithErrorHandling($filePath, $content)) {
                $this->info('✅ PHPUnit configuration created successfully.');
                
                return true;
            }
            
            return false;
        }
        catch (Exception $e) {
            $this->error("Failed to create PHPUnit configuration: {$e->getMessage()}");
            
            return false;
        }
    }
    
    /**
     * Create Pest configuration.
     *
     * @param  array<string, mixed>  $packageData
     */
    protected function createPestConfig( array $packageData ): bool
    {
        try {
            $stub         = $this->files->get($this->getStubPath('pest_config'));
            $replacements = [
                '{{vendor}}'  => $packageData['vendor'],
                '{{package}}' => $packageData['package'],
                '{{Vendor}}'  => Str::studly($packageData['vendor']),
                '{{Package}}' => Str::studly($packageData['package']),
            ];
            $content      = $this->processStub($stub, $replacements);
            $filePath     = $this->getPackagePath($packageData['vendor'], $packageData['package']) . '/tests/Pest.php';
            if ($this->writeFileWithErrorHandling($filePath, $content)) {
                $this->info('✅ Pest configuration created successfully.');
                
                return true;
            }
            
            return false;
        }
        catch (Exception $e) {
            $this->error("Failed to create Pest configuration: {$e->getMessage()}");
            
            return false;
        }
    }
    
    /**
     * Create test case.
     *
     * @param  array<string, mixed>  $packageData
     */
    protected function createTestCase( array $packageData ): bool
    {
        try {
            $stub         = $this->files->get($this->getStubPath('test_case'));
            $replacements = [
                '{{vendor}}'  => $packageData['vendor'],
                '{{package}}' => $packageData['package'],
                '{{Vendor}}'  => Str::studly($packageData['vendor']),
                '{{Package}}' => Str::studly($packageData['package']),
            ];
            $content      = $this->processStub($stub, $replacements);
            $filePath     = $this->getPackagePath($packageData['vendor'], $packageData['package']) . '/tests/TestCase.php';
            if ($this->writeFileWithErrorHandling($filePath, $content)) {
                $this->info('✅ Test case created successfully.');
                
                return true;
            }
            
            return false;
        }
        catch (Exception $e) {
            $this->error("Failed to create test case: {$e->getMessage()}");
            
            return false;
        }
    }
    
    /**
     * Create README.md file.
     *
     * @param  array<string, mixed>  $packageData
     */
    protected function createReadme( array $packageData ): bool
    {
        try {
            $stub         = $this->files->get($this->getStubPath('readme'));
            $replacements = [
                '{{vendor}}'      => $packageData['vendor'],
                '{{package}}'     => $packageData['package'],
                '{{Vendor}}'      => Str::studly($packageData['vendor']),
                '{{Package}}'     => Str::studly($packageData['package']),
                '{{author}}'      => $packageData['author'],
                '{{description}}' => $packageData['description'],
                '{{license}}'     => $packageData['license'],
            ];
            $content      = $this->processStub($stub, $replacements);
            $filePath     = $this->getPackagePath($packageData['vendor'], $packageData['package']) . '/README.md';
            if ($this->writeFileWithErrorHandling($filePath, $content)) {
                $this->info('✅ README.md created successfully.');
                
                return true;
            }
            
            return false;
        }
        catch (Exception $e) {
            $this->error("Failed to create README.md: {$e->getMessage()}");
            
            return false;
        }
    }
    
    /**
     * Create .gitignore file.
     *
     * @param  array<string, mixed>  $packageData
     */
    protected function createGitignore( array $packageData ): bool
    {
        try {
            $stub     = $this->files->get($this->getStubPath('gitignore'));
            $filePath = $this->getPackagePath($packageData['vendor'], $packageData['package']) . '/.gitignore';
            if ($this->writeFileWithErrorHandling($filePath, $stub)) {
                $this->info('✅ .gitignore created successfully.');
                
                return true;
            }
            
            return false;
        }
        catch (Exception $e) {
            $this->error("Failed to create .gitignore: {$e->getMessage()}");
            
            return false;
        }
    }
    
    /**
     * Create GitHub Actions workflow.
     *
     * @param  array<string, mixed>  $packageData
     */
    protected function createGithubAction( array $packageData ): bool
    {
        try {
            $stub         = $this->files->get($this->getStubPath('ci'));
            $replacements = [
                '{{package}}'         => $packageData['package'],
                '{{php_version}}'     => $packageData['php_version'],
                '{{laravel_version}}' => $packageData['laravel_version'],
            ];
            $content      = $this->processStub($stub, $replacements);
            $workflowPath = $this->getPackagePath($packageData['vendor'], $packageData['package']) . '/.github/workflows';
            if (! $this->createDirectoryWithErrorHandling($workflowPath)) {
                return false;
            }
            $filePath = $workflowPath . '/ci.yml';
            if ($this->writeFileWithErrorHandling($filePath, $content)) {
                $this->info('✅ GitHub Actions workflow created successfully.');
                // Create additional configuration files for code quality tools
                $this->createCodeQualityConfigs($packageData);
                
                return true;
            }
            
            return false;
        }
        catch (Exception $e) {
            $this->error("Failed to create GitHub Actions workflow: {$e->getMessage()}");
            
            return false;
        }
    }
    
    /**
     * Create Pest test files.
     *
     * @param  array<string, mixed>  $packageData
     */
    protected function createPestTests( array $packageData ): bool
    {
        $success = true;
        // Create Feature test if enabled
        if ($this->getConfigBool('testing.create_feature_tests', true)) {
            $success = $this->createPestFeatureTest($packageData);
        }
        // Create Unit test if enabled
        if ($this->getConfigBool('testing.create_unit_tests', true)) {
            $success = $success && $this->createPestUnitTest($packageData);
        }
        
        return $success;
    }
    
    /**
     * Create Pest Feature test.
     *
     * @param  array<string, mixed>  $packageData
     */
    protected function createPestFeatureTest( array $packageData ): bool
    {
        try {
            $stub         = $this->files->get($this->getStubPath('pest_feature_test'));
            $replacements = [
                '{{vendor}}'  => $packageData['vendor'],
                '{{package}}' => $packageData['package'],
                '{{Vendor}}'  => Str::studly($packageData['vendor']),
                '{{Package}}' => Str::studly($packageData['package']),
            ];
            $content      = $this->processStub($stub, $replacements);
            $filePath     = $this->getPackagePath($packageData['vendor'], $packageData['package']) . '/tests/Feature/' . Str::studly($packageData['package']) . 'Test.php';
            if ($this->writeFileWithErrorHandling($filePath, $content)) {
                $this->info('✅ Pest Feature test created successfully.');
                
                return true;
            }
            
            return false;
        }
        catch (Exception $e) {
            $this->error("Failed to create Pest Feature test: {$e->getMessage()}");
            
            return false;
        }
    }
    
    /**
     * Create Pest Unit test.
     *
     * @param  array<string, mixed>  $packageData
     */
    protected function createPestUnitTest( array $packageData ): bool
    {
        try {
            $stub         = $this->files->get($this->getStubPath('pest_unit_test'));
            $replacements = [
                '{{vendor}}'  => $packageData['vendor'],
                '{{package}}' => $packageData['package'],
                '{{Vendor}}'  => Str::studly($packageData['vendor']),
                '{{Package}}' => Str::studly($packageData['package']),
            ];
            $content      = $this->processStub($stub, $replacements);
            $filePath     = $this->getPackagePath($packageData['vendor'], $packageData['package']) . '/tests/Unit/' . Str::studly($packageData['package']) . 'Test.php';
            if ($this->writeFileWithErrorHandling($filePath, $content)) {
                $this->info('✅ Pest Unit test created successfully.');
                
                return true;
            }
            
            return false;
        }
        catch (Exception $e) {
            $this->error("Failed to create Pest Unit test: {$e->getMessage()}");
            
            return false;
        }
    }
    
    /**
     * Generate boot loads content for service provider.
     *
     * @param  array<string, mixed>  $packageData
     */
    protected function generateBootLoads( array $packageData ): string
    {
        $loads = [];
        if ($packageData['views'] ?? false) {
            $loads[] = "\$this->loadViewsFrom(__DIR__.'/../resources/views', '" . $packageData['package'] . "');";
        }
        if ($packageData['migrations'] ?? false) {
            $loads[] = "\$this->loadMigrationsFrom(__DIR__.'/../database/migrations');";
        }
        if ($packageData['routes'] ?? false) {
            $loads[] = "\$this->loadRoutesFrom(__DIR__.'/../routes/web.php');";
        }
        
        return implode("
        ", $loads);
    }
    
    /**
     * Generate boot publishes content for service provider.
     *
     * @param  array<string, mixed>  $packageData
     */
    protected function generateBootPublishes( array $packageData ): string
    {
        $publishes = [];
        if ($packageData['config'] ?? false) {
            $publishes[] = '$this->publishes([';
            $publishes[] = "    __DIR__.'/../config/" . $packageData['package'] . ".php' => config_path('" . $packageData['package'] . ".php'),";
            $publishes[] = "], 'config');";
        }
        if ($packageData['views'] ?? false) {
            $publishes[] = '$this->publishes([';
            $publishes[] = "    __DIR__.'/../resources/views' => resource_path('views/vendor/" . $packageData['package'] . "'),";
            $publishes[] = "], 'views');";
        }
        if ($packageData['migrations'] ?? false) {
            $publishes[] = '$this->publishes([';
            $publishes[] = "    __DIR__.'/../database/migrations' => database_path('migrations'),";
            $publishes[] = "], 'migrations');";
        }
        
        return implode("
            ", $publishes);
    }
    
    /**
     * Generate register merges content for service provider.
     *
     * @param  array<string, mixed>  $packageData
     */
    protected function generateRegisterMerges( array $packageData ): string
    {
        $merges = [];
        if ($packageData['config'] ?? false) {
            $merges[] = "\$this->mergeConfigFrom(__DIR__.'/../config/" . $packageData['package'] . ".php', '" . $packageData['package'] . "');";
        }
        
        return implode("
        ", $merges);
    }
    
    /**
     * Create code quality configuration files for the package.
     *
     * @param  array<string, mixed>  $packageData
     */
    protected function createCodeQualityConfigs( array $packageData ): void
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
    protected function createPintConfig( string $packagePath ): void
    {
        $pintConfig = [
            'preset' => 'laravel',
            'rules'  => [
                'binary_operator_spaces'            => [
                    'default'   => 'single_space',
                    'operators' => [ '=>' => null ],
                ],
                'blank_line_after_namespace'        => true,
                'blank_line_after_opening_tag'      => true,
                'blank_line_before_statement'       => [
                    'statements' => [ 'return' ],
                ],
                'braces'                            => true,
                'cast_spaces'                       => true,
                'class_attributes_separation'       => [
                    'elements' => [
                        'method' => 'one',
                    ],
                ],
                'no_unused_imports'                 => true,
                'ordered_imports'                   => [
                    'sort_algorithm' => 'alpha',
                ],
                'single_trait_insert_per_statement' => true,
            ],
        ];
        $content    = json_encode($pintConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $this->writeFileWithErrorHandling($packagePath . '/pint.json', $content);
    }
    
    /**
     * Create PHPStan configuration file.
     */
    protected function createPhpStanConfig( string $packagePath ): void
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
        $this->writeFileWithErrorHandling($packagePath . '/phpstan.neon', $phpstanConfig);
    }
}
