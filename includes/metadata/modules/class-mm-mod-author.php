<?php
/**
 * MM_Mod_Author — author archive SEO + Person schema nodes.
 *
 * Adds Person nodes on author archive pages.  On BlogPosting nodes
 * built by the Schema module, the author Person @id is referenced
 * by URL so this module can be loaded in any order.
 */

defined( 'ABSPATH' ) || exit;

class MM_Mod_Author extends MM_Mod_Base {

	public function populate( array &$data, MM_Page_Context $context, MM_Site_Settings $settings ): void {
		if ( ! $settings->get( 'authors.enabled', true ) ) {
			return;
		}

		// Build Person node on author archive pages.
		if ( $context->is_author() ) {
			$author = $context->get_author();
			if ( $author && $settings->get( 'schema.author_persons', true ) ) {
				$this->add_node( $data, $this->build_person_node( $author, $settings ) );
			}
		}

		// Build Person node for singular posts (author of post, not archive visitor).
		if ( $context->is_singular() && $settings->get( 'schema.author_persons', true ) ) {
			$post = $context->get_post();
			if ( $post ) {
				$author = get_userdata( (int) $post->post_author );
				if ( $author ) {
					$this->add_node( $data, $this->build_person_node( $author, $settings ) );
				}
			}
		}
	}

	private function build_person_node( \WP_User $author, MM_Site_Settings $settings ): array {
		$meta        = $settings->get_user_meta( $author->ID );
		$author_url  = get_author_posts_url( $author->ID );

		$node = [
			'@type'       => 'Person',
			'@id'         => $author_url . '#person',
			'name'        => $author->display_name,
			'url'         => $author_url,
		];

		if ( $author->description ) {
			$node['description'] = $author->description;
		}

		// Avatar / profile picture.
		$avatar_url = get_avatar_url( $author->ID, [ 'size' => 256 ] );
		if ( $avatar_url ) {
			$node['image'] = [
				'@type' => 'ImageObject',
				'url'   => $avatar_url,
			];
		}

		// Social profiles — from user meta social fields.
		$social   = $meta['social'] ?? [];
		$same_as  = [];
		$profiles = [
			'twitter'   => 'https://twitter.com/',
			'linkedin'  => '',
			'instagram' => 'https://www.instagram.com/',
			'bluesky'   => 'https://bsky.app/profile/',
			'website'   => '',
		];
		foreach ( $profiles as $key => $prefix ) {
			$value = trim( $social[ $key ] ?? '' );
			if ( $value ) {
				if ( filter_var( $value, FILTER_VALIDATE_URL ) ) {
					$same_as[] = $value;
				} elseif ( $prefix ) {
					$same_as[] = $prefix . ltrim( $value, '@/' );
				}
			}
		}
		if ( $same_as ) {
			$node['sameAs'] = $same_as;
		}

		// Link author to organisation.
		$node['worksFor'] = [ '@id' => $this->site_id( 'organization' ) ];

		return $node;
	}
}
