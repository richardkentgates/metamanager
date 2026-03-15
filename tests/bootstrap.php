<?php
/**
 * PHPUnit bootstrap for Metamanager integration tests.
 *
 * Uses the wp-phpunit/wp-phpunit Composer package (already in vendor/) as
 * the WordPress test library — no SVN checkout or separate download needed.
 *
 * Before running the suite for the first time:
 *   1. Run bin/install-wp-tests.sh to create the test DB and write
 *      tests/wp-tests-config.php with your local credentials.
 *   2. Ensure ABSPATH in wp-tests-config.php points to a WordPress
 *      installation (e.g. /srv/www/wordpress on the test server, or
 *      /tmp/wordpress in CI after downloading WP core).
 */

// Point wp-phpunit at our local DB/path config file.
// The env var takes precedence so CI can override via workflow env.
if ( ! getenv( 'WP_PHPUNIT__TESTS_CONFIG' ) ) {
	putenv( 'WP_PHPUNIT__TESTS_CONFIG=' . __DIR__ . '/wp-tests-config.php' );
}

// Tell the WP bootstrap where to find the PHPUnit Polyfills library.
if ( ! defined( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' ) ) {
	define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', dirname( __DIR__ ) . '/vendor/yoast/phpunit-polyfills' );
}

$_wp_phpunit_bootstrap = dirname( __DIR__ ) . '/vendor/wp-phpunit/wp-phpunit/includes/bootstrap.php';
if ( ! file_exists( $_wp_phpunit_bootstrap ) ) {
	echo PHP_EOL;
	echo 'ERROR: Cannot find vendor/wp-phpunit/wp-phpunit/includes/bootstrap.php' . PHP_EOL;
	echo 'Run:  composer install' . PHP_EOL;
	echo PHP_EOL;
	exit( 1 );
}

$_tests_config = __DIR__ . '/wp-tests-config.php';
if ( ! getenv( 'WP_PHPUNIT__TESTS_CONFIG' ) && ! file_exists( $_tests_config ) ) {
	echo PHP_EOL;
	echo 'ERROR: Cannot find tests/wp-tests-config.php' . PHP_EOL;
	echo 'Run:  bash bin/install-wp-tests.sh <db> <user> <pass> [host] [wp-path]' . PHP_EOL;
	echo PHP_EOL;
	exit( 1 );
}

// Boot the WP test suite (defines WP functions, WP_UnitTestCase, factories,
// and installs a fresh test DB that is wiped per-test).
require_once $_wp_phpunit_bootstrap;

// Define filesystem constants that WP normally sets via wp-admin/includes/file.php.
// The WP test bootstrap does not load the admin includes, so job-queue code
// that writes .htaccess files needs these to be present.
if ( ! defined( 'FS_CHMOD_FILE' ) ) {
	define( 'FS_CHMOD_FILE', 0644 );
}
if ( ! defined( 'FS_CHMOD_DIR' ) ) {
	define( 'FS_CHMOD_DIR', 0755 );
}

// Force WP_Filesystem to use direct I/O in tests so job-queue .htaccess writes
// don't try to use FTP (which may be configured in the live WP options table).
add_filter( 'filesystem_method', fn() => 'direct' );

// ---------------------------------------------------------------------------
// Bootstrap the plugin
// ---------------------------------------------------------------------------

// Define constants that the plugin normally gets from its header / define() calls.
if ( ! defined( 'MM_VERSION' ) ) {
	define( 'MM_VERSION',     '0.0.0-test' );
}
if ( ! defined( 'MM_PLUGIN_FILE' ) ) {
	define( 'MM_PLUGIN_FILE', dirname( __DIR__ ) . '/metamanager.php' );
}
if ( ! defined( 'MM_PLUGIN_DIR' ) ) {
	define( 'MM_PLUGIN_DIR',  dirname( __DIR__ ) . '/' );
}
if ( ! defined( 'MM_PLUGIN_URL' ) ) {
	define( 'MM_PLUGIN_URL',  'http://example.org/wp-content/plugins/metamanager/' );
}
if ( ! defined( 'MM_JOB_ROOT' ) ) {
	define( 'MM_JOB_ROOT',     sys_get_temp_dir() . '/metamanager-test-jobs' );
}
if ( ! defined( 'MM_JOB_COMPRESS' ) ) {
	define( 'MM_JOB_COMPRESS', MM_JOB_ROOT . '/compress/' );
}
if ( ! defined( 'MM_JOB_META' ) ) {
	define( 'MM_JOB_META',     MM_JOB_ROOT . '/meta/' );
}
if ( ! defined( 'MM_JOB_DONE' ) ) {
	define( 'MM_JOB_DONE',     MM_JOB_ROOT . '/completed/' );
}
if ( ! defined( 'MM_JOB_FAILED' ) ) {
	define( 'MM_JOB_FAILED',   MM_JOB_ROOT . '/failed/' );
}
if ( ! defined( 'MM_JOB_TABLE' ) ) {
	define( 'MM_JOB_TABLE', 'metamanager_jobs' );
}
if ( ! defined( 'MM_PID_COMPRESS' ) ) {
	define( 'MM_PID_COMPRESS', MM_JOB_ROOT . '/compress-daemon.pid' );
}
if ( ! defined( 'MM_PID_META' ) ) {
	define( 'MM_PID_META',     MM_JOB_ROOT . '/meta-daemon.pid' );
}

// WP_CLI\Utils stubs — needed by Test_MM_CLI.php; loaded here so the test
// file itself has no namespace declarations (avoids Intelephense false positives).
require_once __DIR__ . '/stubs/wp-cli-utils.php';

// Load all plugin classes.
$plugin_includes = dirname( __DIR__ ) . '/includes/';
require_once $plugin_includes . 'class-mm-db.php';
require_once $plugin_includes . 'class-mm-job-queue.php';
require_once $plugin_includes . 'class-mm-metadata.php';
require_once $plugin_includes . 'class-mm-status.php';
require_once $plugin_includes . 'class-mm-settings.php';
require_once $plugin_includes . 'class-mm-frontend.php';
