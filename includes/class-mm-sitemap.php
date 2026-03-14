<?php
/**
 * MM_Sitemap — XML media sitemap generator.
 *
 * Serves two dedicated sitemap endpoints:
 *
 *   /sitemap-video.xml  — public posts that embed YouTube/Vimeo/self-hosted video,
 *                         plus video attachment pages, rendered as a Google Video
 *                         Sitemap with full spec support including Metamanager-sourced
 *                         duration, keywords, rating, publication date, and uploader.
 *
 *   /sitemap-media.xml  — all media attachment pages (image, video, audio, PDF) with
 *                         image extension nodes (<image:image>) for images and video
 *                         extension nodes (<video:video>) for video attachments.
 *
 * Settings are exposed as a new "Sitemaps" section on the existing
 * Media → MM Settings admin page.
 *
 * Ported from gcm-seo-core (commit ed2021e, removed 2026-03-14) with enhancements
 * made possible by Metamanager's per-attachment metadata storage.
 *
 * @package Metamanager
 * @since   1.6.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class MM_Sitemap
 */
class MM_Sitemap {

	// -----------------------------------------------------------------------
	// XML namespace constants
	// -----------------------------------------------------------------------

	const NS_SITEMAP = 'http://www.sitemaps.org/schemas/sitemap/0.9';
	const NS_IMAGE   = 'http://www.google.com/schemas/sitemap-image/1.1';
	const NS_VIDEO   = 'http://www.google.com/schemas/sitemap-video/0.9';

	// -----------------------------------------------------------------------
	// Settings option keys
	// -----------------------------------------------------------------------

	/** bool — include image extension nodes in sitemap-media.xml (default true). */
	const OPT_IMAGES            = 'mm_sitemap_images';
	/** bool — serve /sitemap-video.xml at all (default true). */
	const OPT_VIDEO             = 'mm_sitemap_video';
	/** bool — extract YouTube embeds for /sitemap-video.xml (default true). */
	const OPT_VIDEO_YOUTUBE     = 'mm_sitemap_video_youtube';
	/** bool — extract Vimeo embeds for /sitemap-video.xml (default true). */
	const OPT_VIDEO_VIMEO       = 'mm_sitemap_video_vimeo';
	/** bool — extract self-hosted <video> tags for /sitemap-video.xml (default true). */
	const OPT_VIDEO_SELFHOSTED  = 'mm_sitemap_video_selfhosted';
	/** bool — serve /sitemap-media.xml at all (default true). */
	const OPT_MEDIA             = 'mm_sitemap_media';

	// -----------------------------------------------------------------------
	// Boot
	// -----------------------------------------------------------------------

	/**
	 * Register hooks. Called from the main plugin file on 'plugins_loaded'.
	 */
	public static function init(): void {
		add_action( 'init',                                    [ __CLASS__, 'add_rewrite_rules' ] );
		add_filter( 'query_vars',                              [ __CLASS__, 'register_query_vars' ] );
		add_action( 'template_redirect',                       [ __CLASS__, 'maybe_serve_sitemap' ] );
		add_action( 'admin_init',                              [ __CLASS__, 'register_settings' ] );
		add_action( 'load-media_page_metamanager-settings',    [ __CLASS__, 'add_settings_help_tab' ] );
	}

	// -----------------------------------------------------------------------
	// Rewrite rules
	// -----------------------------------------------------------------------

	/**
	 * Register custom rewrite rules so WordPress recognises the sitemap URLs.
	 * Must be followed by flush_rewrite_rules() (called on plugin activation).
	 */
	public static function add_rewrite_rules(): void {
		add_rewrite_rule( '^sitemap-video\.xml$', 'index.php?mm_sitemap=video', 'top' );
		add_rewrite_rule( '^sitemap-media\.xml$', 'index.php?mm_sitemap=media', 'top' );
	}

	/**
	 * Expose the mm_sitemap query variable to WordPress.
	 *
	 * @param  string[] $vars  Existing registered query variables.
	 * @return string[]        Updated list.
	 */
	public static function register_query_vars( array $vars ): array {
		$vars[] = 'mm_sitemap';
		return $vars;
	}

	// -----------------------------------------------------------------------
	// Dispatcher
	// -----------------------------------------------------------------------

	/**
	 * Intercept sitemap requests and serve the XML response.
	 * Calls exit() after output to prevent the WordPress theme from rendering.
	 */
	public static function maybe_serve_sitemap(): void {
		$type = (string) get_query_var( 'mm_sitemap', '' );
		if ( '' === $type ) {
			return;
		}

		nocache_headers();
		header( 'Content-Type: application/xml; charset=UTF-8' );

		switch ( $type ) {
			case 'video':
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				echo get_option( self::OPT_VIDEO, true )
					? self::render_video_sitemap()
					: self::render_empty_urlset( self::NS_VIDEO, 'video' );
				break;

			case 'media':
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				echo get_option( self::OPT_MEDIA, true )
					? self::render_media_sitemap()
					: self::render_empty_urlset( '', '' );
				break;
		}

		exit;
	}

	// -----------------------------------------------------------------------
	// /sitemap-video.xml
	// -----------------------------------------------------------------------

	/**
	 * Build the complete video sitemap XML string.
	 *
	 * Sources:
	 *   1. Published posts whose content contains YouTube/Vimeo embeds or
	 *      self-hosted <video> tags (toggled by individual settings).
	 *   2. Video attachment pages — enriched with Metamanager metadata.
	 *
	 * @return string  Complete XML document.
	 */
	private static function render_video_sitemap(): string {
		$yt_enabled   = (bool) get_option( self::OPT_VIDEO_YOUTUBE, true );
		$vimeo_enabled = (bool) get_option( self::OPT_VIDEO_VIMEO, true );
		$self_enabled  = (bool) get_option( self::OPT_VIDEO_SELFHOSTED, true );

		$entries = []; // keyed by page permalink to deduplicate

		// --- 1. Embedded video in published posts ---
		if ( $yt_enabled || $vimeo_enabled || $self_enabled ) {
			$posts = get_posts( [
				'post_type'      => 'post',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'all',
			] );

			foreach ( $posts as $post ) {
				$permalink = get_permalink( $post );
				if ( ! $permalink ) {
					continue;
				}

				$videos = [];

				if ( $yt_enabled || $vimeo_enabled ) {
					$videos = array_merge( $videos, self::extract_embed_videos( $post, $yt_enabled, $vimeo_enabled ) );
				}

				if ( $self_enabled ) {
					$videos = array_merge( $videos, self::extract_selfhosted_videos( $post ) );
				}

				if ( $videos ) {
					$entries[ $permalink ] = $videos;
				}
			}
		}

		// --- 2. Video attachment pages ---
		$attachments = get_posts( [
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'posts_per_page' => -1,
			'post_mime_type' => 'video',
			'fields'         => 'all',
		] );

		foreach ( $attachments as $att ) {
			$permalink = get_attachment_link( $att->ID );
			if ( ! $permalink ) {
				continue;
			}
			$url = wp_get_attachment_url( $att->ID );
			if ( ! $url ) {
				continue;
			}
			$entries[ $permalink ] = [ self::build_attachment_video_record( $att->ID, $att, $url ) ];
		}

		if ( ! $entries ) {
			return self::render_empty_urlset( self::NS_VIDEO, 'video' );
		}

		$xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
		$xml .= '<urlset xmlns="' . self::NS_SITEMAP . '" xmlns:video="' . self::NS_VIDEO . "\">\n";

		foreach ( $entries as $loc => $videos ) {
			$xml .= "\t<url>\n";
			$xml .= "\t\t<loc>" . esc_xml( $loc ) . "</loc>\n";
			foreach ( $videos as $video ) {
				$xml .= self::render_video_node( $video );
			}
			$xml .= "\t</url>\n";
		}

		$xml .= '</urlset>';
		return $xml;
	}

	// -----------------------------------------------------------------------
	// Video extraction helpers
	// -----------------------------------------------------------------------

	/**
	 * Extract YouTube and/or Vimeo embedded video records from a post.
	 *
	 * @param  WP_Post $post           The post to scan.
	 * @param  bool    $yt_enabled     Whether to look for YouTube embeds.
	 * @param  bool    $vimeo_enabled  Whether to look for Vimeo embeds.
	 * @return array[]                 Video record arrays.
	 */
	private static function extract_embed_videos( WP_Post $post, bool $yt_enabled, bool $vimeo_enabled ): array {
		$content = $post->post_content;
		$records = [];

		if ( $yt_enabled ) {
			preg_match_all(
				'/(?:youtube\.com\/(?:watch\?v=|embed\/|shorts\/)|youtu\.be\/)([a-zA-Z0-9_-]{11})/',
				$content,
				$matches
			);
			foreach ( array_unique( $matches[1] ) as $id ) {
				$watch_url = 'https://www.youtube.com/watch?v=' . $id;
				$oembed    = self::get_cached_oembed( $watch_url );
				$records[] = [
					'thumbnail'   => $oembed['thumbnail_url'] ?? 'https://img.youtube.com/vi/' . $id . '/hqdefault.jpg',
					'title'       => $oembed['title'] ?? get_the_title( $post ),
					'description' => wp_strip_all_tags( $post->post_excerpt ?: wp_trim_words( $post->post_content, 20, '' ) ),
					'player_loc'  => 'https://www.youtube.com/embed/' . $id,
					'content_loc' => '',
					'duration'    => isset( $oembed['duration'] ) ? (int) $oembed['duration'] : null,
					'pub_date'    => null,
					'rating'      => null,
					'tags'        => [],
					'uploader'    => null,
					'uploader_url'=> null,
				];
			}
		}

		if ( $vimeo_enabled ) {
			preg_match_all(
				'/vimeo\.com\/(?:video\/)?(\d+)/',
				$content,
				$matches
			);
			foreach ( array_unique( $matches[1] ) as $id ) {
				$vimeo_url = 'https://vimeo.com/' . $id;
				$oembed    = self::get_cached_oembed( $vimeo_url );
				$records[] = [
					'thumbnail'   => $oembed['thumbnail_url'] ?? '',
					'title'       => $oembed['title'] ?? get_the_title( $post ),
					'description' => wp_strip_all_tags( $post->post_excerpt ?: wp_trim_words( $post->post_content, 20, '' ) ),
					'player_loc'  => 'https://player.vimeo.com/video/' . $id,
					'content_loc' => '',
					'duration'    => isset( $oembed['duration'] ) ? (int) $oembed['duration'] : null,
					'pub_date'    => null,
					'rating'      => null,
					'tags'        => [],
					'uploader'    => null,
					'uploader_url'=> null,
				];
			}
		}

		return $records;
	}

	/**
	 * Extract self-hosted video records from a post's content.
	 * Only local URLs (same origin) are included.
	 *
	 * @param  WP_Post $post  The post to scan.
	 * @return array[]        Video record arrays.
	 */
	private static function extract_selfhosted_videos( WP_Post $post ): array {
		preg_match_all(
			'/<video[^>]*>.*?<source[^>]+src=["\']([^"\']+\.(?:mp4|webm|ogg))["\'][^>]*/is',
			$post->post_content,
			$matches
		);

		$records = [];
		foreach ( array_unique( $matches[1] ) as $src ) {
			if ( ! self::is_local_url( $src ) ) {
				continue;
			}
			$records[] = [
				'thumbnail'   => '',
				'title'       => get_the_title( $post ),
				'description' => wp_strip_all_tags( $post->post_excerpt ),
				'player_loc'  => '',
				'content_loc' => $src,
				'duration'    => null,
				'pub_date'    => null,
				'rating'      => null,
				'tags'        => [],
				'uploader'    => null,
				'uploader_url'=> null,
			];
		}

		return $records;
	}

	/**
	 * Build a fully-enriched video record for a video attachment, using all
	 * available Metamanager metadata.
	 *
	 * @param  int      $id   Attachment post ID.
	 * @param  WP_Post  $att  Attachment post object.
	 * @param  string   $url  Direct file URL.
	 * @return array          Video record array.
	 */
	private static function build_attachment_video_record( int $id, WP_Post $att, string $url ): array {
		$meta = static fn( string $key ): string => (string) get_post_meta( $id, $key, true );

		$thumbnail = '';
		$thumb_id  = (int) get_post_thumbnail_id( $id );
		if ( $thumb_id ) {
			$thumb_src = wp_get_attachment_image_src( $thumb_id, 'medium' );
			if ( $thumb_src ) {
				$thumbnail = $thumb_src[0];
			}
		}

		$title       = $meta( MM_Metadata::META_HEADLINE ) ?: trim( $att->post_title ) ?: basename( $url );
		$description = wp_strip_all_tags( $att->post_excerpt ?: '' );

		$record = [
			'thumbnail'   => $thumbnail,
			'title'       => $title,
			'description' => $description,
			'player_loc'  => '',
			'content_loc' => $url,
			'duration'    => null,
			'pub_date'    => null,
			'rating'      => null,
			'tags'        => [],
			'uploader'    => null,
			'uploader_url'=> null,
		];

		// Duration from ffprobe (integer seconds, stored by the meta daemon).
		$duration = $meta( MM_Metadata::META_DURATION );
		if ( '' !== $duration && (int) $duration > 0 ) {
			$record['duration'] = (int) $duration;
		}

		// Keywords → <video:tag> elements (Google spec: max 32 per video).
		$keywords = $meta( MM_Metadata::META_KEYWORDS );
		if ( '' !== $keywords ) {
			$kw = array_values( array_filter( array_map( 'trim', explode( ';', $keywords ) ) ) );
			if ( $kw ) {
				$record['tags'] = array_slice( $kw, 0, 32 );
			}
		}

		// Rating (0–5 stars stored as integer, spec accepts 0.0–5.0 float).
		$rating = $meta( MM_Metadata::META_RATING );
		if ( '' !== $rating ) {
			$record['rating'] = round( (float) $rating, 1 );
		}

		// Publication date (ISO 8601).
		$date_created = $meta( MM_Metadata::META_DATE );
		if ( '' !== $date_created ) {
			$record['pub_date'] = $date_created . 'T00:00:00+00:00';
		}

		// Uploader (mm_creator) with author profile as info URL.
		$creator = $meta( MM_Metadata::META_CREATOR );
		if ( '' !== $creator ) {
			$record['uploader']      = $creator;
			$record['uploader_url']  = get_author_posts_url( (int) $att->post_author );
		}

		return $record;
	}

	/**
	 * Fetch oEmbed data for a remote video URL, caching the result for one week.
	 *
	 * @param  string $url  The canonical video URL (watch page or profile page).
	 * @return array        oEmbed response fields, or an empty array on failure.
	 */
	private static function get_cached_oembed( string $url ): array {
		$cache_key = 'mm_oembed_' . md5( $url );
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			return (array) $cached;
		}

		$oembed = _wp_oembed_get_object();
		$data   = $oembed->get_data( $url, [] );
		$result = is_object( $data ) ? (array) $data : [];

		if ( $result ) {
			set_transient( $cache_key, $result, WEEK_IN_SECONDS );
		}

		return $result;
	}

	/**
	 * Return true if the URL belongs to this site (same origin or a relative path).
	 *
	 * @param  string $url  URL to check.
	 * @return bool
	 */
	private static function is_local_url( string $url ): bool {
		return str_starts_with( $url, home_url() )
			|| ( ! str_starts_with( $url, 'http' ) && ! str_starts_with( $url, '//' ) );
	}

	// -----------------------------------------------------------------------
	// /sitemap-media.xml
	// -----------------------------------------------------------------------

	/**
	 * Build the attachment-pages sitemap XML string.
	 *
	 * Lists all attachment pages for supported media types
	 * (image/*, video/*, audio/*, application/pdf) with
	 * <image:image> or <video:video> extension nodes where applicable.
	 *
	 * @return string  Complete XML document.
	 */
	private static function render_media_sitemap(): string {
		$mime_types = array_merge(
			[ 'image' ], // WordPress shorthand expands to all image/* MIME types.
			MM_Metadata::VIDEO_MIME_TYPES,
			MM_Metadata::AUDIO_MIME_TYPES,
			MM_Metadata::PDF_MIME_TYPES
		);

		$attachments = get_posts( [
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'posts_per_page' => -1,
			'post_mime_type' => implode( ',', $mime_types ),
			'fields'         => 'all',
		] );

		if ( ! $attachments ) {
			return self::render_empty_urlset( '', '' );
		}

		// Determine which namespaces are actually needed.
		$include_images = (bool) get_option( self::OPT_IMAGES, true );
		$has_images     = false;
		$has_videos     = false;
		foreach ( $attachments as $att ) {
			$mime = (string) get_post_mime_type( $att->ID );
			if ( $include_images && wp_attachment_is_image( $att->ID ) ) {
				$has_images = true;
			}
			if ( MM_Metadata::is_video_mime( $mime ) ) {
				$has_videos = true;
			}
			if ( $has_images && $has_videos ) {
				break;
			}
		}

		$xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
		$xml .= '<urlset xmlns="' . self::NS_SITEMAP . '"';
		if ( $has_images ) {
			$xml .= ' xmlns:image="' . self::NS_IMAGE . '"';
		}
		if ( $has_videos ) {
			$xml .= ' xmlns:video="' . self::NS_VIDEO . '"';
		}
		$xml .= ">\n";

		foreach ( $attachments as $att ) {
			$permalink = get_attachment_link( $att->ID );
			if ( ! $permalink ) {
				continue;
			}
			$mime = (string) get_post_mime_type( $att->ID );

			$xml .= "\t<url>\n";
			$xml .= "\t\t<loc>" . esc_xml( $permalink ) . "</loc>\n";

			if ( $include_images && $has_images && wp_attachment_is_image( $att->ID ) ) {
				$xml .= self::render_image_node( $att->ID, $att );
			} elseif ( $has_videos && MM_Metadata::is_video_mime( $mime ) ) {
				$url = wp_get_attachment_url( $att->ID );
				if ( $url ) {
					$xml .= self::render_video_node(
						self::build_attachment_video_record( $att->ID, $att, $url )
					);
				}
			}

			$xml .= "\t</url>\n";
		}

		$xml .= '</urlset>';
		return $xml;
	}

	// -----------------------------------------------------------------------
	// XML node renderers
	// -----------------------------------------------------------------------

	/**
	 * Render a <image:image> extension node for a single image attachment.
	 *
	 * Populated fields:
	 *   image:loc           — Full-size attachment URL.
	 *   image:title         — mm_headline, falling back to post_title.
	 *   image:caption       — post_excerpt (the WordPress caption field).
	 *   image:license       — mm_copyright when it starts with "http".
	 *   image:geo_location  — GPS "City, Country" label, or decimal coordinates.
	 *
	 * @param  int      $id    Attachment post ID.
	 * @param  WP_Post  $post  Attachment post object.
	 * @return string          XML fragment.
	 */
	private static function render_image_node( int $id, WP_Post $post ): string {
		$src = wp_get_attachment_image_src( $id, 'full' );
		if ( ! $src ) {
			return '';
		}
		[ $url ] = $src;

		$meta = static fn( string $key ): string => (string) get_post_meta( $id, $key, true );

		$xml = "\t\t<image:image>\n";
		$xml .= "\t\t\t<image:loc>" . esc_xml( $url ) . "</image:loc>\n";

		// Title: mm_headline preferred; post_title as fallback.
		$headline = $meta( MM_Metadata::META_HEADLINE );
		$title    = '' !== $headline ? $headline : trim( $post->post_title );
		if ( '' !== $title ) {
			$xml .= "\t\t\t<image:title>" . esc_xml( $title ) . "</image:title>\n";
		}

		// Caption.
		$caption = trim( $post->post_excerpt );
		if ( '' !== $caption ) {
			$xml .= "\t\t\t<image:caption>" . esc_xml( $caption ) . "</image:caption>\n";
		}

		// License: only when mm_copyright is a URL.
		$copyright = $meta( MM_Metadata::META_COPYRIGHT );
		if ( str_starts_with( $copyright, 'http' ) ) {
			$xml .= "\t\t\t<image:license>" . esc_xml( $copyright ) . "</image:license>\n";
		}

		// Geo location: build a human-readable label from GPS or IPTC city/country.
		$lat = $meta( MM_Metadata::META_GPS_LAT );
		$lon = $meta( MM_Metadata::META_GPS_LON );
		if ( '' !== $lat && '' !== $lon ) {
			$city    = $meta( MM_Metadata::META_CITY );
			$country = $meta( MM_Metadata::META_COUNTRY );
			if ( '' !== $city && '' !== $country ) {
				$geo_label = "{$city}, {$country}";
			} elseif ( '' !== $city ) {
				$geo_label = $city;
			} elseif ( '' !== $country ) {
				$geo_label = $country;
			} else {
				$geo_label = round( (float) $lat, 4 ) . ',' . round( (float) $lon, 4 );
			}
			$xml .= "\t\t\t<image:geo_location>" . esc_xml( $geo_label ) . "</image:geo_location>\n";
		}

		$xml .= "\t\t</image:image>\n";
		return $xml;
	}

	/**
	 * Render a <video:video> extension node from a normalised video record array.
	 *
	 * Expected array keys: thumbnail, title, description, player_loc,
	 * content_loc, duration, pub_date, rating, tags (array), uploader,
	 * uploader_url. All keys are optional; missing/null values are omitted.
	 *
	 * Per Google's spec, player_loc and content_loc are mutually exclusive;
	 * player_loc takes precedence when both are set.
	 *
	 * @param  array $v  Video record.
	 * @return string    XML fragment.
	 */
	private static function render_video_node( array $v ): string {
		$xml = "\t\t<video:video>\n";

		if ( ! empty( $v['thumbnail'] ) ) {
			$xml .= "\t\t\t<video:thumbnail_loc>" . esc_xml( $v['thumbnail'] ) . "</video:thumbnail_loc>\n";
		}

		$xml .= "\t\t\t<video:title>" . esc_xml( $v['title'] ?? '' ) . "</video:title>\n";
		$xml .= "\t\t\t<video:description>" . esc_xml( $v['description'] ?? '' ) . "</video:description>\n";

		// player_loc and content_loc are mutually exclusive.
		if ( ! empty( $v['player_loc'] ) ) {
			$xml .= "\t\t\t<video:player_loc>" . esc_xml( $v['player_loc'] ) . "</video:player_loc>\n";
		} elseif ( ! empty( $v['content_loc'] ) ) {
			$xml .= "\t\t\t<video:content_loc>" . esc_xml( $v['content_loc'] ) . "</video:content_loc>\n";
		}

		if ( null !== ( $v['duration'] ?? null ) && (int) $v['duration'] > 0 ) {
			$xml .= "\t\t\t<video:duration>" . (int) $v['duration'] . "</video:duration>\n";
		}

		if ( ! empty( $v['pub_date'] ) ) {
			$xml .= "\t\t\t<video:publication_date>" . esc_xml( $v['pub_date'] ) . "</video:publication_date>\n";
		}

		if ( null !== ( $v['rating'] ?? null ) ) {
			$xml .= "\t\t\t<video:rating>" . number_format( (float) $v['rating'], 1 ) . "</video:rating>\n";
		}

		foreach ( (array) ( $v['tags'] ?? [] ) as $tag ) {
			if ( '' !== $tag ) {
				$xml .= "\t\t\t<video:tag>" . esc_xml( (string) $tag ) . "</video:tag>\n";
			}
		}

		if ( ! empty( $v['uploader'] ) ) {
			$info_attr = ! empty( $v['uploader_url'] )
				? ' info="' . esc_attr( $v['uploader_url'] ) . '"'
				: '';
			$xml .= "\t\t\t<video:uploader{$info_attr}>" . esc_xml( $v['uploader'] ) . "</video:uploader>\n";
		}

		$xml .= "\t\t</video:video>\n";
		return $xml;
	}

	/**
	 * Return a well-formed empty <urlset> document for when content or
	 * the feature is disabled.
	 *
	 * @param  string $ns_uri    Optional extra namespace URI (e.g. NS_VIDEO).
	 * @param  string $ns_prefix Namespace prefix to pair with $ns_uri.
	 * @return string            XML document.
	 */
	private static function render_empty_urlset( string $ns_uri, string $ns_prefix ): string {
		$xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
		$xml .= '<urlset xmlns="' . self::NS_SITEMAP . '"';
		if ( '' !== $ns_uri && '' !== $ns_prefix ) {
			$xml .= ' xmlns:' . $ns_prefix . '="' . $ns_uri . '"';
		}
		$xml .= "></urlset>\n";
		return $xml;
	}

	// -----------------------------------------------------------------------
	// Admin help tab
	// -----------------------------------------------------------------------

	/**
	 * Add a contextual help tab for the Sitemaps section on the MM Settings screen.
	 * Hooked to 'load-media_page_metamanager-settings'.
	 */
	public static function add_settings_help_tab(): void {
		$screen = get_current_screen();
		if ( ! $screen ) {
			return;
		}

		$media_url  = home_url( '/sitemap-media.xml' );
		$video_url  = home_url( '/sitemap-video.xml' );

		$screen->add_help_tab( [
			'id'      => 'mm_help_sitemaps',
			'title'   => esc_html__( 'Sitemaps', 'metamanager' ),
			'content' =>
				'<h2>' . esc_html__( 'Metamanager XML Sitemaps', 'metamanager' ) . '</h2>'
				. '<p>' . esc_html__( 'Metamanager generates two dedicated XML sitemap files that you can submit to search engines alongside your main WordPress sitemap.', 'metamanager' ) . '</p>'
				. '<h3>' . esc_html__( 'Media sitemap', 'metamanager' ) . '</h3>'
				. '<p><code>' . esc_html( $media_url ) . '</code></p>'
				. '<p>' . esc_html__( 'Lists all attachment pages for images, video, audio, and PDF files. Image entries include title, caption, license URL (when Copyright is a URL), and GPS location when available. Video entries include duration, keywords, rating, publication date, and uploader.', 'metamanager' ) . '</p>'
				. '<h3>' . esc_html__( 'Video sitemap', 'metamanager' ) . '</h3>'
				. '<p><code>' . esc_html( $video_url ) . '</code></p>'
				. '<p>' . esc_html__( 'Covers three sources: YouTube embeds, Vimeo embeds, and self-hosted &lt;video&gt; tags found in published post content, plus video attachment pages. oEmbed data for YouTube and Vimeo is cached for one week. Duration, keywords, rating, and other video-specific tags are populated from Metamanager metadata for hosted video files.', 'metamanager' ) . '</p>'
				. '<h3>' . esc_html__( 'Submitting to Google Search Console', 'metamanager' ) . '</h3>'
				. '<ol>'
				. '<li>' . esc_html__( 'Open Google Search Console and select your property.', 'metamanager' ) . '</li>'
				. '<li>' . esc_html__( 'Navigate to Indexing → Sitemaps.', 'metamanager' ) . '</li>'
				. '<li>' . sprintf( esc_html__( 'Enter %s and click Submit.', 'metamanager' ), '<code>sitemap-media.xml</code>' ) . '</li>'
				. '<li>' . sprintf( esc_html__( 'Repeat for %s.', 'metamanager' ), '<code>sitemap-video.xml</code>' ) . '</li>'
				. '</ol>'
				. '<p>' . esc_html__( 'Both sitemaps can be toggled and fine-tuned on this page under the Sitemaps section.', 'metamanager' ) . '</p>',
		] );
	}

	// -----------------------------------------------------------------------
	// Settings
	// -----------------------------------------------------------------------

	/**
	 * Register sitemap settings and add a "Sitemaps" section to the existing
	 * Media → MM Settings page. Hooked to 'admin_init'.
	 */
	public static function register_settings(): void {
		$group = 'mm_settings_group';
		$page  = 'metamanager-settings';

		$bool = [
			'type'              => 'boolean',
			'sanitize_callback' => 'rest_sanitize_boolean',
			'default'           => true,
		];

		register_setting( $group, self::OPT_IMAGES,           $bool );
		register_setting( $group, self::OPT_VIDEO,            $bool );
		register_setting( $group, self::OPT_VIDEO_YOUTUBE,    $bool );
		register_setting( $group, self::OPT_VIDEO_VIMEO,      $bool );
		register_setting( $group, self::OPT_VIDEO_SELFHOSTED, $bool );
		register_setting( $group, self::OPT_MEDIA,            $bool );

		add_settings_section(
			'mm_section_sitemaps',
			esc_html__( 'Sitemaps', 'metamanager' ),
			static function (): void {
				echo '<p>' . wp_kses(
					sprintf(
						/* translators: %1$s, %2$s: example sitemap URLs */
						__( 'Metamanager generates two dedicated XML sitemaps: <code>%1$s</code> for media attachment pages and <code>%2$s</code> for video content. Submit these directly to Google Search Console alongside the main WordPress sitemap.', 'metamanager' ),
						esc_html( home_url( '/sitemap-media.xml' ) ),
						esc_html( home_url( '/sitemap-video.xml' ) )
					),
					[ 'code' => [] ]
				) . '</p>';
			},
			$page
		);

		add_settings_field(
			'mm_sitemap_media',
			esc_html__( 'Media sitemap', 'metamanager' ),
			[ __CLASS__, 'field_sitemap_media' ],
			$page,
			'mm_section_sitemaps'
		);

		add_settings_field(
			'mm_sitemap_images',
			esc_html__( 'Image extension nodes', 'metamanager' ),
			[ __CLASS__, 'field_sitemap_images' ],
			$page,
			'mm_section_sitemaps'
		);

		add_settings_field(
			'mm_sitemap_video',
			esc_html__( 'Video sitemap', 'metamanager' ),
			[ __CLASS__, 'field_sitemap_video' ],
			$page,
			'mm_section_sitemaps'
		);
	}

	// -----------------------------------------------------------------------
	// Settings field renderers
	// -----------------------------------------------------------------------

	public static function field_sitemap_media(): void {
		$checked = (bool) get_option( self::OPT_MEDIA, true );
		printf(
			'<input type="checkbox" id="mm_sitemap_media" name="%s" value="1"%s>',
			esc_attr( self::OPT_MEDIA ),
			checked( $checked, true, false )
		);
		echo ' <label for="mm_sitemap_media">'
			. esc_html__( 'Enable /sitemap-media.xml (image, video, audio, and PDF attachment pages)', 'metamanager' )
			. '</label>';
	}

	public static function field_sitemap_images(): void {
		$checked = (bool) get_option( self::OPT_IMAGES, true );
		printf(
			'<input type="checkbox" id="mm_sitemap_images" name="%s" value="1"%s>',
			esc_attr( self::OPT_IMAGES ),
			checked( $checked, true, false )
		);
		echo ' <label for="mm_sitemap_images">'
			. esc_html__( 'Include <image:image> extension nodes in the media sitemap', 'metamanager' )
			. '</label>';
		echo '<p class="description">'
			. esc_html__( 'Adds title, caption, license, and GPS location to each image entry.', 'metamanager' )
			. '</p>';
	}

	public static function field_sitemap_video(): void {
		$checked = (bool) get_option( self::OPT_VIDEO, true );
		printf(
			'<input type="checkbox" id="mm_sitemap_video" name="%s" value="1"%s>',
			esc_attr( self::OPT_VIDEO ),
			checked( $checked, true, false )
		);
		echo ' <label for="mm_sitemap_video">'
			. esc_html__( 'Enable /sitemap-video.xml', 'metamanager' )
			. '</label>';

		echo '<fieldset style="margin-top:8px;padding-left:20px;">';
		echo '<legend class="screen-reader-text">' . esc_html__( 'Video sources', 'metamanager' ) . '</legend>';

		$sources = [
			self::OPT_VIDEO_YOUTUBE    => __( 'YouTube embeds', 'metamanager' ),
			self::OPT_VIDEO_VIMEO      => __( 'Vimeo embeds', 'metamanager' ),
			self::OPT_VIDEO_SELFHOSTED => __( 'Self-hosted &lt;video&gt; tags', 'metamanager' ),
		];

		foreach ( $sources as $opt => $label ) {
			$cb_checked = (bool) get_option( $opt, true );
			printf(
				'<label style="display:block;margin-bottom:4px;"><input type="checkbox" name="%s" value="1"%s> %s</label>',
				esc_attr( $opt ),
				checked( $cb_checked, true, false ),
				wp_kses( $label, [] )
			);
		}

		echo '</fieldset>';
		echo '<p class="description">'
			. esc_html__( 'Video attachments are always included. The checkboxes above control extraction of embedded videos from post content.', 'metamanager' )
			. '</p>';
	}
}
