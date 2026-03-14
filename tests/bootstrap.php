<?php
/**
 * PHPUnit bootstrap for Metamanager integration tests.
 *
 * Loads the WordPress test environment and then bootstraps the plugin so
 * all classes and constants are available inside every test case.
 *
 * Requirements:
 *   - Run bin/install-wp-tests.sh to install the WP test library and create
 *     the test database before running the suite for the first time.
 *   - The WP_TESTS_DIR environment variable must point to the installed
 *     library (default: /tmp/wordpress-tests-lib).
 */

$_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $_tests_dir ) {
	$_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

if ( ! file_exists( $_tests_dir . '/includes/bootstrap.php' ) ) {
	echo PHP_EOL;
	echo 'ERROR: Cannot find the WordPress test library.' . PHP_EOL;
	echo "Looked in: {$_tests_dir}/includes/bootstrap.php" . PHP_EOL;
	echo PHP_EOL;
	echo 'Run this once to install it:' . PHP_EOL;
	echo '  bash bin/install-wp-tests.sh <db> <user> <pass>' . PHP_EOL;
	echo PHP_EOL;
	exit( 1 );
}

// Point WordPress at its test core directory.
$_wp_dir = getenv( 'WP_CORE_DIR' );
if ( ! $_wp_dir ) {
	$_wp_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress';
}
define( 'ABSPATH', trailingslashify( $_wp_dir ) );

// Boot the WP test suite. This defines WP functions, WP_UnitTestCase, the
// factory system, and sets up/tears down a test DB transaction per test.
require_once $_tests_dir . '/includes/bootstrap.php';

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

// Load all plugin classes.
$plugin_includes = dirname( __DIR__ ) . '/includes/';
require_once $plugin_includes . 'class-mm-db.php';
require_once $plugin_includes . 'class-mm-job-queue.php';
require_once $plugin_includes . 'class-mm-metadata.php';
require_once $plugin_includes . 'class-mm-status.php';
require_once $plugin_includes . 'class-mm-settings.php';
require_once $plugin_includes . 'class-mm-frontend.php';
