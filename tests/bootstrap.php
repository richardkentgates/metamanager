<?php
/**
 * Bootstrap for PHPUnit tests.
 * No Composer autoload — this is a server plugin.
 */

// Load WordPress test suite (set up by wp-phpunit).
$wp_tests_dir = getenv('WP_TESTS_DIR') ?: '/tmp/wordpress-tests-lib';
if ( ! file_exists($wp_tests_dir . '/includes/functions.php') ) {
    echo "ERROR: WordPress test suite not found at $wp_tests_dir\n";
    exit(1);
}
require_once $wp_tests_dir . '/includes/functions.php';

// Load the plugin.
require_once dirname(__FILE__) . '/../metamanager.php';
