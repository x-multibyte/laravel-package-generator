# Laravel Package Generator

A powerful Laravel package that provides an Artisan command to generate complete Laravel packages with modern development tools and best practices.

## Features

- 🚀 **Complete Package Structure**: Generates standard Laravel package directory structure
- 🧪 **Testing Framework**: Supports both PHPUnit and Pest testing frameworks
- 🔧 **Code Quality Tools**: Integrated with PHPStan, Larastan, and Laravel Pint
- 📦 **CI/CD Ready**: Includes GitHub Actions workflow for automated testing
- 🎨 **Customizable Templates**: All stub files can be customized and published
- 🔒 **Type Safety**: Full type declarations and PHPDoc comments
- 📝 **Documentation**: Auto-generates README and package documentation

## Installation

Install the package via Composer:

```bash
composer require trae/laravel-package-generator --dev
```

The package will automatically register its service provider.

## Usage

### Basic Package Generation

Generate a new Laravel package with the following command:

```bash
php artisan package:new {vendor} {package}
```

Example:
```bash
php artisan package:new acme awesome-package
```

### Available Options

- `--config`: Generate package configuration file
- `--facade`: Generate package facade
- `--migration`: Generate database migrations
- `--model`: Generate Eloquent models
- `--controller`: Generate controllers
- `--middleware`: Generate middleware
- `--view`: Generate Blade views
- `--route`: Generate route files
- `--test`: Generate test files (default: enabled)
- `--ci`: Generate CI/CD workflow (default: enabled)

### Example with Options

```bash
php artisan package:new acme awesome-package --config --facade --migration --model
```

## Configuration

Publish the configuration file to customize package generation:

```bash
php artisan vendor:publish --tag=laravel-package-generator-config
```

This will publish `config/laravel-package-generator.php` where you can customize:

- Default package information (author, email, license)
- Feature flags for components
- Testing framework preference (PHPUnit or Pest)
- Stub file locations

## Customizing Templates

Publish the stub templates to customize generated files:

```bash
php artisan vendor:publish --tag=laravel-package-generator-stubs
```

This will publish all stub files to `resources/stubs/laravel-package-generator/` where you can modify:

- Package structure templates
- Service provider templates
- Test file templates
- CI/CD workflow templates
- And more...

## Generated Package Structure

The generated package follows Laravel package conventions:

```
vendor/package/
├── src/
│   ├── Commands/           # Artisan commands
│   ├── Http/
│   │   ├── Controllers/    # Controllers
│   │   └── Middleware/     # Middleware
│   ├── Models/             # Eloquent models
│   ├── Facades/            # Package facades
│   └── PackageServiceProvider.php
├── config/                 # Configuration files
├── database/
│   ├── migrations/         # Database migrations
│   └── factories/          # Model factories
├── resources/
│   └── views/              # Blade templates
├── routes/                 # Route definitions
├── tests/
│   ├── Feature/            # Feature tests
│   └── Unit/               # Unit tests
├── .github/workflows/      # CI/CD workflows
├── composer.json
├── phpunit.xml
├── README.md
└── .gitignore
```

## Code Quality Tools

Generated packages include modern development tools:

### Laravel Pint
Automatic code formatting following Laravel conventions:
```bash
composer format
```

### PHPStan + Larastan
Static analysis for type safety and code quality:
```bash
composer analyse
```

### Testing
Run tests with Pest or PHPUnit:
```bash
composer test
```

### All Quality Checks
Run all quality tools at once:
```bash
composer quality
```

## CI/CD Integration

Generated packages include GitHub Actions workflow that automatically:

- Runs tests on multiple PHP versions (8.1, 8.2, 8.3)
- Performs code quality checks
- Validates code formatting
- Runs static analysis

## Requirements

- PHP 8.1 or higher
- Laravel 10.0 or higher
- Composer

## Contributing

Contributions are welcome! Please feel free to submit a Pull Request.

## License

This package is open-sourced software licensed under the [MIT license](LICENSE).

## Credits

- **Author**: Trae AI
- **Email**: support@trae.ai
- **Website**: [https://trae.ai](https://trae.ai)

## Support

If you discover any security vulnerabilities or have questions, please email support@trae.ai.