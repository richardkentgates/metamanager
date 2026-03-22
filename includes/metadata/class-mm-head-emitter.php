<?php
/**
 * MM_Head_Emitter — the single wp_head emitter.
 *
 * Architecture: each module adds to a shared $data array via populate().
 * The Document renders everything in one clean block.  Nothing is echoed
 * from individual modules — they only write to $data.
 *
 * $data shape:
 * [
 *   'title'  => string,
 *   'meta'   => [ ['name'=>..., 'content'=>...], ... ],
 *   'links'  => [ ['rel'=>..., 'href'=>...], ... ],
 *   'schema' => array  (will be JSON-encoded as @graph),
 * ]
 */

defined( 'ABSPATH' ) || exit;

class MM_Head_Emitter {

	/** @var MM_Mod_Base[] */
	private array $modules = [];

	private MM_Page_Context  $context;
	private MM_Site_Settings $settings;

	public function __construct( MM_Page_Context $context, MM_Site_Settings $settings ) {
		$this->context  = $context;
		$this->settings = $settings;
	}

	public function add_module( MM_Mod_Base $module ): void {
		$this->modules[] = $module;
	}

	// -------------------------------------------------------------------------
	// wp_head callback
	// -------------------------------------------------------------------------

	public function render(): void {
		$data = [
			'title'  => '',
			'meta'   => [],
			'links'  => [],
			'schema' => [],
		];

		foreach ( $this->modules as $module ) {
			$module->populate( $data, $this->context, $this->settings );
		}

		/**
		 * Filter the full document data array before rendering.
		 *
		 * @param array             $data     The assembled head data.
		 * @param MM_Page_Context  $context  Page context.
		 */
		$data = apply_filters( 'mm_meta_document', $data, $this->context );

		echo "\n<!-- Metamanager " . esc_html( MM_META_VERSION ) . " -->\n";

		// <title> — only emitted here if the theme does not support title-tag.
		// Modern themes set the title via the pre_get_document_title filter.
		if ( $data['title'] && ! current_theme_supports( 'title-tag' ) ) {
			echo '<title>' . esc_html( $data['title'] ) . '</title>' . "\n";
		}

		// <meta> tags.
		foreach ( $data['meta'] as $meta ) {
			$meta = array_map( 'esc_attr', $meta );
			if ( isset( $meta['property'] ) ) {
				printf( '<meta property="%s" content="%s" />' . "\n", $meta['property'], $meta['content'] );
			} elseif ( isset( $meta['name'] ) ) {
				printf( '<meta name="%s" content="%s" />' . "\n", $meta['name'], $meta['content'] );
			} elseif ( isset( $meta['http-equiv'] ) ) {
				printf( '<meta http-equiv="%s" content="%s" />' . "\n", $meta['http-equiv'], $meta['content'] );
			}
		}

		// <link> tags.
		foreach ( $data['links'] as $link ) {
			$link = array_map( 'esc_attr', $link );
			$attrs = '';
			foreach ( $link as $attr => $val ) {
				$attrs .= sprintf( ' %s="%s"', esc_attr( $attr ), $val );
			}
			echo '<link' . $attrs . ' />' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		// JSON-LD @graph.
		if ( ! empty( $data['schema'] ) ) {
			$graph = [
				'@context' => 'https://schema.org',
				'@graph'   => array_values( $data['schema'] ),
			];
			echo '<script type="application/ld+json">'
				. wp_json_encode( $graph, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT )
				. '</script>' . "\n";
		}

		echo "<!-- /Metamanager -->\n\n";
	}
}
