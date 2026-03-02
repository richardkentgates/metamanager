<?php
/**
 * Metamanager Status Class
 *
 * Checks the health of all external dependencies:
 * - ExifTool availability and path
 * - jpegtran / optipng availability (lossless compression tools)
 * - Daemon liveness via PID files (no systemctl needed)
 * - Per-image compression status
 *
 * @package Metamanager
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class MM_Status
 */
class MM_Status {

	// -----------------------------------------------------------------------
	// Tool detection
	// -----------------------------------------------------------------------

	/**
	 * Candidate paths for ExifTool.
	 */
	private const EXIFTOOL_PATHS = [ '/usr/bin/exiftool', '/usr/local/bin/exiftool' ];

	/**
	 * Candidate paths for jpegtran (libjpeg / libjpeg-turbo).
	 */
	private const JPEGTRAN_PATHS = [ '/usr/bin/jpegtran', '/usr/local/bin/jpegtran' ];

	/**
	 * Candidate paths for optipng.
	 */
	private const OPTIPNG_PATHS = [ '/usr/bin/optipng', '/usr/local/bin/optipng' ];

	/**
	 * Check whether ExifTool is installed and executable.
	 */
	public static function exiftool_available(): bool {
		return (bool) self::exiftool_path();
	}

	/**
	 * Return the first found ExifTool executable path, or empty string.
	 */
	public static function exiftool_path(): string {
		foreach ( self::EXIFTOOL_PATHS as $path ) {
			if ( file_exists( $path ) && is_executable( $path ) ) {
				return $path;
			}
		}
		return '';
	}

	/**
	 * Check whether jpegtran is available.
	 */
	public static function jpegtran_available(): bool {
		return (bool) self::jpegtran_path();
	}

	/**
	 * Return the first found jpegtran executable path.
	 */
	public static function jpegtran_path(): string {
		foreach ( self::JPEGTRAN_PATHS as $path ) {
			if ( file_exists( $path ) && is_executable( $path ) ) {
				return $path;
			}
		}
		return '';
	}

	/**
	 * Check whether optipng is available.
	 */
	public static function optipng_available(): bool {
		return (bool) self::optipng_path();
	}

	/**
	 * Return the first found optipng executable path.
	 */
	public static function optipng_path(): string {
		foreach ( self::OPTIPNG_PATHS as $path ) {
			if ( file_exists( $path ) && is_executable( $path ) ) {
				return $path;
			}
		}
		return '';
	}

	// -----------------------------------------------------------------------
	// Daemon liveness
	//
	// The daemons write their PID to /tmp on startup. PHP reads the PID file
	// and confirms the process is still running via /proc. This works for
	// www-data without systemctl privileges.
	// -----------------------------------------------------------------------

	/**
	 * Check whether the compression daemon process is alive.
	 */
	public static function compress_daemon_running(): bool {
		return self::is_pid_alive( MM_PID_COMPRESS );
	}

	/**
	 * Check whether the metadata daemon process is alive.
	 */
	public static function meta_daemon_running(): bool {
		return self::is_pid_alive( MM_PID_META );
	}

	/**
	 * Read a PID file and check /proc/<pid> to confirm the process is alive.
	 *
	 * @param string $pid_file Absolute path to the PID file.
	 * @return bool
	 */
	private static function is_pid_alive( string $pid_file ): bool {
		if ( ! file_exists( $pid_file ) ) {
			return false;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$pid = (int) trim( file_get_contents( $pid_file ) );
		if ( $pid < 2 ) {
			return false;
		}

		// /proc/<pid> exists as a directory while the process is running.
		return is_dir( '/proc/' . $pid );
	}

	// -----------------------------------------------------------------------
	// Per-image compression status
	// -----------------------------------------------------------------------

	/**
	 * Mark a specific image size as compressed in post meta.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $size          Size slug (e.g. 'full', 'thumbnail').
	 */
	public static function mark_compressed( int $attachment_id, string $size ): void {
		update_post_meta( $attachment_id, '_mm_compressed_' . $size, '1' );
	}

	/**
	 * Check whether a specific image size has been compressed.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $size          Size slug.
	 * @return bool
	 */
	public static function is_compressed( int $attachment_id, string $size ): bool {
		return '1' === get_post_meta( $attachment_id, '_mm_compressed_' . $size, true );
	}

	/**
	 * Return a status summary for an attachment: all, partial, none, or na.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array{ status: string, label: string, color: string }
	 */
	public static function compression_status( int $attachment_id ): array {
		if ( ! wp_attachment_is_image( $attachment_id ) ) {
			return [ 'status' => 'na', 'label' => '—', 'color' => '#bbb' ];
		}

		$meta = wp_get_attachment_metadata( $attachment_id ) ?: [];
		$file = get_attached_file( $attachment_id );

		$all_compressed = true;
		$any_compressed = false;

		if ( $file && file_exists( $file ) ) {
			if ( self::is_compressed( $attachment_id, 'full' ) ) {
				$any_compressed = true;
			} else {
				$all_compressed = false;
			}
		}

		if ( ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
			$dir = trailingslashit( pathinfo( $file, PATHINFO_DIRNAME ) );
			foreach ( $meta['sizes'] as $size => $info ) {
				if ( empty( $info['file'] ) || ! file_exists( $dir . $info['file'] ) ) {
					continue;
				}
				if ( self::is_compressed( $attachment_id, $size ) ) {
					$any_compressed = true;
				} else {
					$all_compressed = false;
				}
			}
		}

		if ( $all_compressed && $any_compressed ) {
			return [ 'status' => 'all', 'label' => '✔ Compressed', 'color' => '#13bb2c' ];
		}
		if ( $any_compressed ) {
			return [ 'status' => 'partial', 'label' => '● Partial', 'color' => '#e6b800' ];
		}
		return [ 'status' => 'none', 'label' => '✘ Not Compressed', 'color' => '#e54c3c' ];
	}

	// -----------------------------------------------------------------------
	// Full system status snapshot (used by status banner)
	// -----------------------------------------------------------------------

	/**
	 * Return a summary of all system dependencies.
	 *
	 * @return array<string, bool>
	 */
	public static function system_status(): array {
		return [
			'exiftool'         => self::exiftool_available(),
			'jpegtran'         => self::jpegtran_available(),
			'optipng'          => self::optipng_available(),
			'compress_daemon'  => self::compress_daemon_running(),
			'meta_daemon'      => self::meta_daemon_running(),
		];
	}
}
