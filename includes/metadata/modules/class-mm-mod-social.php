<?php
/**
 * MM_Mod_Social — Open Graph + Twitter/X card tags.
 *
 * Both tag sets are derived from the same resolved data object so they
 * cannot diverge.  Always emits og:image dimensions when available.
 * Correctly handles: articles, products, author pages, local business homepage.
 */

defined( 'ABSPATH' ) || exit;

class MM_Mod_Social extends MM_Mod_Base {

	public function populate( array &$data, MM_Page_Context $context, MM_Site_Settings $settings ): void {
		if ( ! $settings->get( 'social.og_enabled', true ) ) {
			return;
		}

		$resolved = $this->resolve_social_data( $data, $context, $settings );

		$this->emit_og( $data, $resolved, $context, $settings );

		if ( $settings->get( 'social.twitter_enabled', true ) ) {
			$this->emit_twitter( $data, $resolved, $settings );
		}

		// Pinterest site verification.
		$pinterest_verify = $settings->get( 'social.pinterest_verify', '' );
		if ( $pinterest_verify ) {
			$this->add_meta( $data, [ 'name' => 'p:domain_verify', 'content' => sanitize_text_field( $pinterest_verify ) ] );
		}
	}

	// -------------------------------------------------------------------------
	// Shared data resolver — one call, used by both OG and Twitter.
	// -------------------------------------------------------------------------

	private function resolve_social_data( array &$data, MM_Page_Context $context, MM_Site_Settings $settings ): array {
		// Start from the already-resolved title/description in $data.
		$title       = $data['title'] ?? '';
		$description = '';
		$og_type     = 'website';
		$url         = home_url( add_query_arg( [] ) );
		$image       = [ 'url' => '', 'width' => 0, 'height' => 0 ];
		$article     = [];
		$author_user = null;

		foreach ( $data['meta'] as $mt ) {
			if ( ( $mt['name'] ?? '' ) === 'description' ) {
				$description = $mt['content'];
			}
		}

		// Per-entity overrides and OG type resolution.
		if ( $context->is_singular() ) {
			$post = $context->get_post();
			if ( $post ) {
				$meta        = $settings->get_post_meta( $post->ID );
				$title       = ! empty( $meta['og_title'] ) ? $meta['og_title'] : $title;
				$description = ! empty( $meta['og_description'] ) ? $meta['og_description'] : $description;
				$url         = get_permalink( $post );

				// Image: custom meta → featured image → site default.
				if ( ! empty( $meta['og_image_id'] ) ) {
					$image = $this->image_data( (int) $meta['og_image_id'] );
				} elseif ( ! empty( $meta['og_image_url'] ) ) {
					$image = $this->image_data( 0, $meta['og_image_url'] );
				} elseif ( has_post_thumbnail( $post ) ) {
					$image = $this->image_data( get_post_thumbnail_id( $post ) );
				} else {
					$image = $this->default_image( $settings );
				}

				// Article type for blog posts.
				if ( 'post' === $post->post_type || in_array( $post->post_type, (array) get_post_types( [ 'public' => true ] ), true ) ) {
					$og_type = 'article';
					$author_user = get_userdata( (int) $post->post_author );

					$cats = get_the_category( $post->ID );
					$tags = get_the_tags( $post->ID );

					$article = [
						'published_time' => get_the_date( 'c', $post ),
						'modified_time'  => get_the_modified_date( 'c', $post ),
						'author'         => $author_user ? get_author_posts_url( $author_user->ID ) : '',
						'section'        => $cats ? $cats[0]->name : '',
						'tags'           => $tags ? wp_list_pluck( $tags, 'name' ) : [],
					];
				}

				// WooCommerce product → og:type product.
				if ( 'product' === $post->post_type ) {
					$og_type = 'product';
				}
			}
		} elseif ( $context->is_front_page() || $context->is_home() ) {
			$url   = home_url( '/' );
			$image = $this->default_image( $settings );
			// Local business OG type — handled in Local module which merges additional properties.
			$og_type = 'website';
		} elseif ( $context->is_tax() || $context->is_category() || $context->is_tag() ) {
			$term = $context->get_term();
			if ( $term ) {
				$meta        = $settings->get_term_meta( $term->term_id );
				$title       = ! empty( $meta['og_title'] ) ? $meta['og_title'] : $title;
				$description = ! empty( $meta['og_description'] ) ? $meta['og_description'] : $description;
				$url         = get_term_link( $term );
				$image       = ! empty( $meta['og_image_id'] )
					? $this->image_data( (int) $meta['og_image_id'] )
					: $this->default_image( $settings );
			}
		} elseif ( $context->is_author() ) {
			$author_user = $context->get_author();
			if ( $author_user ) {
				$url   = get_author_posts_url( $author_user->ID );
				$image = $this->default_image( $settings );
			}
		} else {
			$image = $this->default_image( $settings );
		}

		return compact( 'title', 'description', 'og_type', 'url', 'image', 'article', 'author_user' );
	}

	// -------------------------------------------------------------------------
	// Open Graph emission
	// -------------------------------------------------------------------------

	private function emit_og( array &$data, array $r, MM_Page_Context $context, MM_Site_Settings $settings ): void {
		$this->add_meta( $data, [ 'property' => 'og:type',        'content' => $r['og_type'] ] );
		$this->add_meta( $data, [ 'property' => 'og:title',       'content' => $r['title'] ] );
		$this->add_meta( $data, [ 'property' => 'og:url',         'content' => $r['url'] ] );
		$this->add_meta( $data, [ 'property' => 'og:locale',      'content' => $settings->get( 'social.og_locale', 'en_US' ) ] );
		$this->add_meta( $data, [ 'property' => 'og:site_name',   'content' => get_bloginfo( 'name' ) ] );

		if ( $r['description'] ) {
			$this->add_meta( $data, [ 'property' => 'og:description', 'content' => $r['description'] ] );
		}

		// Image — always include dimensions when available.
		if ( $r['image']['url'] ) {
			$this->add_meta( $data, [ 'property' => 'og:image',        'content' => $r['image']['url'] ] );
			if ( $r['image']['width'] ) {
				$this->add_meta( $data, [ 'property' => 'og:image:width',  'content' => (string) $r['image']['width'] ] );
			}
			if ( $r['image']['height'] ) {
				$this->add_meta( $data, [ 'property' => 'og:image:height', 'content' => (string) $r['image']['height'] ] );
			}
			$this->add_meta( $data, [ 'property' => 'og:image:type',   'content' => $this->mime_from_url( $r['image']['url'] ) ] );
		}

		// Article tags.
		if ( 'article' === $r['og_type'] && $r['article'] ) {
			$a = $r['article'];
			if ( $a['published_time'] ) {
				$this->add_meta( $data, [ 'property' => 'article:published_time', 'content' => $a['published_time'] ] );
			}
			if ( $a['modified_time'] ) {
				$this->add_meta( $data, [ 'property' => 'article:modified_time',  'content' => $a['modified_time'] ] );
			}
			if ( $a['author'] ) {
				$this->add_meta( $data, [ 'property' => 'article:author',          'content' => $a['author'] ] );
			}
			if ( $a['section'] ) {
				$this->add_meta( $data, [ 'property' => 'article:section',         'content' => $a['section'] ] );
			}
			foreach ( $a['tags'] as $tag ) {
				$data['meta'][] = [ 'property' => 'article:tag', 'content' => $tag ];
			}
		}

		// Facebook app ID.
		$fb_app_id = $settings->get( 'social.fb_app_id', '' );
		if ( $fb_app_id ) {
			$this->add_meta( $data, [ 'property' => 'fb:app_id', 'content' => $fb_app_id ] );
		}
	}

	// -------------------------------------------------------------------------
	// Twitter / X card emission
	// -------------------------------------------------------------------------

	private function emit_twitter( array &$data, array $r, MM_Site_Settings $settings ): void {
		$card = $r['image']['url']
			? $settings->get( 'social.twitter_card_type', 'summary_large_image' )
			: 'summary';

		$this->add_meta( $data, [ 'name' => 'twitter:card',  'content' => $card ] );
		$this->add_meta( $data, [ 'name' => 'twitter:title', 'content' => $r['title'] ] );

		if ( $r['description'] ) {
			$this->add_meta( $data, [ 'name' => 'twitter:description', 'content' => $r['description'] ] );
		}

		if ( $r['image']['url'] ) {
			$this->add_meta( $data, [ 'name' => 'twitter:image', 'content' => $r['image']['url'] ] );
		}

		$site_handle = $settings->get( 'social.twitter_site', '' );
		if ( $site_handle ) {
			$handle = '@' . ltrim( $site_handle, '@' );
			$this->add_meta( $data, [ 'name' => 'twitter:site', 'content' => $handle ] );
		}

		// Per-author twitter handle on articles.
		if ( $r['author_user'] ) {
			$author_meta = $settings->get_user_meta( $r['author_user']->ID );
			$twitter = $author_meta['social']['twitter'] ?? '';
			if ( $twitter ) {
				$handle = '@' . ltrim( $twitter, '@' );
				$this->add_meta( $data, [ 'name' => 'twitter:creator', 'content' => $handle ] );
			}
		}
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	private function default_image( MM_Site_Settings $settings ): array {
		$id  = (int) $settings->get( 'social.og_default_image_id', 0 );
		$url = $settings->get( 'social.og_default_image', '' );
		if ( $id ) {
			return $this->image_data( $id );
		}
		if ( $url ) {
			return $this->image_data( 0, $url );
		}
		return [ 'url' => '', 'width' => 0, 'height' => 0 ];
	}

	private function mime_from_url( string $url ): string {
		$ext = strtolower( pathinfo( wp_parse_url( $url, PHP_URL_PATH ), PATHINFO_EXTENSION ) );
		$map = [ 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp' ];
		return $map[ $ext ] ?? 'image/jpeg';
	}
}
