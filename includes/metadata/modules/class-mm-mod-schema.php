<?php
/**
 * MM_Mod_Schema — JSON-LD @graph builder.
 *
 * Emits a single <script type="application/ld+json"> block per page.
 * Nodes are added by this module and by MM_Mod_Local and MM_Mod_Author.
 * All nodes share a consistent @id convention: {site_url}#{fragment}.
 */

defined( 'ABSPATH' ) || exit;

class MM_Mod_Schema extends MM_Mod_Base {

	public function populate( array &$data, MM_Page_Context $context, MM_Site_Settings $settings ): void {
		// WebSite node (emitted on every page, referenced by other nodes).
		$this->add_website_node( $data, $settings );

		// WebPage / subtype node.
		$this->add_webpage_node( $data, $context, $settings );

		// BreadcrumbList.
		if ( $settings->get( 'schema.breadcrumbs', true ) ) {
			$this->add_breadcrumb_node( $data, $context, $settings );
		}

		// Content-type-specific nodes.
		if ( $context->is_singular() ) {
			$this->add_content_node( $data, $context, $settings );
		} elseif ( ( $context->is_tax() || $context->is_category() || $context->is_tag() )
			&& $settings->get( 'schema.archive_itemlist', true ) ) {
			$this->add_itemlist_node( $data, $context );
		}

		// Custom JSON-LD appended verbatim (power user escape hatch).
		$custom = trim( $settings->get( 'schema.custom_json_ld', '' ) );
		if ( $custom ) {
			$decoded = json_decode( $custom, true );
			if ( is_array( $decoded ) ) {
				$data['schema'][] = $decoded;
			}
		}
	}

	// -------------------------------------------------------------------------
	// WebSite
	// -------------------------------------------------------------------------

	private function add_website_node( array &$data, MM_Site_Settings $settings ): void {
		$node = [
			'@type'  => 'WebSite',
			'@id'    => $this->site_id( 'website' ),
			'url'    => $this->site_url(),
			'name'   => get_bloginfo( 'name' ),
		];
		if ( get_bloginfo( 'description' ) ) {
			$node['description'] = get_bloginfo( 'description' );
		}

		// SearchAction (Sitelinks search box).
		if ( $settings->get( 'schema.website_searchaction', true ) ) {
			$node['potentialAction'] = [
				'@type'       => 'SearchAction',
				'target'      => [
					'@type'       => 'EntryPoint',
					'urlTemplate' => home_url( '/?s={search_term_string}' ),
				],
				'query-input' => 'required name=search_term_string',
			];
		}

		$this->add_node( $data, $node );

		// SiteNavigationElement: emit when nav menus exist.
		$this->add_navigation_node( $data );
	}

	// -------------------------------------------------------------------------
	// SiteNavigationElement
	// -------------------------------------------------------------------------

	private function add_navigation_node( array &$data ): void {
		$menus = wp_get_nav_menus();
		if ( empty( $menus ) ) {
			return;
		}

		$nav_items = [];
		foreach ( $menus as $menu ) {
			$menu_items = wp_get_nav_menu_items( $menu );
			if ( ! is_array( $menu_items ) ) {
				continue;
			}
			foreach ( $menu_items as $item ) {
				if ( $item->url && $item->title ) {
					$nav_items[] = [
						'@type'    => 'SiteNavigationElement',
						'name'     => $item->title,
						'url'      => $item->url,
						'position' => count( $nav_items ) + 1,
					];
				}
			}
		}

		if ( empty( $nav_items ) ) {
			return;
		}

		$this->add_node( $data, [
			'@type'   => 'SiteNavigationElement',
			'@id'     => $this->site_id( 'navigation' ),
			'name'    => 'Main Navigation',
			'hasPart' => $nav_items,
		] );

		// Link WebSite node to navigation.
		foreach ( $data['schema'] as &$node ) {
			if ( ( $node['@type'] ?? '' ) === 'WebSite' ) {
				$node['hasPart'] = [ '@id' => $this->site_id( 'navigation' ) ];
				break;
			}
		}
	}

	// -------------------------------------------------------------------------
	// WebPage
	// -------------------------------------------------------------------------

	private function add_webpage_node( array &$data, MM_Page_Context $context, MM_Site_Settings $settings ): void {
		$url   = $this->current_url();
		$title = $data['title'] ?? get_the_title();

		$type = 'WebPage';
		if ( $context->is_singular() ) {
			$post = $context->get_post();
			if ( $post ) {
				$meta = $settings->get_post_meta( $post->ID );
				$default_type = $settings->get( "schema.post_type_types.{$post->post_type}", 'WebPage' );
				$type = ! empty( $meta['schema_type'] ) ? $meta['schema_type'] : $default_type;
				// Map content types to their WebPage counterpart.
				if ( in_array( $type, [ 'BlogPosting', 'Article', 'NewsArticle', 'HowTo', 'FAQPage', 'Product', 'Course' ], true ) ) {
					$type = 'WebPage'; // WebPage is the containing page; content type is separate.
				}
			}
		} elseif ( $context->is_front_page() ) {
			$type = 'WebPage';
		} elseif ( $context->is_author() ) {
			$type = 'ProfilePage';
		} elseif ( $context->is_search() ) {
			$type = 'SearchResultsPage';
		}

		$node = [
			'@type'       => $type,
			'@id'         => $url . '#webpage',
			'url'         => $url,
			'name'        => $title,
			'isPartOf'    => [ '@id' => $this->site_id( 'website' ) ],
		];

		// Description from meta.
		foreach ( $data['meta'] as $mt ) {
			if ( ( $mt['name'] ?? '' ) === 'description' ) {
				$node['description'] = $mt['content'];
				break;
			}
		}

		// Breadcrumb reference (added after breadcrumb node is built).
		if ( $settings->get( 'schema.breadcrumbs', true ) ) {
			$node['breadcrumb'] = [ '@id' => $url . '#breadcrumb' ];
		}

		// Date signals (singular posts).
		if ( $context->is_singular() && $context->get_post() ) {
			$post = $context->get_post();
			$node['datePublished'] = get_the_date( 'c', $post );
			$node['dateModified']  = get_the_modified_date( 'c', $post );
		}

		// Primary image reference.
		$og_image = $this->get_og_image( $data );
		if ( $og_image ) {
			$node['primaryImageOfPage'] = [ '@id' => $url . '#primaryimage' ];
			$this->add_node( $data, [
				'@type'  => 'ImageObject',
				'@id'    => $url . '#primaryimage',
				'url'    => $og_image['url'],
				'width'  => $og_image['width'],
				'height' => $og_image['height'],
			] );
		}

		$this->add_node( $data, $node );
	}

	// -------------------------------------------------------------------------
	// BreadcrumbList
	// -------------------------------------------------------------------------

	private function add_breadcrumb_node( array &$data, MM_Page_Context $context, MM_Site_Settings $settings ): void {
		$items = [];
		$pos   = 1;

		// Homepage always first.
		$items[] = $this->crumb( $pos++, get_bloginfo( 'name' ), home_url( '/' ) );

		if ( $context->is_singular() ) {
			$post = $context->get_post();
			if ( $post ) {
				// Parent pages hierarchy.
				$ancestors = array_reverse( get_post_ancestors( $post ) );
				foreach ( $ancestors as $ancestor_id ) {
					$items[] = $this->crumb( $pos++, get_the_title( $ancestor_id ), get_permalink( $ancestor_id ) );
				}
				// Primary category for posts.
				if ( 'post' === $post->post_type ) {
					$cats = get_the_category( $post->ID );
					if ( $cats ) {
						$items[] = $this->crumb( $pos++, $cats[0]->name, get_term_link( $cats[0] ) );
					}
				}
				// The post itself.
				$meta  = $settings->get_post_meta( $post->ID );
				$label = ! empty( $meta['breadcrumb_label'] ) ? $meta['breadcrumb_label'] : get_the_title( $post );
				$items[] = $this->crumb( $pos++, $label, get_permalink( $post ) );
			}
		} elseif ( $context->is_tax() || $context->is_category() || $context->is_tag() ) {
			$term = $context->get_term();
			if ( $term ) {
				// Ancestor terms.
				$ancestors = get_ancestors( $term->term_id, $term->taxonomy, 'taxonomy' );
				foreach ( array_reverse( $ancestors ) as $ancestor_id ) {
					$ancestor = get_term( $ancestor_id, $term->taxonomy );
					if ( $ancestor && ! is_wp_error( $ancestor ) ) {
						$items[] = $this->crumb( $pos++, $ancestor->name, get_term_link( $ancestor ) );
					}
				}
				$items[] = $this->crumb( $pos++, $term->name, get_term_link( $term ) );
			}
		} elseif ( $context->is_author() ) {
			$author  = $context->get_author();
			$items[] = $this->crumb( $pos++, $author ? $author->display_name : 'Author', $author ? get_author_posts_url( $author->ID ) : '' );
		}

		if ( count( $items ) <= 1 ) {
			return; // No breadcrumb for homepage alone.
		}

		$url = $this->current_url();
		$this->add_node( $data, [
			'@type'           => 'BreadcrumbList',
			'@id'             => $url . '#breadcrumb',
			'itemListElement' => $items,
		] );
	}

	// -------------------------------------------------------------------------
	// Content nodes (BlogPosting, Article, HowTo, FAQPage, Product, Course, etc.)
	// -------------------------------------------------------------------------

	private function add_content_node( array &$data, MM_Page_Context $context, MM_Site_Settings $settings ): void {
		$post = $context->get_post();
		if ( ! $post ) {
			return;
		}

		$meta         = $settings->get_post_meta( $post->ID );
		$default_type = $settings->get( "schema.post_type_types.{$post->post_type}", 'WebPage' );
		$type         = ! empty( $meta['schema_type'] ) ? $meta['schema_type'] : $default_type;

		// WebPage itself is already added above; skip if type resolves to WebPage.
		if ( in_array( $type, [ 'WebPage', 'WebSite' ], true ) ) {
			return;
		}

		$url = get_permalink( $post );

		$node = [
			'@type'         => $type,
			'@id'           => $url . '#' . strtolower( $type ),
			'headline'      => get_the_title( $post ),
			'url'           => $url,
			'datePublished' => get_the_date( 'c', $post ),
			'dateModified'  => get_the_modified_date( 'c', $post ),
			'isPartOf'      => [ '@id' => $url . '#webpage' ],
		];

		// Description.
		foreach ( $data['meta'] as $mt ) {
			if ( ( $mt['name'] ?? '' ) === 'description' ) {
				$node['description'] = $mt['content'];
				break;
			}
		}

		// Image.
		$og_image = $this->get_og_image( $data );
		if ( $og_image ) {
			$node['image'] = [ '@id' => $url . '#primaryimage' ];
		}

		// Author — link to Person node (added by Author module or built inline).
		if ( in_array( $type, [ 'BlogPosting', 'Article', 'NewsArticle' ], true ) ) {
			$author = get_userdata( (int) $post->post_author );
			if ( $author && $settings->get( 'schema.author_persons', true ) ) {
				$node['author']    = [ '@id' => get_author_posts_url( $author->ID ) . '#person' ];
				$node['publisher'] = [ '@id' => $this->site_id( 'organization' ) ];
			}
		}

		// FAQPage — extract FAQ blocks from post content using <details>/<summary> elements.
		if ( 'FAQPage' === $type ) {
			$faqs = $this->extract_faq( $post->post_content );
			if ( $faqs ) {
				$node['mainEntity'] = $faqs;
			}
		}

		// Merge per-post schema field overrides (Event dates, prices, addresses, etc.).
		$schema_fields = $meta['schema_fields'] ?? [];
		if ( ! empty( $schema_fields ) ) {
			$additions = MM_Schema_Types::build_node_additions( $schema_fields, $type );
			if ( ! empty( $additions ) ) {
				$node = array_merge( $node, $additions );
			}
		}

		$this->add_node( $data, $node );
	}

	// -------------------------------------------------------------------------
	// ItemList (taxonomy archives)
	// -------------------------------------------------------------------------

	private function add_itemlist_node( array &$data, MM_Page_Context $context ): void {
		$term = $context->get_term();
		if ( ! $term ) {
			return;
		}

		global $wp_query;
		$posts = $wp_query->posts ?? [];
		if ( empty( $posts ) ) {
			return;
		}

		$items = [];
		$pos   = 1;
		foreach ( $posts as $post ) {
			$items[] = [
				'@type'    => 'ListItem',
				'position' => $pos++,
				'url'      => get_permalink( $post ),
				'name'     => get_the_title( $post ),
			];
		}

		$url = get_term_link( $term );
		$this->add_node( $data, [
			'@type'           => 'ItemList',
			'@id'             => ( is_string( $url ) ? $url : '' ) . '#itemlist',
			'name'            => $term->name,
			'itemListElement' => $items,
		] );
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	private function crumb( int $pos, string $name, string $url ): array {
		return [
			'@type'    => 'ListItem',
			'position' => $pos,
			'name'     => $name,
			'item'     => $url,
		];
	}

	private function current_url(): string {
		return home_url( ( isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/' ) );
	}

	private function get_og_image( array $data ): ?array {
		foreach ( $data['meta'] as $mt ) {
			if ( ( $mt['property'] ?? '' ) === 'og:image' && $mt['content'] ) {
				$img = [ 'url' => $mt['content'], 'width' => 0, 'height' => 0 ];
				foreach ( $data['meta'] as $m2 ) {
					if ( ( $m2['property'] ?? '' ) === 'og:image:width' )  { $img['width']  = (int) $m2['content']; }
					if ( ( $m2['property'] ?? '' ) === 'og:image:height' ) { $img['height'] = (int) $m2['content']; }
				}
				return $img;
			}
		}
		return null;
	}

	private function extract_faq( string $content ): array {
		$faqs = [];
		if ( preg_match_all( '/<details[^>]*>\s*<summary[^>]*>(.*?)<\/summary>(.*?)<\/details>/si', $content, $matches ) ) {
			for ( $i = 0; $i < count( $matches[0] ); $i++ ) {
				$faqs[] = [
					'@type'          => 'Question',
					'name'           => wp_strip_all_tags( $matches[1][ $i ] ),
					'acceptedAnswer' => [
						'@type' => 'Answer',
						'text'  => wp_strip_all_tags( $matches[2][ $i ] ),
					],
				];
			}
		}
		return $faqs;
	}
}
