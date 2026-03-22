# Contributing

> ⚠ **Beta software.** All contributions and feedback are welcome.

---

## Reporting Issues

Use [GitHub Issues](https://github.com/richardkentgates/metamanager/issues) to report bugs, unexpected behaviour, or documentation gaps. Please include:

- WordPress version and PHP version
- Linux distro and version
- Steps to reproduce
- Any relevant output from `journalctl -u metamanager-compress-daemon` or `metamanager-meta-daemon`

---

## Development Setup

No build step is required for the PHP plugin files. For static analysis:

```bash
# Clone the repo
git clone https://github.com/richardkentgates/metamanager.git
cd metamanager

# Install dev dependencies (PHPStan + WordPress stubs)
composer install
```

---

## Running Static Analysis Locally

### PHP syntax check

```bash
find . -name "*.php" ! -path "./vendor/*" -print0 | xargs -0 php -l
```

### PHPStan (level 5 with WordPress stubs)

```bash
vendor/bin/phpstan analyse --no-progress
```

Configuration is in [`phpstan.neon`](https://github.com/richardkentgates/metamanager/blob/main/phpstan.neon) at the repo root.

---

## CI / Code Scanning

Every push to `main` and every pull request runs the **Code Scanning** workflow (`.github/workflows/codeql.yml`), which has six jobs:

| Job | Tool | PHP version | Scope |
|-----|------|-------------|-------|
| **PHPUnit Integration Tests** | PHPUnit 9.6 + wp-phpunit | 8.0, 8.1, 8.2, 8.3 | Full integration test suite (62 tests, 114 assertions) against a real WordPress install and MySQL 8.0 |
| **PHP Lint** | `php -l` | 8.0, 8.1, 8.2, 8.3 | Syntax check on all PHP files |
| **PHPStan** | Level 5 with WordPress stubs | 8.0, 8.1, 8.2, 8.3 | Static analysis — must produce zero errors |
| **CodeQL — JavaScript** | GitHub CodeQL (`security-and-quality`) | — | `assets/js/` |

PHP is not a CodeQL language — CodeQL results appear on the Security tab for the JavaScript file only. PHP analysis and test results appear in the Actions tab.

The workflow also runs on a weekly schedule (Sunday 03:00 UTC).

---

## Submitting Changes

1. Fork the repository
2. Create a branch: `git checkout -b fix/your-description`
3. Make changes and run static analysis locally (see above)
4. Commit with a clear message following the existing style (prefix: `Fix:`, `Feature:`, `Docs:`, `CI:`, etc.)
5. Open a pull request against `main`

There is no formal coding standard enforced yet beyond what PHPStan level 5 catches. WordPress coding style conventions are appreciated but not required.
