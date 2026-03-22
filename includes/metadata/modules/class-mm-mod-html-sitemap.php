<?php
/**
 * MM_Mod_Html_Sitemap — [mm_sitemap] shortcode.
 *
 * Renders a hierarchical HTML sitemap respecting noindex flags and
 * page exclusions. Parameters:
 *
 *   post_types  (comma-separated, default: page,post)
 *   taxonomies  (comma-separated, default: empty)
 *   depth       (int, default: 3, 0 = unlimited)
 *   exclude     (comma-separated post IDs)
 *   columns     (1–3, default: 1)
 *   show_date   (yes|no, default: no)
 *   order_by    (menu_order|title|date, default: menu_order)
 */

defined( 'ABSPATH' ) || exit;

class MM_Mod_Html_Sitemap extends MM_Mod_Base {

	/** Nothing to add to HTML head. */
	public function populate( array &$data, MM_Page_Context $context, MM_Site_Settings $settings ): void {}

	public function register_hooks(): void {
		if ( $this->settings->get( 'sitemap.html_sitemap.enabled', true ) ) {
			add_shortcode( 'mm_sitemap', [ $this, 'render_shortcode' ] );
		}
	}

	// -------------------------------------------------------------------------
	// Shortcode
	// -------------------------------------------------------------------------

	public function render_shortcode( $atts ): string {
		$cfg = $this->settings->get( 'sitemap.html_sitemap', [] );

		$atts = shortcode_atts( [
			'post_types' => implode( ',', (array) ( $cfg['post_types'] ?? [ 'page', 'post' ] ) ),
			'taxonomies' => implode( ',', (array) ( $cfg['taxonomies'] ?? [] ) ),
			'depth'      => (int) ( $cfg['depth'] ?? 3 ),
			'exclude'    => '',
			'columns'    => (int) ( $cfg['columns'] ?? 1 ),
			'show_date'  => 'no',
			'order_by'   => $cfg['order_by'] ?? 'menu_order',
		], $atts, 'mm_sitemap' );

		$post_types = array_filter( array_map( 'trim', explode( ',', $atts['post_types'] ) ) );
		$taxonomies = array_filter( array_map( 'trim', explode( ',', $atts['taxonomies'] ) ) );
		$exclude    = array_filter( array_map( 'absint', explode( ',', $atts['exclude'] ) ) );
		$global_ex  = array_filter( array_map( 'absint', (array) ( $cfg['exclude_ids'] ?? [] ) ) );
		$exclude    = array_unique( array_merge( $exclude, $global_ex ) );
		$columns    = max( 1, min( 3, (int) $atts['columns'] ) );
		$show_date  = 'yes' === $atts['show_date'];
		$depth      = (int) $atts['depth'];
		$order_by   = in_array( $atts['order_by'], [ 'menu_order', 'title', 'date' ], true ) ? $atts['order_by'] : 'menu_order';

		$out = '<div class="gcm-html-sitemap gcm-html-sitemap--cols-' . $columns . '">';

		foreach ( $post_types as $pt ) {
			$out .= $this->render_post_type( $pt, $depth, $exclude, $show_date, $order_by );
		}

		foreach ( $taxonomies as $taxonomy ) {
			$out .= $this->render_taxonomy( $taxonomy );
		}

		$out .= '</div>';

		return $out;
	}

	// -------------------------------------------------------------------------
	// Post-type section
	// -------------------------------------------------------------------------

	private function render_post_type( string $pt, int $depth, array $exclude, bool $show_date, string $order_by ): string {
		$obj = get_post_type_object( $pt );
		if ( ! $obj ) {
			return '';
		}

		$is_hierarchical = $obj->hierarchical;

		if ( $is_hierarchical ) {
			$html = $this->render_hierarchical( $pt, 0, $depth, $exclude, $show_date, $order_by );
		} else {
			$html = $this->render_flat( $pt, $exclude, $show_date, $order_by );
		}

		if ( ! $html ) {
			return '';
		}

		$label = esc_html( $obj->labels->name );
		$out   = '<section class="gcm-sitemap-section gcm-sitemap-section--' . esc_attr( $pt ) . '">';
		$out  .= '<h2 class="gcm-sitemap-heading">' . $label . '</h2>';
		$out  .= $html;
		$out  .= '</section>';
		return $out;
	}

	private function render_hierarchical( string $pt, int $parent, int $max_depth, array $exclude, bool $show_date, string $order_by, int $current_depth = 0 ): string {
		if ( $max_depth > 0 && $current_depth >= $max_depth ) {
			return '';
		}

		$query_args = [
			'post_type'      => $pt,
			'post_status'    => 'publish',
			'post_parent'    => $parent,
			'posts_per_page' => -1,
			'no_found_rows'  => true,
			'has_password'   => false,
			'post__not_in'   => $exclude ?: [ 0 ],
		];

		$query_args = $this->apply_order( $query_args, $order_by );
		$posts      = get_posts( $query_args );
		$posts      = $this->filter_noindex( $posts );

		if ( ! $posts ) {
			return '';
		}

		$out = '<ul class="gcm-sitemap-list">';
		foreach ( $posts as $post ) {
			$out .= '<li class="gcm-sitemap-item">';
			$out .= '<a href="' . esc_url( get_permalink( $post ) ) . '">' . esc_html( get_the_title( $post ) ) . '</a>';
			if ( $show_date ) {
				$out .= ' <span class="gcm-sitemap-date">(' . esc_html( get_the_date( '', $post ) ) . ')</span>';
			}
			// Recurse into children.
			$children = $this->render_hierarchical( $pt, $post->ID, $max_depth, $exclude, $show_date, $order_by, $current_depth + 1 );
			$out      .= $children;
			$out      .= '</li>';
		}
		$out .= '</ul>';
		return $out;
	}

	private function render_flat( string $pt, array $exclude, bool $show_date, string $order_by ): string {
		$query_args = [
			'post_type'      => $pt,
			'post_status'    => 'publish',
			'posts_per_page' => 500,
			'no_found_rows'  => true,
			'has_password'   => false,
			'post__not_in'   => $exclude ?: [ 0 ],
		];

		$query_args = $this->apply_order( $query_args, $order_by );
		$posts      = get_posts( $query_args );
		$posts      = $this->filter_noindex( $posts );

		if ( ! $posts ) {
			return '';
		}

		$out = '<ul class="gcm-sitemap-list">';
		foreach ( $posts as $post ) {
			$out .= '<li class="gcm-sitemap-item">';
			$out .= '<a href="' . esc_url( get_permalink( $post ) ) . '">' . esc_html( get_the_title( $post ) ) . '</a>';
			if ( $show_date ) {
				$out .= ' <span class="gcm-sitemap-date">(' . esc_html( get_the_date( '', $post ) ) . ')</span>';
			}
			$out .= '</li>';
		}
		$out .= '</ul>';
		return $out;
	}

	// -------------------------------------------------------------------------
	// Taxonomy section
	// -------------------------------------------------------------------------

	private function render_taxonomy( string $taxonomy ): string {
		$obj = get_taxonomy( $taxonomy );
		if ( ! $obj ) {
			return '';
		}

		$terms = get_terms( [
			'taxonomy'   => $taxonomy,
			'hide_empty' => true,
		] );

		if ( is_wp_error( $terms ) || ! $terms ) {
			return '';
		}

		$out  = '<section class="gcm-sitemap-section gcm-sitemap-section--tax-' . esc_attr( $taxonomy ) . '">';
		$out .= '<h2 class="gcm-sitemap-heading">' . esc_html( $obj->labels->name ) . '</h2>';
		$out .= '<ul class="gcm-sitemap-list">';

		foreach ( $terms as $term ) {
			// Skip noindexed terms.
			$meta = $this->settings->get_term_meta( $term->term_id );
			if ( ! empty( $meta['noindex'] ) ) {
				continue;
			}
			$link = get_term_link( $term );
			if ( ! is_string( $link ) ) {
				continue;
			}
			$out .= '<li class="gcm-sitemap-item">';
			$out .= '<a href="' . esc_url( $link ) . '">' . esc_html( $term->name ) . '</a>';
			$out .= ' <span class="gcm-sitemap-count">(' . (int) $term->count . ')</span>';
			$out .= '</li>';
		}

		$out .= '</ul>';
		$out .= '</section>';
		return $out;
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	private function filter_noindex( array $posts ): array {
		return array_filter( $posts, function( $post ) {
			// Exclude posts explicitly set to noindex or exclude_sitemap.
			$meta = $this->settings->get_post_meta( $post->ID );
			if ( ! empty( $meta['noindex'] ) ) {
				return false;
			}
			if ( ! empty( $meta['exclude_sitemap'] ) ) {
				return false;
			}
			// Exclude password-protected.
			if ( post_password_required( $post ) ) {
				return false;
			}
			return true;
		} );
	}

	private function apply_order( array $args, string $order_by ): array {
		switch ( $order_by ) {
			case 'title':
				$args['orderby'] = 'title';
				$args['order']   = 'ASC';
				break;
			case 'date':
				$args['orderby'] = 'date';
				$args['order']   = 'DESC';
				break;
			default: // menu_order
				$args['orderby'] = 'menu_order title';
				$args['order']   = 'ASC';
				break;
		}
		return $args;
	}
}
