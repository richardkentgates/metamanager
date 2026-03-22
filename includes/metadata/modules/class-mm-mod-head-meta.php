<?php
/**
 * MM_Mod_Head_Meta — title, meta description, canonical, robots directives.
 *
 * Per-page robots attributes (noindex, nofollow, noarchive, nosnippet,
 * noimageindex) flow:
 *   per-post meta override  >  per-type global default  >  built-in fallback
 */

defined( 'ABSPATH' ) || exit;

class MM_Mod_Head_Meta extends MM_Mod_Base {

	public function populate( array &$data, MM_Page_Context $context, MM_Site_Settings $settings ): void {
		$title       = $this->resolve_title( $context, $settings );
		$description = $this->resolve_description( $context, $settings );
		$canonical   = $this->resolve_canonical( $context, $settings );
		$robots      = $this->resolve_robots( $context, $settings );

		// Title stored in $data['title']; also emitted as og:title fallback.
		if ( $title ) {
			$data['title'] = $title;
		}

		// Meta description.
		if ( $description ) {
			$this->add_meta( $data, [ 'name' => 'description', 'content' => $description ] );
		}

		// Canonical link.
		if ( $canonical ) {
			$this->add_link( $data, [ 'rel' => 'canonical', 'href' => $canonical ] );
		}

		// Robots meta — always emit so crawlers have explicit instruction.
		$this->add_meta( $data, [ 'name' => 'robots', 'content' => $robots ] );
	}

	// -------------------------------------------------------------------------
	// Title
	// -------------------------------------------------------------------------

	private function resolve_title( MM_Page_Context $context, MM_Site_Settings $settings ): string {
		$page = $context->get_page_number();

		// 1. Per-post override.
		if ( $context->is_singular() ) {
			$post = $context->get_post();
			if ( $post ) {
				$meta = $settings->get_post_meta( $post->ID );
				if ( ! empty( $meta['title'] ) ) {
					$resolved = $settings->resolve( $meta['title'], $post, null, null, $page );
					return $this->maybe_append_page( $resolved, $page, $settings );
				}
				// Per-type default template.
				$pt  = $post->post_type;
				$tpl = $settings->get( "titles.post_types.{$pt}.single_title", '%%post_title%% %%sep%% %%sitetitle%%' );
				$resolved = $settings->resolve( $tpl, $post, null, null, $page );
				return $this->maybe_append_page( $resolved, $page, $settings );
			}
		}

		// 2. Front page.
		if ( $context->is_front_page() ) {
			$tpl = $settings->get( 'titles.home_title', '%%sitetitle%% %%sep%% %%tagline%%' );
			return $settings->resolve( $tpl );
		}

		// 3. Blog index (posts page).
		if ( $context->is_home() ) {
			$pt  = 'post';
			$tpl = $settings->get( "titles.post_types.{$pt}.archive_title", 'Blog %%sep%% %%sitetitle%%' );
			$resolved = $settings->resolve( $tpl );
			return $this->maybe_append_page( $resolved, $page, $settings );
		}

		// 4. Taxonomy / category / tag archive.
		if ( $context->is_tax() || $context->is_category() || $context->is_tag() ) {
			$term = $context->get_term();
			if ( $term ) {
				$meta = $settings->get_term_meta( $term->term_id );
				if ( ! empty( $meta['title'] ) ) {
					return $settings->resolve( $meta['title'], null, $term );
				}
				$tpl = $settings->get( "titles.taxonomies.{$term->taxonomy}.archive_title", '%%term_title%% %%sep%% %%sitetitle%%' );
				$resolved = $settings->resolve( $tpl, null, $term );
				return $this->maybe_append_page( $resolved, $page, $settings );
			}
		}

		// 5. Author archive.
		if ( $context->is_author() ) {
			$author = $context->get_author();
			if ( $author ) {
				$meta = $settings->get_user_meta( $author->ID );
				if ( ! empty( $meta['title'] ) ) {
					return $settings->resolve( $meta['title'], null, null, $author );
				}
				$tpl = $settings->get( 'authors.title_template', 'Articles by %%author_name%% %%sep%% %%sitetitle%%' );
				$resolved = $settings->resolve( $tpl, null, null, $author );
				return $this->maybe_append_page( $resolved, $page, $settings );
			}
		}

		// 6. Post-type archive.
		if ( $context->is_post_type_archive() ) {
			$pt  = $context->get_post_type();
			$tpl = $settings->get( "titles.post_types.{$pt}.archive_title", '%%post_type_label%% %%sep%% %%sitetitle%%' );
			return $settings->resolve( $tpl );
		}

		// 7. Search.
		if ( $context->is_search() ) {
			$tpl = $settings->get( 'titles.search_title', 'Search Results for %%search_query%% %%sep%% %%sitetitle%%' );
			return $settings->resolve( $tpl );
		}

		// 8. Date archives.
		if ( $context->is_date() ) {
			return $settings->resolve( '%%sitetitle%%' );
		}

		// 9. 404.
		if ( $context->is_404() ) {
			$tpl = $settings->get( 'titles.404_title', 'Page Not Found %%sep%% %%sitetitle%%' );
			return $settings->resolve( $tpl );
		}

		return $settings->resolve( '%%sitetitle%%' );
	}

	// -------------------------------------------------------------------------
	// Meta description
	// -------------------------------------------------------------------------

	private function resolve_description( MM_Page_Context $context, MM_Site_Settings $settings ): string {
		// Per-post override.
		if ( $context->is_singular() ) {
			$post = $context->get_post();
			if ( $post ) {
				$meta = $settings->get_post_meta( $post->ID );
				if ( ! empty( $meta['description'] ) ) {
					return $settings->resolve( $meta['description'], $post );
				}
				// Fallback chain per config: excerpt | content | none.
				$source = $settings->get( "titles.post_types.{$post->post_type}.description_source", 'excerpt' );
				return $this->auto_description( $post, $source );
			}
		}

		if ( $context->is_front_page() ) {
			$desc = $settings->get( 'titles.home_description', '' );
			return $desc ? $settings->resolve( $desc ) : get_bloginfo( 'description' );
		}

		if ( $context->is_home() ) {
			return get_bloginfo( 'description' );
		}

		if ( $context->is_tax() || $context->is_category() || $context->is_tag() ) {
			$term = $context->get_term();
			if ( $term ) {
				$meta = $settings->get_term_meta( $term->term_id );
				if ( ! empty( $meta['description'] ) ) {
					return $settings->resolve( $meta['description'], null, $term );
				}
				$source = $settings->get( "titles.taxonomies.{$term->taxonomy}.description_source", 'term_description' );
				if ( 'term_description' === $source && $term->description ) {
					return wp_strip_all_tags( $term->description );
				}
			}
		}

		if ( $context->is_author() ) {
			$author = $context->get_author();
			if ( $author ) {
				$meta = $settings->get_user_meta( $author->ID );
				if ( ! empty( $meta['description'] ) ) {
					return $settings->resolve( $meta['description'], null, null, $author );
				}
				$tpl = $settings->get( 'authors.description_template', '%%author_bio%%' );
				return $settings->resolve( $tpl, null, null, $author );
			}
		}

		return '';
	}

	// -------------------------------------------------------------------------
	// Canonical
	// -------------------------------------------------------------------------

	private function resolve_canonical( MM_Page_Context $context, MM_Site_Settings $settings ): string {
		// Per-post override.
		if ( $context->is_singular() ) {
			$post = $context->get_post();
			if ( $post ) {
				$meta = $settings->get_post_meta( $post->ID );
				if ( ! empty( $meta['canonical'] ) ) {
					return esc_url_raw( $meta['canonical'] );
				}
				return get_permalink( $post );
			}
		}

		if ( $context->is_front_page() ) {
			return home_url( '/' );
		}

		if ( $context->is_home() ) {
			$page_for_posts = (int) get_option( 'page_for_posts' );
			return $page_for_posts ? get_permalink( $page_for_posts ) : home_url( '/' );
		}

		if ( $context->is_tax() || $context->is_category() || $context->is_tag() ) {
			$term = $context->get_term();
			return $term ? get_term_link( $term ) : '';
		}

		if ( $context->is_author() ) {
			$author = $context->get_author();
			return $author ? get_author_posts_url( $author->ID ) : '';
		}

		if ( $context->is_post_type_archive() ) {
			return get_post_type_archive_link( $context->get_post_type() ) ?: '';
		}

		if ( $context->is_search() ) {
			return get_search_link();
		}

		return '';
	}

	// -------------------------------------------------------------------------
	// Robots directives — the heart of per-page attribute control
	// -------------------------------------------------------------------------

	private function resolve_robots( MM_Page_Context $context, MM_Site_Settings $settings ): string {
		$noindex    = false;
		$nofollow   = false;
		$noarchive  = false;
		$nosnippet  = false;
		$noimageindex = false;

		if ( $context->is_singular() ) {
			$post = $context->get_post();
			if ( $post ) {
				$meta = $settings->get_post_meta( $post->ID );
				$pt   = $post->post_type;

				// noindex: per-post → per-type default.
				$noindex   = $this->resolve_flag( $meta, 'noindex', (bool) $settings->get( "titles.post_types.{$pt}.noindex", false ) );
				$nofollow  = $this->resolve_flag( $meta, 'nofollow', false );
				$noarchive = $this->resolve_flag( $meta, 'noarchive', false );
				$nosnippet = $this->resolve_flag( $meta, 'nosnippet', false );
				$noimageindex = $this->resolve_flag( $meta, 'noimageindex', false );

				// Password-protected posts auto-noindex.
				if ( post_password_required( $post ) ) {
					$noindex = true;
				}
			}
		} elseif ( $context->is_tax() || $context->is_category() || $context->is_tag() ) {
			$term = $context->get_term();
			if ( $term ) {
				$meta    = $settings->get_term_meta( $term->term_id );
				$default = (bool) $settings->get( "titles.taxonomies.{$term->taxonomy}.noindex", false );
				$noindex = $this->resolve_flag( $meta, 'noindex', $default );
			}
		} elseif ( $context->is_author() ) {
			$author  = $context->get_author();
			$default = (bool) $settings->get( 'authors.noindex_default', false );
			if ( $author ) {
				$meta    = $settings->get_user_meta( $author->ID );
				$noindex = $this->resolve_flag( $meta, 'noindex', $default );
			} else {
				$noindex = $default;
			}
		} elseif ( $context->is_date() ) {
			$noindex = (bool) $settings->get( 'titles.date_archive_noindex', true );
		} elseif ( $context->is_search() ) {
			$noindex = (bool) $settings->get( 'titles.search_noindex', true );
		} elseif ( $context->is_404() ) {
			$noindex = true;
		} elseif ( $context->is_home() || $context->is_front_page() ) {
			$pt      = 'post';
			$noindex = (bool) $settings->get( "titles.post_types.{$pt}.noindex_archive", false );
		} elseif ( $context->is_post_type_archive() ) {
			$pt      = $context->get_post_type();
			$noindex = (bool) $settings->get( "titles.post_types.{$pt}.noindex_archive", false );
		}

		return $this->build_robots_string( $noindex, $nofollow, $noarchive, $nosnippet, $noimageindex );
	}

	/**
	 * Resolve a boolean robots flag from postmeta, with a default fallback.
	 * Uses three-state logic: null = "not set" (use default), true/false = explicit override.
	 */
	private function resolve_flag( array $meta, string $key, bool $default ): bool {
		if ( array_key_exists( $key, $meta ) && $meta[ $key ] !== null && $meta[ $key ] !== '' ) {
			return (bool) $meta[ $key ];
		}
		return $default;
	}

	private function build_robots_string( bool $noindex, bool $nofollow, bool $noarchive, bool $nosnippet, bool $noimageindex ): string {
		$parts = [];
		$parts[] = $noindex   ? 'noindex'   : 'index';
		$parts[] = $nofollow  ? 'nofollow'  : 'follow';
		if ( $noarchive )   { $parts[] = 'noarchive'; }
		if ( $nosnippet )   { $parts[] = 'nosnippet'; }
		if ( $noimageindex) { $parts[] = 'noimageindex'; }
		return implode( ', ', $parts );
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	private function auto_description( \WP_Post $post, string $source ): string {
		if ( 'excerpt' === $source || '' === $source ) {
			if ( $post->post_excerpt ) {
				return wp_trim_words( wp_strip_all_tags( $post->post_excerpt ), 30, '' );
			}
			// Fall through to content trim.
		}
		if ( 'content' === $source || ( 'excerpt' === $source && ! $post->post_excerpt ) ) {
			return wp_trim_words( wp_strip_all_tags( strip_shortcodes( $post->post_content ) ), 30, '' );
		}
		return '';
	}

	private function maybe_append_page( string $title, int $page, MM_Site_Settings $settings ): string {
		if ( $page > 1 && $settings->get( 'titles.paginate_append', true ) ) {
			$sep    = $settings->get( 'titles.separator', '|' );
			$title .= ' ' . $sep . ' ' . __( 'Page', 'metamanager' ) . ' ' . $page;
		}
		return $title;
	}
}
