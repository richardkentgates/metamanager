<?php
/**
 * MM_Page_Context — resolves what kind of page is being rendered.
 *
 * Called once per request, cached internally.  All modules receive the
 * same context object so page-type conditionals are evaluated once.
 */

defined( 'ABSPATH' ) || exit;

class MM_Page_Context {

	// Resolved on first call to get().
	private ?array $resolved = null;

	// -------------------------------------------------------------------------
	// Primary resolver
	// -------------------------------------------------------------------------

	/** Return the full resolved context array. */
	public function get(): array {
		if ( null === $this->resolved ) {
			$this->resolved = $this->resolve();
		}
		return $this->resolved;
	}

	// Convenience booleans -------------------------------------------------------

	public function is_front_page(): bool    { return (bool) $this->prop( 'is_front_page' ); }
	public function is_home(): bool          { return (bool) $this->prop( 'is_home' ); }
	public function is_singular(): bool      { return (bool) $this->prop( 'is_singular' ); }
	public function is_archive(): bool       { return (bool) $this->prop( 'is_archive' ); }
	public function is_tax(): bool           { return (bool) $this->prop( 'is_tax' ); }
	public function is_category(): bool      { return (bool) $this->prop( 'is_category' ); }
	public function is_tag(): bool           { return (bool) $this->prop( 'is_tag' ); }
	public function is_author(): bool        { return (bool) $this->prop( 'is_author' ); }
	public function is_date(): bool          { return (bool) $this->prop( 'is_date' ); }
	public function is_search(): bool        { return (bool) $this->prop( 'is_search' ); }
	public function is_404(): bool           { return (bool) $this->prop( 'is_404' ); }
	public function is_paged(): bool         { return get_query_var( 'paged' ) > 1 || get_query_var( 'page' ) > 1; }
	public function is_post_type_archive(): bool { return (bool) $this->prop( 'is_post_type_archive' ); }

	/** Returns WP_Post when on a singular page, otherwise null. */
	public function get_post(): ?\WP_Post {
		$post = $this->prop( 'post' );
		return $post instanceof \WP_Post ? $post : null;
	}

	/** Returns WP_Term when on a taxonomy/category/tag archive, otherwise null. */
	public function get_term(): ?\WP_Term {
		$term = $this->prop( 'term' );
		return $term instanceof \WP_Term ? $term : null;
	}

	/** Returns WP_User when on an author archive, otherwise null. */
	public function get_author(): ?\WP_User {
		$author = $this->prop( 'author' );
		return $author instanceof \WP_User ? $author : null;
	}

	/** Returns the current page number (1-based). */
	public function get_page_number(): int {
		$paged = (int) get_query_var( 'paged' );
		$page  = (int) get_query_var( 'page' );
		return max( 1, $paged, $page );
	}

	/** Returns the current post type slug (singular/archive contexts). */
	public function get_post_type(): string {
		return (string) $this->prop( 'post_type', '' );
	}

	/** Returns the queried object (any kind). */
	public function get_queried_object() {
		return get_queried_object();
	}

	// -------------------------------------------------------------------------
	// Internal resolver
	// -------------------------------------------------------------------------

	private function resolve(): array {
		$ctx = [
			'is_front_page'         => is_front_page(),
			'is_home'               => is_home(),
			'is_singular'           => is_singular(),
			'is_archive'            => is_archive(),
			'is_tax'                => is_tax(),
			'is_category'           => is_category(),
			'is_tag'                => is_tag(),
			'is_author'             => is_author(),
			'is_date'               => is_date(),
			'is_search'             => is_search(),
			'is_404'                => is_404(),
			'is_post_type_archive'  => is_post_type_archive(),
			'post'                  => null,
			'term'                  => null,
			'author'                => null,
			'post_type'             => '',
		];

		if ( $ctx['is_singular'] ) {
			$post             = get_queried_object();
			$ctx['post']      = $post instanceof \WP_Post ? $post : null;
			$ctx['post_type'] = $ctx['post'] ? $ctx['post']->post_type : '';
		} elseif ( $ctx['is_category'] || $ctx['is_tag'] || $ctx['is_tax'] ) {
			$term        = get_queried_object();
			$ctx['term'] = $term instanceof \WP_Term ? $term : null;
		} elseif ( $ctx['is_author'] ) {
			$author         = get_queried_object();
			$ctx['author']  = $author instanceof \WP_User ? $author : null;
		} elseif ( $ctx['is_post_type_archive'] ) {
			$ctx['post_type'] = (string) get_query_var( 'post_type' );
		}

		return $ctx;
	}

	private function prop( string $key, $default = false ) {
		$ctx = $this->get();
		return $ctx[ $key ] ?? $default;
	}
}
