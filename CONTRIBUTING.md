# Contributing to Laravel Cloudflare D1 Driver

Thank you for considering contributing to this project! We welcome contributions from the community.

## How to Contribute

### Reporting Bugs

If you find a bug, please open an issue on GitHub with:
- A clear description of the issue
- Steps to reproduce the problem
- Expected vs actual behavior
- Laravel and PHP versions
- D1 database details (if relevant)

### Suggesting Features

Feature requests are welcome! Please open an issue with:
- Clear description of the proposed feature
- Use cases and benefits
- Any implementation ideas (optional)

### Pull Requests

1. **Fork the repository**
2. **Create a feature branch** from `main`:
   ```bash
   git checkout -b feature/your-feature-name
   ```

3. **Write your code**:
   - Follow PSR-12 coding standards
   - Add tests for new functionality
   - Update documentation if needed

4. **Run tests**:
   ```bash
   composer test
   ```

5. **Commit your changes**:
   - Use clear, descriptive commit messages
   - Reference issue numbers if applicable

6. **Push to your fork** and submit a pull request

## Development Setup

### Requirements
- PHP 8.2+
- Composer
- Laravel 11, 12, or 13

### Installation

```bash
# Clone your fork
git clone https://github.com/YOUR-USERNAME/laravel-cloudflare-d1.git
cd laravel-cloudflare-d1

# Install dependencies
composer install

# Run tests
composer test
```

### Running Tests

```bash
# Run all tests
composer test

# Run specific test file
vendor/bin/phpunit tests/Feature/D1ConnectionTest.php

# Run with coverage
composer test-coverage
```

## Coding Standards

- Follow [PSR-12](https://www.php-fig.org/psr/psr-12/) coding style
- Write meaningful variable and method names
- Add PHPDoc blocks for classes and methods
- Keep methods focused and concise

## Testing Guidelines

- Write tests for all new features
- Ensure all existing tests pass
- Aim for high test coverage
- Use descriptive test method names

Example:
```php
public function test_d1_connection_can_execute_select_query(): void
{
    $result = DB::connection('d1')->select('SELECT 1 as test');
    $this->assertEquals(1, $result[0]->test);
}
```

## Documentation

- Update README.md for user-facing changes
- Update CHANGELOG.md following [Keep a Changelog](https://keepachangelog.com/) format
- Update MIGRATION_GUIDE.md for migration-related changes
- Add inline code comments for complex logic

## Questions?

Feel free to open an issue for any questions about contributing!

## License

By contributing, you agree that your contributions will be licensed under the MIT License.
