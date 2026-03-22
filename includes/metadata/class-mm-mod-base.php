<?php
/**
 * MM_Mod_Base — abstract base class for all SEO modules.
 *
 * Each concrete module extends this class and implements populate(), which
 * writes its output into the shared $data array rather than echoing directly.
 */

defined( 'ABSPATH' ) || exit;

abstract class MM_Mod_Base {

	protected MM_Site_Settings $settings;

	public function __construct( MM_Site_Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Populate the shared document data array.
	 *
	 * @param array            $data     Passed by reference.
	 * @param MM_Page_Context $context  Current page context.
	 * @param MM_Site_Settings $settings Plugin settings.
	 */
	abstract public function populate( array &$data, MM_Page_Context $context, MM_Site_Settings $settings ): void;

	// -------------------------------------------------------------------------
	// Shared helpers available to all modules
	// -------------------------------------------------------------------------

	/** Add a meta tag to the data array, preventing duplicates by name/property. */
	protected function add_meta( array &$data, array $attrs ): void {
		$key = $attrs['name'] ?? $attrs['property'] ?? null;
		if ( $key ) {
			// Remove existing with same identifier.
			$data['meta'] = array_values( array_filter( $data['meta'], function ( $m ) use ( $key ) {
				return ( $m['name'] ?? $m['property'] ?? null ) !== $key;
			} ) );
		}
		$data['meta'][] = $attrs;
	}

	/** Add a link tag. */
	protected function add_link( array &$data, array $attrs ): void {
		$data['links'][] = $attrs;
	}

	/** Add or replace a schema node by @id. */
	protected function add_node( array &$data, array $node ): void {
		if ( isset( $node['@id'] ) ) {
			foreach ( $data['schema'] as $i => $existing ) {
				if ( ( $existing['@id'] ?? '' ) === $node['@id'] ) {
					$data['schema'][ $i ] = $node;
					return;
				}
			}
		}
		$data['schema'][] = $node;
	}

	/** Merge additional properties into an existing schema node by @id. */
	protected function merge_node( array &$data, string $id, array $extra ): void {
		foreach ( $data['schema'] as $i => $node ) {
			if ( ( $node['@id'] ?? '' ) === $id ) {
				$data['schema'][ $i ] = array_merge( $node, $extra );
				return;
			}
		}
	}

	/** Safe site URL with trailing slash. */
	protected function site_url(): string {
		return trailingslashit( home_url() );
	}

	/** Build a schema @id string for the site. */
	protected function site_id( string $fragment = '' ): string {
		return $this->site_url() . ( $fragment ? '#' . $fragment : '' );
	}

	/**
	 * Get the best available image data (URL, width, height) for a given
	 * attachment ID or direct URL, with graceful fallback.
	 *
	 * @return array{url: string, width: int, height: int}
	 */
	protected function image_data( int $attachment_id = 0, string $url = '' ): array {
		if ( $attachment_id ) {
			$meta = wp_get_attachment_metadata( $attachment_id );
			$src  = wp_get_attachment_image_src( $attachment_id, 'full' );
			return [
				'url'    => $src ? $src[0] : '',
				'width'  => $meta['width']  ?? ( $src[1] ?? 0 ),
				'height' => $meta['height'] ?? ( $src[2] ?? 0 ),
			];
		}
		if ( $url ) {
			// Try to find the attachment by URL.
			$id = attachment_url_to_postid( $url );
			if ( $id ) {
				return $this->image_data( $id );
			}
			return [ 'url' => $url, 'width' => 0, 'height' => 0 ];
		}
		return [ 'url' => '', 'width' => 0, 'height' => 0 ];
	}
}
