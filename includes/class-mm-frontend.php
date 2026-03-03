<?php
/**
 * MM_Frontend — front-end schema and Open Graph output.
 *
 * Fires on wp_head for:
 *   - Attachment pages (is_attachment()).
 *   - Single posts / pages that have a featured image (is_singular() +
 *     has_post_thumbnail()).
 *
 * Outputs:
 *   1. Schema.org JSON-LD:
 *        ImageObject    for images
 *        VideoObject    for video attachments
 *        AudioObject    for audio attachments
 *        DigitalDocument for PDF attachments
 *      All types include GeoCoordinates when GPS data is present.
 *   2. Open Graph meta tags appropriate to each media type.
 *   3. <link rel="license"> or <meta name="copyright"> when copyright is set.
 *
 * Design notes:
 *   - Output is suppressed when is_admin() so nothing fires in the block
 *     editor iframe or REST previews.
 *   - Each output section is wrapped in an HTML comment so it is easy to
 *     recognise in View Source.
 *   - JSON is encoded with JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE so
 *     URLs remain readable and non-ASCII characters are preserved verbatim.
 *   - build_schema_base() is the single source of truth for the shared fields
 *     (attribution, keywords, location, dates) so each type-specific builder
 *     only adds its own distinct properties.
 *
 * @package Metamanager
 * @since   1.1.0
 * @license GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MM_Frontend {

	// -----------------------------------------------------------------------
	// Boot
	// -----------------------------------------------------------------------

	/**
	 * Register the wp_head action. Called from the main plugin file on
	 * 'plugins_loaded' so it only runs on the public front end.
	 */
	public static function init(): void {
		add_action( 'wp_head', [ __CLASS__, 'output_head_tags' ], 5 );
	}

	// -----------------------------------------------------------------------
	// Primary entry point
	// -----------------------------------------------------------------------

	/**
	 * Resolve the current page to an attachment and emit all head tags.
	 */
	public static function output_head_tags(): void {
		$attachment_id = self::resolve_attachment_id();
		if ( ! $attachment_id ) {
			return;
		}

		$mime = (string) get_post_mime_type( $attachment_id );

		if ( MM_Metadata::is_video_mime( $mime ) || MM_Metadata::is_audio_mime( $mime ) ) {
			self::output_video_audio_json_ld( $attachment_id, $mime );
			self::output_video_audio_open_graph( $attachment_id, $mime );
		} elseif ( MM_Metadata::is_pdf_mime( $mime ) ) {
			self::output_pdf_json_ld( $attachment_id );
			self::output_pdf_open_graph( $attachment_id );
		} else {
			self::output_json_ld( $attachment_id );
			self::output_open_graph( $attachment_id );
		}

		self::output_license_link( $attachment_id );
	}

	// -----------------------------------------------------------------------
	// Resolve which attachment to describe
	// -----------------------------------------------------------------------

	/**
	 * Return the attachment ID relevant to the current page, or 0 if none.
	 *
	 * Priority:
	 *   1. Attachment page  → the attachment itself.
	 *   2. Single post/page → the featured image (post thumbnail).
	 *
	 * @return int  Attachment post ID, or 0.
	 */
	private static function resolve_attachment_id(): int {
		if ( is_attachment() ) {
			$id   = (int) get_the_ID();
			$mime = (string) get_post_mime_type( $id );
			if ( wp_attachment_is_image( $id )
				|| MM_Metadata::is_av_mime( $mime )
				|| MM_Metadata::is_pdf_mime( $mime ) ) {
				return $id;
			}
			return 0;
		}

		if ( is_singular() && has_post_thumbnail() ) {
			$id = (int) get_post_thumbnail_id( get_the_ID() );
			return $id > 0 ? $id : 0;
		}

		return 0;
	}

	// -----------------------------------------------------------------------
	// Shared schema base builder
	// -----------------------------------------------------------------------

	/**
	 * Build the common fields shared by all Schema.org types: attribution,
	 * copyright, keywords, location (IPTC text + GPS), and dates.
	 *
	 * The caller receives a partial schema array and is responsible for adding
	 * @context, @type, url, contentUrl, and any type-specific fields.
	 *
	 * @param  int       $id   Attachment post ID.
	 * @param  \WP_Post  $post Post object (passed in to avoid re-fetching).
	 * @return array           Partial schema array.
	 */
	private static function build_schema_base( int $id, \WP_Post $post ): array {
		$meta = static fn( string $key ): string => (string) get_post_meta( $id, $key, true );

		$schema = [];

		// Title / name.
		$title = trim( $post->post_title );
		if ( '' !== $title ) {
			$schema['name'] = $title;
		}

		// Description (long-form body text).
		if ( '' !== $post->post_content ) {
			$schema['description'] = wp_strip_all_tags( $post->post_content );
		}

		// Editorial.
		if ( '' !== ( $v = $meta( MM_Metadata::META_HEADLINE ) ) ) {
			$schema['headline'] = $v;
		}
		if ( '' !== ( $v = $meta( MM_Metadata::META_CREDIT ) ) ) {
			$schema['creditText'] = $v;
		}

		// Attribution.
		if ( '' !== ( $v = $meta( MM_Metadata::META_CREATOR ) ) ) {
			$schema['creator'] = [ '@type' => 'Person', 'name' => $v ];
		}
		if ( '' !== ( $v = $meta( MM_Metadata::META_COPYRIGHT ) ) ) {
			$schema['copyrightNotice'] = $v;
		}
		if ( '' !== ( $v = $meta( MM_Metadata::META_OWNER ) ) ) {
			$schema['copyrightHolder'] = [ '@type' => 'Organization', 'name' => $v ];
		}

		// Classification.
		if ( '' !== ( $v = $meta( MM_Metadata::META_KEYWORDS ) ) ) {
			$kw = array_values( array_filter( array_map( 'trim', explode( ';', $v ) ) ) );
			if ( $kw ) {
				$schema['keywords'] = $kw;
			}
		}

		// Date.
		if ( '' !== ( $v = $meta( MM_Metadata::META_DATE ) ) ) {
			$schema['dateCreated'] = $v;
		}

		// Location: IPTC text fields.
		$city      = $meta( MM_Metadata::META_CITY );
		$state     = $meta( MM_Metadata::META_STATE );
		$country   = $meta( MM_Metadata::META_COUNTRY );
		$loc_parts = array_filter( [ $city, $state, $country ] );

		// Location: GPS coordinates.
		$lat   = $meta( MM_Metadata::META_GPS_LAT );
		$lon   = $meta( MM_Metadata::META_GPS_LON );
		$alt_m = $meta( MM_Metadata::META_GPS_ALT );

		if ( '' !== $lat && '' !== $lon ) {
			$geo = [
				'@type'     => 'GeoCoordinates',
				'latitude'  => (float) $lat,
				'longitude' => (float) $lon,
			];
			if ( '' !== $alt_m ) {
				$geo['elevation'] = (float) $alt_m;
			}
			$place = [ '@type' => 'Place', 'geo' => $geo ];
			if ( $loc_parts ) {
				$place['name'] = implode( ', ', $loc_parts );
			}
			$schema['locationCreated'] = $place;
			$schema['contentLocation'] = $place;
		} elseif ( $loc_parts ) {
			$place = [ '@type' => 'Place', 'name' => implode( ', ', $loc_parts ) ];
			$schema['locationCreated'] = $place;
			$schema['contentLocation'] = $place;
		}

		// Canonical page URL.
		$page_url = get_attachment_link( $id );
		if ( $page_url ) {
			$schema['mainEntityOfPage'] = [ '@type' => 'WebPage', '@id' => $page_url ];
		}

		return $schema;
	}

	// -----------------------------------------------------------------------
	// Schema.org JSON-LD — images
	// -----------------------------------------------------------------------

	/**
	 * Emit a Schema.org ImageObject JSON-LD block.
	 *
	 * @param int $id  Attachment post ID.
	 */
	private static function output_json_ld( int $id ): void {
		$post = get_post( $id );
		if ( ! $post || ! wp_attachment_is_image( $id ) ) {
			return;
		}

		$src = wp_get_attachment_image_src( $id, 'full' );
		if ( ! $src ) {
			return;
		}
		[ $url, $width, $height ] = $src;

		$schema = array_merge(
			[
				'@context'   => 'https://schema.org',
				'@type'      => 'ImageObject',
				'url'        => $url,
				'contentUrl' => $url,
			],
			self::build_schema_base( $id, $post )
		);

		if ( $width )  { $schema['width']  = $width; }
		if ( $height ) { $schema['height'] = $height; }

		// Image-specific fields.
		if ( '' !== $post->post_excerpt ) {
			$schema['caption'] = $post->post_excerpt;
		}

		$alt = (string) get_post_meta( $id, '_wp_attachment_image_alt', true );
		if ( '' !== $alt ) {
			$schema['alternativeHeadline'] = $alt;
		}

		// Thumbnail (medium size, if different from full).
		$thumb = wp_get_attachment_image_src( $id, 'medium' );
		if ( $thumb && $thumb[0] !== $url ) {
			$schema['thumbnail'] = [ '@type' => 'ImageObject', 'url' => $thumb[0] ];
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		printf(
			"\n<!-- Metamanager: Schema.org JSON-LD -->\n<script type=\"application/ld+json\">\n%s\n</script>\n",
			wp_json_encode( $schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE )
		);
	}

	// -----------------------------------------------------------------------
	// Schema.org JSON-LD — video / audio
	// -----------------------------------------------------------------------

	/**
	 * Emit Schema.org JSON-LD for a video or audio attachment.
	 * Uses VideoObject for video MIME types, AudioObject for audio.
	 *
	 * @param int    $id   Attachment post ID.
	 * @param string $mime MIME type.
	 */
	private static function output_video_audio_json_ld( int $id, string $mime ): void {
		$post = get_post( $id );
		if ( ! $post ) {
			return;
		}

		$url = wp_get_attachment_url( $id );
		if ( ! $url ) {
			return;
		}

		$is_video    = MM_Metadata::is_video_mime( $mime );
		$schema_type = $is_video ? 'VideoObject' : 'AudioObject';

		$schema = array_merge(
			[
				'@context'       => 'https://schema.org',
				'@type'          => $schema_type,
				'url'            => $url,
				'contentUrl'     => $url,
				'encodingFormat' => $mime,
			],
			self::build_schema_base( $id, $post )
		);

		// uploadDate is required by Google for VideoObject rich results; always set from post date.
		$schema['uploadDate'] = gmdate( 'Y-m-d', strtotime( $post->post_date_gmt ) );

		// Thumbnail: featured image attached to the media post, used by VideoObject.
		if ( $is_video ) {
			$thumb_id  = (int) get_post_thumbnail_id( $id );
			$thumb_src = $thumb_id ? wp_get_attachment_image_src( $thumb_id, 'medium' ) : false;
			if ( $thumb_src ) {
				$schema['thumbnailUrl'] = $thumb_src[0];
			}
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		printf(
			"\n<!-- Metamanager: Schema.org JSON-LD -->\n<script type=\"application/ld+json\">\n%s\n</script>\n",
			wp_json_encode( $schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE )
		);
	}

	// -----------------------------------------------------------------------
	// Schema.org JSON-LD — PDF (DigitalDocument)
	// -----------------------------------------------------------------------

	/**
	 * Emit Schema.org DigitalDocument JSON-LD for a PDF attachment.
	 *
	 * @param int $id  Attachment post ID.
	 */
	private static function output_pdf_json_ld( int $id ): void {
		$post = get_post( $id );
		if ( ! $post ) {
			return;
		}

		$url = wp_get_attachment_url( $id );
		if ( ! $url ) {
			return;
		}

		$schema = array_merge(
			[
				'@context'       => 'https://schema.org',
				'@type'          => 'DigitalDocument',
				'url'            => $url,
				'contentUrl'     => $url,
				'encodingFormat' => 'application/pdf',
			],
			self::build_schema_base( $id, $post )
		);

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		printf(
			"\n<!-- Metamanager: Schema.org JSON-LD -->\n<script type=\"application/ld+json\">\n%s\n</script>\n",
			wp_json_encode( $schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE )
		);
	}

	// -----------------------------------------------------------------------
	// Open Graph — video / audio
	// -----------------------------------------------------------------------

	/**
	 * Emit Open Graph tags for a video or audio attachment.
	 * Video → og:video; audio → og:audio.
	 *
	 * @param int    $id   Attachment post ID.
	 * @param string $mime MIME type.
	 */
	private static function output_video_audio_open_graph( int $id, string $mime ): void {
		$post = get_post( $id );
		if ( ! $post ) {
			return;
		}

		$url = wp_get_attachment_url( $id );
		if ( ! $url ) {
			return;
		}

		$is_video = MM_Metadata::is_video_mime( $mime );
		$ns       = $is_video ? 'og:video' : 'og:audio';

		$tag = static fn( string $prop, string $content ) =>
			printf( "\t<meta property=\"%s\" content=\"%s\">\n", esc_attr( $prop ), esc_attr( $content ) );

		echo "\n<!-- Metamanager: Open Graph -->\n";
		$tag( 'og:type', $is_video ? 'video.other' : 'music.song' );
		$tag( 'og:url',  get_attachment_link( $id ) ?: $url );

		$title = trim( $post->post_title );
		if ( '' !== $title ) {
			$tag( 'og:title', $title );
		}
		if ( '' !== $post->post_content ) {
			$tag( 'og:description', wp_strip_all_tags( $post->post_content ) );
		}

		// Preview image for link unfurling on social platforms.
		$thumb_id  = (int) get_post_thumbnail_id( $id );
		$thumb_src = $thumb_id ? wp_get_attachment_image_src( $thumb_id, 'large' ) : false;
		if ( $thumb_src ) {
			$tag( 'og:image', $thumb_src[0] );
			if ( str_starts_with( $thumb_src[0], 'https://' ) ) {
				$tag( 'og:image:secure_url', $thumb_src[0] );
			}
			if ( ! empty( $thumb_src[1] ) ) { $tag( 'og:image:width',  (string) $thumb_src[1] ); }
			if ( ! empty( $thumb_src[2] ) ) { $tag( 'og:image:height', (string) $thumb_src[2] ); }
		}

		$tag( $ns, $url );
		if ( str_starts_with( $url, 'https://' ) ) {
			$tag( $ns . ':secure_url', $url );
		}
		$tag( $ns . ':type', $mime );
	}

	// -----------------------------------------------------------------------
	// Open Graph — PDF
	// -----------------------------------------------------------------------

	/**
	 * Emit Open Graph tags for a PDF attachment page.
	 * PDFs are typed as og:type=article (closest OG equivalent for documents).
	 *
	 * @param int $id  Attachment post ID.
	 */
	private static function output_pdf_open_graph( int $id ): void {
		$post = get_post( $id );
		if ( ! $post ) {
			return;
		}

		$url = wp_get_attachment_url( $id );
		if ( ! $url ) {
			return;
		}

		$tag = static fn( string $prop, string $content ) =>
			printf( "\t<meta property=\"%s\" content=\"%s\">\n", esc_attr( $prop ), esc_attr( $content ) );

		echo "\n<!-- Metamanager: Open Graph -->\n";
		$tag( 'og:type',  'article' );
		$tag( 'og:url',   get_attachment_link( $id ) ?: $url );

		$title = trim( $post->post_title );
		if ( '' !== $title ) {
			$tag( 'og:title', $title );
		}

		if ( '' !== $post->post_content ) {
			$tag( 'og:description', wp_strip_all_tags( $post->post_content ) );
		}
	}

	// -----------------------------------------------------------------------
	// Open Graph — images
	// -----------------------------------------------------------------------

	/**
	 * Emit Open Graph image meta tags.
	 *
	 * Tags produced: og:image, og:image:secure_url (HTTPS only),
	 * og:image:width, og:image:height, og:image:type, og:image:alt.
	 *
	 * @param int $id  Attachment post ID.
	 */
	private static function output_open_graph( int $id ): void {
		if ( ! wp_attachment_is_image( $id ) ) {
			return;
		}

		$src = wp_get_attachment_image_src( $id, 'full' );
		if ( ! $src ) {
			return;
		}
		[ $url, $width, $height ] = $src;

		$tag = static fn( string $prop, string $content ) =>
			printf( "\t<meta property=\"%s\" content=\"%s\">\n", esc_attr( $prop ), esc_attr( $content ) );

		echo "\n<!-- Metamanager: Open Graph -->\n";
		$tag( 'og:image', $url );

		if ( str_starts_with( $url, 'https://' ) ) {
			$tag( 'og:image:secure_url', $url );
		}

		if ( $width )  { $tag( 'og:image:width',  (string) $width ); }
		if ( $height ) { $tag( 'og:image:height', (string) $height ); }

		$mime = get_post_mime_type( $id );
		if ( $mime ) {
			$tag( 'og:image:type', $mime );
		}

		$alt = (string) get_post_meta( $id, '_wp_attachment_image_alt', true );
		if ( '' !== $alt ) {
			$tag( 'og:image:alt', $alt );
		}
	}

	// -----------------------------------------------------------------------
	// License link
	// -----------------------------------------------------------------------

	/**
	 * Emit a <link rel="license"> or <meta name="copyright"> tag.
	 *
	 * If the stored copyright value is a valid URL (e.g. a Creative Commons
	 * licence URI) it becomes  rel="license".  Plain text notices become a
	 * standard  <meta name="copyright"> tag instead.
	 *
	 * @param int $id  Attachment post ID.
	 */
	private static function output_license_link( int $id ): void {
		$copyright = (string) get_post_meta( $id, MM_Metadata::META_COPYRIGHT, true );
		if ( '' === $copyright ) {
			return;
		}

		if ( filter_var( $copyright, FILTER_VALIDATE_URL ) ) {
			printf( "\t<link rel=\"license\" href=\"%s\">\n", esc_url( $copyright ) );
		} else {
			printf( "\t<meta name=\"copyright\" content=\"%s\">\n", esc_attr( $copyright ) );
		}
	}
}
