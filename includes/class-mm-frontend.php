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
 *   1. Schema.org JSON-LD  ImageObject  with GeoCoordinates when GPS data is
 *      present. Uses all fields stored by MM_Metadata, including the full
 *      IPTC location hierarchy and GPS lat/lon/altitude from the camera.
 *   2. Open Graph meta tags  (og:image, og:image:alt, og:image:width,
 *      og:image:height, og:image:type, og:image:secure_url).
 *   3. <link rel="license"> or <meta name="copyright"> when copyright is set.
 *
 * Design notes:
 *   - Output is suppressed when is_admin() so nothing fires in the block
 *     editor iframe or REST previews.
 *   - Each output section is wrapped in an HTML comment so it is easy to
 *     recognise in View Source.
 *   - JSON is encoded with JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE so
 *     URLs remain readable and non-ASCII characters (e.g. © symbols in
 *     copyright notices) are preserved verbatim.
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
	 * Resolve the current page to an image attachment and emit all head tags.
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
			// Accept images and supported video/audio types.
			if ( wp_attachment_is_image( $id ) || MM_Metadata::is_av_mime( $mime ) ) {
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
	// Schema.org JSON-LD
	// -----------------------------------------------------------------------

	/**
	 * Emit a Schema.org ImageObject JSON-LD block.
	 *
	 * Fields produced (when data is available):
	 *   - @type ImageObject
	 *   - url / contentUrl         (full-size image URL)
	 *   - width / height
	 *   - name                     (post title)
	 *   - description              (post content)
	 *   - caption                  (post excerpt)
	 *   - alternativeHeadline      (alt text)
	 *   - headline                 (mm_headline)
	 *   - creator → Person         (mm_creator)
	 *   - copyrightNotice          (mm_copyright)
	 *   - copyrightHolder → Org    (mm_owner)
	 *   - creditText               (mm_credit)
	 *   - keywords[]               (mm_keywords, split on ';')
	 *   - dateCreated              (mm_date_created)
	 *   - locationCreated → Place  (IPTC city/state/country + GeoCoordinates)
	 *   - contentLocation → Place  (same — where the subject is depicted)
	 *   - thumbnail → ImageObject  (medium size)
	 *   - mainEntityOfPage         (attachment page URL)
	 *
	 * @param int $id  Attachment post ID.
	 */
	private static function output_json_ld( int $id ): void {
		$post = get_post( $id );
		if ( ! $post || ! wp_attachment_is_image( $id ) ) {
			return;
		}

		// Full-size URL and pixel dimensions.
		$src = wp_get_attachment_image_src( $id, 'full' );
		if ( ! $src ) {
			return;
		}
		[ $url, $width, $height ] = $src;

		$meta = static fn( string $key ): string => (string) get_post_meta( $id, $key, true );

		// Build schema incrementally — only include keys that have values.
		$schema = [
			'@context'   => 'https://schema.org',
			'@type'      => 'ImageObject',
			'url'        => $url,
			'contentUrl' => $url,
		];

		if ( $width )  { $schema['width']  = $width; }
		if ( $height ) { $schema['height'] = $height; }

		// WordPress native fields.
		$title = trim( $post->post_title );
		if ( '' !== $title )              { $schema['name']        = $title; }
		if ( '' !== $post->post_content ) { $schema['description'] = wp_strip_all_tags( $post->post_content ); }
		if ( '' !== $post->post_excerpt ) { $schema['caption']     = $post->post_excerpt; }

		$alt = (string) get_post_meta( $id, '_wp_attachment_image_alt', true );
		if ( '' !== $alt ) { $schema['alternativeHeadline'] = $alt; }

		// Editorial.
		if ( '' !== ( $v = $meta( MM_Metadata::META_HEADLINE ) ) ) { $schema['headline']    = $v; }
		if ( '' !== ( $v = $meta( MM_Metadata::META_CREDIT ) ) )   { $schema['creditText']  = $v; }

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
			if ( $kw ) { $schema['keywords'] = $kw; }
		}
		if ( '' !== ( $v = $meta( MM_Metadata::META_DATE ) ) ) { $schema['dateCreated'] = $v; }

		// Location: IPTC text fields.
		$city    = $meta( MM_Metadata::META_CITY );
		$state   = $meta( MM_Metadata::META_STATE );
		$country = $meta( MM_Metadata::META_COUNTRY );
		$loc_parts = array_filter( [ $city, $state, $country ] );

		// Location: GPS coordinates.
		$lat = $meta( MM_Metadata::META_GPS_LAT );
		$lon = $meta( MM_Metadata::META_GPS_LON );
		$alt_m = $meta( MM_Metadata::META_GPS_ALT );

		if ( '' !== $lat && '' !== $lon ) {
			// We have GPS — build a full Place with GeoCoordinates.
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

			$schema['locationCreated']  = $place;
			$schema['contentLocation']  = $place;

		} elseif ( $loc_parts ) {
			// IPTC text location only — no GPS.
			$place = [ '@type' => 'Place', 'name' => implode( ', ', $loc_parts ) ];
			$schema['locationCreated'] = $place;
			$schema['contentLocation'] = $place;
		}

		// Thumbnail (medium size, if different from full).
		$thumb = wp_get_attachment_image_src( $id, 'medium' );
		if ( $thumb && $thumb[0] !== $url ) {
			$schema['thumbnail'] = [ '@type' => 'ImageObject', 'url' => $thumb[0] ];
		}

		// Canonical page URL.
		$page_url = get_attachment_link( $id );
		if ( $page_url ) {
			$schema['mainEntityOfPage'] = [ '@type' => 'WebPage', '@id' => $page_url ];
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		printf(
			"\n<!-- Metamanager: Schema.org JSON-LD -->\n<script type=\"application/ld+json\">\n%s\n</script>\n",
			wp_json_encode( $schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE )
		);
	}

	// -----------------------------------------------------------------------
	// Video / Audio — Schema.org + Open Graph
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

		$is_video   = MM_Metadata::is_video_mime( $mime );
		$schema_type = $is_video ? 'VideoObject' : 'AudioObject';

		$meta = static fn( string $key ): string => (string) get_post_meta( $id, $key, true );

		$schema = [
			'@context'   => 'https://schema.org',
			'@type'      => $schema_type,
			'url'        => $url,
			'contentUrl' => $url,
		];

		$title = trim( $post->post_title );
		if ( '' !== $title )              { $schema['name']        = $title; }
		if ( '' !== $post->post_content ) { $schema['description'] = wp_strip_all_tags( $post->post_content ); }

		if ( '' !== ( $v = $meta( MM_Metadata::META_HEADLINE ) ) )  { $schema['headline']   = $v; }
		if ( '' !== ( $v = $meta( MM_Metadata::META_CREDIT ) ) )    { $schema['creditText'] = $v; }
		if ( '' !== ( $v = $meta( MM_Metadata::META_DATE ) ) )      { $schema['dateCreated'] = $v; }

		if ( '' !== ( $v = $meta( MM_Metadata::META_CREATOR ) ) ) {
			$schema['creator'] = [ '@type' => 'Person', 'name' => $v ];
		}
		if ( '' !== ( $v = $meta( MM_Metadata::META_COPYRIGHT ) ) ) {
			$schema['copyrightNotice'] = $v;
		}
		if ( '' !== ( $v = $meta( MM_Metadata::META_OWNER ) ) ) {
			$schema['copyrightHolder'] = [ '@type' => 'Organization', 'name' => $v ];
		}

		if ( '' !== ( $v = $meta( MM_Metadata::META_KEYWORDS ) ) ) {
			$kw = array_values( array_filter( array_map( 'trim', explode( ';', $v ) ) ) );
			if ( $kw ) { $schema['keywords'] = $kw; }
		}

		// Location.
		$city    = $meta( MM_Metadata::META_CITY );
		$state   = $meta( MM_Metadata::META_STATE );
		$country = $meta( MM_Metadata::META_COUNTRY );
		$loc_parts = array_filter( [ $city, $state, $country ] );

		$lat   = $meta( MM_Metadata::META_GPS_LAT );
		$lon   = $meta( MM_Metadata::META_GPS_LON );
		$alt_m = $meta( MM_Metadata::META_GPS_ALT );

		if ( '' !== $lat && '' !== $lon ) {
			$geo   = [ '@type' => 'GeoCoordinates', 'latitude' => (float) $lat, 'longitude' => (float) $lon ];
			if ( '' !== $alt_m ) { $geo['elevation'] = (float) $alt_m; }
			$place = [ '@type' => 'Place', 'geo' => $geo ];
			if ( $loc_parts ) { $place['name'] = implode( ', ', $loc_parts ); }
			$schema['locationCreated'] = $place;
			$schema['contentLocation'] = $place;
		} elseif ( $loc_parts ) {
			$place = [ '@type' => 'Place', 'name' => implode( ', ', $loc_parts ) ];
			$schema['locationCreated'] = $place;
			$schema['contentLocation'] = $place;
		}

		$schema['encodingFormat']  = $mime;
		$schema['mainEntityOfPage'] = [ '@type' => 'WebPage', '@id' => get_attachment_link( $id ) ];

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		printf(
			"\n<!-- Metamanager: Schema.org JSON-LD -->\n<script type=\"application/ld+json\">\n%s\n</script>\n",
			wp_json_encode( $schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE )
		);
	}

	/**
	 * Emit Open Graph tags for a video or audio attachment.
	 *
	 * Video attachments get og:video; audio gets og:audio.
	 *
	 * @param int    $id   Attachment post ID.
	 * @param string $mime MIME type.
	 */
	private static function output_video_audio_open_graph( int $id, string $mime ): void {
		$url = wp_get_attachment_url( $id );
		if ( ! $url ) {
			return;
		}

		$is_video = MM_Metadata::is_video_mime( $mime );
		$ns       = $is_video ? 'og:video' : 'og:audio';

		$tag = static fn( string $prop, string $content ) =>
			printf( "\t<meta property=\"%s\" content=\"%s\">\n", esc_attr( $prop ), esc_attr( $content ) );

		echo "\n<!-- Metamanager: Open Graph -->\n";

		$tag( $ns, $url );
		if ( str_starts_with( $url, 'https://' ) ) {
			$tag( $ns . ':secure_url', $url );
		}
		$tag( $ns . ':type', $mime );
	}

	// -----------------------------------------------------------------------
	// Open Graph
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
		if ( $mime ) { $tag( 'og:image:type', $mime ); }

		$alt = (string) get_post_meta( $id, '_wp_attachment_image_alt', true );
		if ( '' !== $alt ) { $tag( 'og:image:alt', $alt ); }
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
