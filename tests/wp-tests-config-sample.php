<?php
/**
 * WordPress test suite configuration — sample file.
 *
 * Copy this file to tests/wp-tests-config.php (gitignored) and fill in
 * your local values, OR run:
 *
 *   bash bin/install-wp-tests.sh <db-name> <db-user> <db-pass> [db-host] [wp-path]
 *
 * which generates the config automatically.
 *
 * ABSPATH must point to an existing WordPress installation:
 *   - Development server:  /srv/www/wordpress/
 *   - Local install:       /var/www/html/wordpress/
 *   - CI (downloaded):     /tmp/wordpress/
 */
define( 'ABSPATH',        '/path/to/wordpress/' );
define( 'DB_NAME',        'wordpress_test' );
define( 'DB_USER',        'db_user' );
define( 'DB_PASSWORD',    'db_password' );
define( 'DB_HOST',        'localhost' );
define( 'DB_CHARSET',     'utf8' );
define( 'DB_COLLATE',     '' );
$table_prefix             = 'wptests_';
define( 'WP_TESTS_DOMAIN', 'example.org' );
define( 'WP_TESTS_EMAIL',  'admin@example.org' );
define( 'WP_TESTS_TITLE',  'Test Blog' );
define( 'WP_PHP_BINARY',   'php' );
define( 'WPLANG',          '' );
