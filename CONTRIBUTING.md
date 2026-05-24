# Contributing to MetaManager

## Development Setup

This is a WordPress plugin with bash daemons — no Composer dependencies.

### Requirements
- PHP 8.1+
- WordPress 6.4+
- ExifTool (`sudo apt install libimage-exiftool-perl`)

### Local Testing

Download PHPUnit and PHPStan as PHARs:

```bash
# PHPUnit
curl -L https://phar.phpunit.de/phpunit-9.6.0.phar -o /usr/local/bin/phpunit
chmod +x /usr/local/bin/phpunit

# PHPStan
curl -L https://github.com/phpstan/phpstan/releases/latest/download/phpstan.phar -o /usr/local/bin/phpstan
chmod +x /usr/local/bin/phpstan
```

Run WordPress tests:
```bash
bash tests/bin/install-wp-tests.sh wordpress_test root root 127.0.0.1 latest false
phpunit --configuration tests/phpunit.xml
```

Run static analysis:
```bash
phpstan analyse --configuration phpstan.neon
```

## Pull Requests
- Keep changes focused on a single issue.
- Test on a real LAMP server before submitting.
- No Composer dependencies — this is server software.
