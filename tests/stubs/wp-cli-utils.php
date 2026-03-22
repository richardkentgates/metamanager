<?php
/**
 * Minimal stubs for the WP_CLI\Utils namespace.
 *
 * Loaded by Test_MM_CLI.php so that class-mm-cli.php can be required in a
 * plain-namespace test file without mixing namespace blocks.
 *
 * Bracketed namespace syntax is required so the ABSPATH guard can appear
 * before the named namespace declaration (PHP requires namespace to be
 * the first statement; a global namespace block satisfies this).
 *
 * @package Metamanager\Tests\Stubs
 */

// phpcs:ignore WordPress.NamingConventions -- mixed global/named namespace blocks required here.
namespace {
	defined( 'ABSPATH' ) || exit;
}

namespace WP_CLI\Utils {

	function make_progress_bar( string $msg, int $count ): object {
		return new class {
			public function tick(): void {}
			public function finish(): void {}
		};
	}

	function format_items( string $format, array $items, array $columns ): void {}
}
