<?php
/**
 * Metamanager Metadata Class
 *
 * Handles all metadata field definitions, saving custom fields, building job
 * payloads, reading embedded metadata from files for display, and importing
 * embedded metadata from image files back into WordPress on upload.
 *
 * Storage model:
 * - WordPress native fields (post_title, post_content, post_excerpt,
 *   _wp_attachment_image_alt) are managed by WordPress directly.
 * - Extended fields (Creator, Copyright, Owner, Headline, Credit, Keywords,
 *   Date Created, Location, Rating) are stored as standard wp_postmeta rows
 *   via register_post_meta() — the codex-compliant way to declare typed,
 *   REST-exposed, sanitised custom fields for attachments.
 *
 * Sync direction:
 * - Upload: embedded file metadata → WP fields (import_from_file).
 *   Native fields populated if empty; custom meta populated if empty.
 *   Existing values (set by prior user action) are never overwritten.
 * - Edit/save: WP fields → embedded file metadata, via daemon job queue.
 *
 * Attribution rule:
 * - Creator, Copyright, and Owner are PER-IMAGE fields.
 *   They are intentionally excluded from all bulk actions.
 *
 * @package Metamanager
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class MM_Metadata
 */
class MM_Metadata {

	// -----------------------------------------------------------------------
	// Custom post meta key constants
	// Single authoritative definition — used by all methods and external callers.
	// -----------------------------------------------------------------------

	public const META_CREATOR   = 'mm_creator';
	public const META_COPYRIGHT = 'mm_copyright';
	public const META_OWNER     = 'mm_owner';
	public const META_HEADLINE  = 'mm_headline';
	public const META_CREDIT    = 'mm_credit';

	/** Semicolon-separated keywords. Multi-value IPTC/XMP tags are joined with "; ". */
	public const META_KEYWORDS = 'mm_keywords';

	/** Date originally created, stored as YYYY-MM-DD. */
	public const META_DATE    = 'mm_date_created';

	public const META_CITY    = 'mm_location_city';
	public const META_STATE   = 'mm_location_state';
	public const META_COUNTRY = 'mm_location_country';

	/** Star rating 0 (unrated) to 5 — XMP:Rating convention. */
	public const META_RATING  = 'mm_rating';

	// -----------------------------------------------------------------------
	// Field definitions — logical key → ExifTool write tags
	// -----------------------------------------------------------------------

	/**
	 * Logical field map: PHP key => ExifTool tag names to write.
	 *
	 * Single source of truth shared with the shell daemon, which declares an
	 * identical mapping in bash.
	 *
	 * @return array<string, string[]>
	 */
	public static function field_map(): array {
		return [
			// WordPress native.
			'Title'       => [ 'Title', 'IPTC:ObjectName', 'XMP:Title' ],
			'Description' => [ 'EXIF:ImageDescription', 'IPTC:Caption-Abstract', 'XMP:Description' ],
			'Caption'     => [ 'IPTC:Caption-Abstract', 'XMP:Caption' ],
			'AltText'     => [ 'XMP:AltTextAccessibility' ],
			// Per-image attribution — never bulk.
			'Creator'     => [ 'EXIF:Artist', 'IPTC:By-line', 'XMP:Creator' ],
			'Copyright'   => [ 'EXIF:Copyright', 'IPTC:CopyrightNotice', 'XMP:Rights' ],
			'Owner'       => [ 'XMP:Owner', 'EXIF:OwnerName' ],
			// Site provenance — safe for bulk.
			'Publisher'   => [ 'IPTC:Source', 'XMP:Publisher' ],
			'Website'     => [ 'XMP:WebStatement', 'IPTC:Source' ],
			// Editorial.
			'Headline'    => [ 'IPTC:Headline', 'XMP:Headline' ],
			'Credit'      => [ 'IPTC:Credit', 'XMP:Credit' ],
			// Classification.
			'Keywords'    => [ 'IPTC:Keywords', 'XMP:Subject' ],
			'DateCreated' => [ 'EXIF:DateTimeOriginal', 'IPTC:DateCreated', 'XMP:DateCreated' ],
			'Rating'      => [ 'XMP:Rating' ],
			// Location (IPTC Photo Metadata Standard).
			'City'        => [ 'IPTC:City', 'XMP:City' ],
			'State'       => [ 'IPTC:Province-State', 'XMP:State' ],
			'Country'     => [ 'IPTC:Country-PrimaryLocationName', 'XMP:Country' ],
		];
	}

	// -----------------------------------------------------------------------
	// Register post meta — WordPress codex-compliant storage declaration
	// -----------------------------------------------------------------------

	/**
	 * Declare all custom attachment meta keys via register_post_meta().
	 *
	 * This is the WordPress-codex-compliant way to store additional data in
	 * the database for attachments. register_post_meta() provides:
	 * - Value type declaration (for REST API schema and sanitisation).
	 * - An auth callback (who may read/write via REST).
	 * - A sanitise callback (applied automatically by update_post_meta).
	 * - REST API visibility — show_in_rest:true exposes values at
	 *   /wp/v2/media/<id> for Gutenberg and external consumers.
	 *
	 * Must be called on the 'init' action.
	 */
	public static function register_meta(): void {
		$base = [
			'object_subtype'    => 'attachment',
			'type'              => 'string',
			'single'            => true,
			'sanitize_callback' => 'sanitize_text_field',
			'auth_callback'     => fn() => current_user_can( 'upload_files' ),
			'show_in_rest'      => true,
		];

		$string_fields = [
			self::META_CREATOR   => __( 'Original creator or photographer.', 'metamanager' ),
			self::META_COPYRIGHT => __( 'Copyright notice.', 'metamanager' ),
			self::META_OWNER     => __( 'Current rights holder or asset owner.', 'metamanager' ),
			self::META_HEADLINE  => __( 'Short editorial headline.', 'metamanager' ),
			self::META_CREDIT    => __( 'Credit line (e.g. agency or photographer credit).', 'metamanager' ),
			self::META_KEYWORDS  => __( 'Semicolon-separated descriptive keywords.', 'metamanager' ),
			self::META_DATE      => __( 'Date the image was originally created (YYYY-MM-DD).', 'metamanager' ),
			self::META_CITY      => __( 'City where the image was created.', 'metamanager' ),
			self::META_STATE     => __( 'State or province where the image was created.', 'metamanager' ),
			self::META_COUNTRY   => __( 'Country where the image was created.', 'metamanager' ),
		];

		foreach ( $string_fields as $key => $description ) {
			register_post_meta( 'attachment', $key, array_merge( $base, [ 'description' => $description ] ) );
		}

		// Rating is an integer (0 = unrated, 1-5 = stars).
		register_post_meta( 'attachment', self::META_RATING, [
			'object_subtype'    => 'attachment',
			'type'              => 'integer',
			'description'       => __( 'Star rating 0–5.', 'metamanager' ),
			'single'            => true,
			'sanitize_callback' => fn( $v ) => min( 5, max( 0, (int) $v ) ),
			'auth_callback'     => fn() => current_user_can( 'upload_files' ),
			'show_in_rest'      => true,
		] );
	}

	// -----------------------------------------------------------------------
	// Import embedded metadata from file → WordPress (fires on upload)
	// -----------------------------------------------------------------------

	/**
	 * Read embedded metadata from the image file and populate WordPress fields.
	 *
	 * Called by MM_Job_Queue::on_upload() before jobs are enqueued so the
	 * job payload already contains the imported values.
	 *
	 * Rules:
	 * - Custom post meta: imported only if currently empty (never overwrites
	 *   values set by a prior user action).
	 * - WP native fields (post_content, post_excerpt, alt text): imported if empty.
	 * - post_title: imported if a meaningful embedded title exists and the current
	 *   title matches the sanitised filename WordPress set automatically.
	 *
	 * @param int $attachment_id WordPress attachment ID.
	 */
	public static function import_from_file( int $attachment_id ): void {
		if ( ! MM_Status::exiftool_available() ) {
			return;
		}

		$file = get_attached_file( $attachment_id );
		if ( ! $file || ! file_exists( $file ) ) {
			return;
		}

		$embedded = self::read_embedded( $file );
		if ( empty( $embedded ) ) {
			return;
		}

		// Helper: first non-empty value from a priority-ordered list of ExifTool tags.
		// ExifTool with -G1 names tags as "Group:Tag" — EXIF IFD0 tags appear as
		// "IFD0:Tag", ExifIFD tags as "ExifIFD:Tag". We list real group names plus
		// common aliases so images from any camera/software are handled correctly.
		$pick = static function ( array $candidates ) use ( $embedded ): string {
			foreach ( $candidates as $tag ) {
				$value = $embedded[ $tag ] ?? '';
				if ( is_array( $value ) ) {
					// Multi-value (Keywords, Subject) → semicolon-separated string.
					$value = implode( '; ', array_filter( array_map( 'trim', $value ) ) );
				}
				$value = trim( (string) $value );
				if ( '' !== $value ) {
					return $value;
				}
			}
			return '';
		};

		// Custom post meta: priority-ordered ExifTool tag candidates.
		$meta_import = [
			self::META_CREATOR   => [ 'IPTC:By-line', 'IFD0:Artist', 'XMP:Creator', 'EXIF:Artist' ],
			self::META_COPYRIGHT => [ 'IPTC:CopyrightNotice', 'IFD0:Copyright', 'XMP:Rights', 'EXIF:Copyright' ],
			self::META_OWNER     => [ 'ExifIFD:OwnerName', 'IFD0:OwnerName', 'XMP:Owner' ],
			self::META_HEADLINE  => [ 'IPTC:Headline', 'XMP:Headline' ],
			self::META_CREDIT    => [ 'IPTC:Credit', 'XMP:Credit' ],
			self::META_KEYWORDS  => [ 'IPTC:Keywords', 'XMP:Subject' ],
			self::META_DATE      => [ 'ExifIFD:DateTimeOriginal', 'IPTC:DateCreated', 'XMP:DateCreated', 'IFD0:DateTime' ],
			self::META_CITY      => [ 'IPTC:City', 'XMP:City' ],
			self::META_STATE     => [ 'IPTC:Province-State', 'XMP:State' ],
			self::META_COUNTRY   => [ 'IPTC:Country-PrimaryLocationName', 'XMP:Country' ],
			self::META_RATING    => [ 'XMP:Rating' ],
		];

		foreach ( $meta_import as $meta_key => $candidates ) {
			$existing = get_post_meta( $attachment_id, $meta_key, true );
			if ( '' !== (string) $existing ) {
				continue; // Preserve existing user-set value.
			}

			$value = $pick( $candidates );
			if ( '' === $value ) {
				continue;
			}

			if ( self::META_RATING === $meta_key ) {
				update_post_meta( $attachment_id, $meta_key, min( 5, max( 0, (int) $value ) ) );
			} elseif ( self::META_DATE === $meta_key ) {
				update_post_meta( $attachment_id, $meta_key, self::normalise_date( $value ) );
			} else {
				update_post_meta( $attachment_id, $meta_key, sanitize_text_field( $value ) );
			}
		}

		// WordPress native fields.
		$post = get_post( $attachment_id );
		if ( ! $post ) {
			return;
		}

		$native_updates = [];

		// post_title: replace if the embedded title exists AND the current title
		// looks like WP's auto-generated filename default (not a user-typed value).
		$embedded_title = $pick( [ 'IPTC:ObjectName', 'XMP:Title', 'IFD0:Title' ] );
		if ( '' !== $embedded_title ) {
			$auto_title = str_replace( [ '-', '_' ], ' ', preg_replace( '/\.[^.]+$/', '', basename( $file ) ) );
			if ( '' === trim( $post->post_title )
				|| strtolower( trim( $post->post_title ) ) === strtolower( trim( $auto_title ) ) ) {
				$native_updates['post_title'] = sanitize_text_field( $embedded_title );
			}
		}

		// post_content (Description): import if empty.
		if ( '' === trim( $post->post_content ) ) {
			$v = $pick( [ 'IPTC:Caption-Abstract', 'XMP:Description', 'IFD0:ImageDescription' ] );
			if ( '' !== $v ) {
				$native_updates['post_content'] = sanitize_textarea_field( $v );
			}
		}

		// post_excerpt (Caption): import if empty and distinct from description.
		if ( '' === trim( $post->post_excerpt ) ) {
			$v = $pick( [ 'XMP:Caption' ] );
			if ( '' !== $v && $v !== ( $native_updates['post_content'] ?? '' ) ) {
				$native_updates['post_excerpt'] = sanitize_text_field( $v );
			}
		}

		if ( ! empty( $native_updates ) ) {
			$native_updates['ID'] = $attachment_id;
			wp_update_post( $native_updates );
		}

		// Alt text — stored as _wp_attachment_image_alt (WordPress standard postmeta).
		if ( '' === (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ) ) {
			$v = $pick( [ 'XMP:AltTextAccessibility' ] );
			if ( '' !== $v ) {
				update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( $v ) );
			}
		}
	}

	// -----------------------------------------------------------------------
	// Building the metadata payload for a job file
	// -----------------------------------------------------------------------

	/**
	 * Assemble all metadata values for a given attachment.
	 * This becomes the `metadata` key in the job JSON the daemon reads.
	 *
	 * @param int $attachment_id WordPress attachment ID.
	 * @return array<string, string>
	 */
	public static function get_fields_for_job( int $attachment_id ): array {
		$post = get_post( $attachment_id );
		if ( ! $post ) {
			return [];
		}

		$meta = static fn( string $key ): string =>
			(string) get_post_meta( $attachment_id, $key, true );

		return array_filter( [
			// WordPress native.
			'Title'       => $post->post_title,
			'Description' => $post->post_content,
			'Caption'     => $post->post_excerpt,
			'AltText'     => $meta( '_wp_attachment_image_alt' ),
			// Per-image attribution.
			'Creator'     => $meta( self::META_CREATOR ),
			'Copyright'   => $meta( self::META_COPYRIGHT ),
			'Owner'       => $meta( self::META_OWNER ),
			// Site provenance — neutral, never asserts authorship or copyright.
			'Publisher'   => get_bloginfo( 'name' ),
			'Website'     => home_url(),
			// Editorial.
			'Headline'    => $meta( self::META_HEADLINE ),
			'Credit'      => $meta( self::META_CREDIT ),
			// Classification.
			'Keywords'    => $meta( self::META_KEYWORDS ),
			'DateCreated' => $meta( self::META_DATE ),
			'Rating'      => $meta( self::META_RATING ),
			// Location.
			'City'        => $meta( self::META_CITY ),
			'State'       => $meta( self::META_STATE ),
			'Country'     => $meta( self::META_COUNTRY ),
		] );
	}

	// -----------------------------------------------------------------------
	// WordPress attachment edit screen fields
	// -----------------------------------------------------------------------

	/**
	 * attachment_fields_to_edit filter: render custom metadata fields grouped
	 * into sections (Attribution & Rights, Editorial, Classification, Location).
	 *
	 * @param  array    $form_fields Existing fields array.
	 * @param  \WP_Post $post        Attachment post object.
	 * @return array
	 */
	public static function register_fields( array $form_fields, \WP_Post $post ): array {
		if ( ! wp_attachment_is_image( $post->ID ) ) {
			return $form_fields;
		}

		$id = $post->ID;
		$h4 = static fn( string $label, string $sub = '' ): string =>
			'<h4 style="margin:1.2em 0 .3em;color:#1a2233;border-bottom:2px solid #2d8cf0;padding-bottom:4px;">'
			. esc_html( $label )
			. ( $sub ? ' <small style="font-weight:400;color:#888;font-size:.85em;">' . esc_html( $sub ) . '</small>' : '' )
			. '</h4>';

		// --- Attribution & Rights ---
		$form_fields['mm_section_attribution'] = [ 'label' => '', 'input' => 'html', 'html' =>
			$h4( __( 'Attribution & Rights', 'metamanager' ), __( '(per-image only — never set in bulk)', 'metamanager' ) ) ];

		$form_fields[ self::META_CREATOR ] = [
			'label' => esc_html__( 'Creator', 'metamanager' ),
			'input' => 'text',
			'value' => (string) get_post_meta( $id, self::META_CREATOR, true ),
			'helps' => esc_html__( 'Original creator/photographer. → EXIF:Artist, IPTC:By-line, XMP:Creator', 'metamanager' ),
		];

		$form_fields[ self::META_COPYRIGHT ] = [
			'label' => esc_html__( 'Copyright', 'metamanager' ),
			'input' => 'text',
			'value' => (string) get_post_meta( $id, self::META_COPYRIGHT, true ),
			'helps' => esc_html__( 'Copyright notice (e.g. © 2026 Jane Doe). → EXIF:Copyright, IPTC:CopyrightNotice, XMP:Rights', 'metamanager' ),
		];

		$form_fields[ self::META_OWNER ] = [
			'label' => esc_html__( 'Owner', 'metamanager' ),
			'input' => 'text',
			'value' => (string) get_post_meta( $id, self::META_OWNER, true ),
			'helps' => esc_html__( 'Current rights holder or asset owner. → EXIF:OwnerName, XMP:Owner', 'metamanager' ),
		];

		// --- Editorial ---
		$form_fields['mm_section_editorial'] = [ 'label' => '', 'input' => 'html', 'html' =>
			$h4( __( 'Editorial', 'metamanager' ) ) ];

		$form_fields[ self::META_HEADLINE ] = [
			'label' => esc_html__( 'Headline', 'metamanager' ),
			'input' => 'text',
			'value' => (string) get_post_meta( $id, self::META_HEADLINE, true ),
			'helps' => esc_html__( 'Short editorial headline. → IPTC:Headline, XMP:Headline', 'metamanager' ),
		];

		$form_fields[ self::META_CREDIT ] = [
			'label' => esc_html__( 'Credit', 'metamanager' ),
			'input' => 'text',
			'value' => (string) get_post_meta( $id, self::META_CREDIT, true ),
			'helps' => esc_html__( 'Credit line (e.g. agency). → IPTC:Credit, XMP:Credit', 'metamanager' ),
		];

		// --- Classification ---
		$form_fields['mm_section_classify'] = [ 'label' => '', 'input' => 'html', 'html' =>
			$h4( __( 'Classification', 'metamanager' ) ) ];

		$form_fields[ self::META_KEYWORDS ] = [
			'label' => esc_html__( 'Keywords', 'metamanager' ),
			'input' => 'text',
			'value' => (string) get_post_meta( $id, self::META_KEYWORDS, true ),
			'helps' => esc_html__( 'Separate with semicolons (e.g. nature; landscape). → IPTC:Keywords, XMP:Subject', 'metamanager' ),
		];

		$form_fields[ self::META_RATING ] = [
			'label' => esc_html__( 'Rating', 'metamanager' ),
			'input' => 'html',
			'html'  => self::rating_field_html( $id, (string) get_post_meta( $id, self::META_RATING, true ) ),
			'helps' => esc_html__( '0 = unrated, 1–5 stars. → XMP:Rating', 'metamanager' ),
		];

		$form_fields[ self::META_DATE ] = [
			'label' => esc_html__( 'Date Created', 'metamanager' ),
			'input' => 'html',
			'html'  => sprintf(
				'<input type="date" id="attachments-%1$d-mm_date_created" name="attachments[%1$d][mm_date_created]" value="%2$s" class="widefat">',
				absint( $id ),
				esc_attr( (string) get_post_meta( $id, self::META_DATE, true ) )
			),
			'helps' => esc_html__( 'Date originally created/captured. → EXIF:DateTimeOriginal, IPTC:DateCreated, XMP:DateCreated', 'metamanager' ),
		];

		// --- Location ---
		$form_fields['mm_section_location'] = [ 'label' => '', 'input' => 'html', 'html' =>
			$h4( __( 'Location', 'metamanager' ), __( '(IPTC Photo Metadata Standard)', 'metamanager' ) ) ];

		$form_fields[ self::META_CITY ] = [
			'label' => esc_html__( 'City', 'metamanager' ),
			'input' => 'text',
			'value' => (string) get_post_meta( $id, self::META_CITY, true ),
			'helps' => esc_html__( '→ IPTC:City, XMP:City', 'metamanager' ),
		];

		$form_fields[ self::META_STATE ] = [
			'label' => esc_html__( 'State / Province', 'metamanager' ),
			'input' => 'text',
			'value' => (string) get_post_meta( $id, self::META_STATE, true ),
			'helps' => esc_html__( '→ IPTC:Province-State, XMP:State', 'metamanager' ),
		];

		$form_fields[ self::META_COUNTRY ] = [
			'label' => esc_html__( 'Country', 'metamanager' ),
			'input' => 'text',
			'value' => (string) get_post_meta( $id, self::META_COUNTRY, true ),
			'helps' => esc_html__( '→ IPTC:Country-PrimaryLocationName, XMP:Country', 'metamanager' ),
		];

		return $form_fields;
	}

	// -----------------------------------------------------------------------
	// Saving custom fields
	// -----------------------------------------------------------------------

	/**
	 * attachment_fields_to_save filter: persist all custom meta and enqueue
	 * metadata jobs for all image sizes.
	 *
	 * WordPress handles native post fields (title, description, caption) via
	 * its own form handler. This filter only touches our custom meta keys.
	 *
	 * @param  array $post       Post array (mutable, returned to WP).
	 * @param  array $attachment Submitted field values.
	 * @return array
	 */
	public static function on_fields_save( array $post, array $attachment ): array {
		if ( empty( $post['ID'] ) || ! wp_attachment_is_image( $post['ID'] ) ) {
			return $post;
		}

		$id = (int) $post['ID'];

		$string_fields = [
			self::META_CREATOR, self::META_COPYRIGHT, self::META_OWNER,
			self::META_HEADLINE, self::META_CREDIT, self::META_KEYWORDS,
			self::META_DATE, self::META_CITY, self::META_STATE, self::META_COUNTRY,
		];

		foreach ( $string_fields as $key ) {
			if ( array_key_exists( $key, $attachment ) ) {
				$value = sanitize_text_field( $attachment[ $key ] );
				if ( self::META_DATE === $key && '' !== $value ) {
					$value = self::normalise_date( $value );
				}
				update_post_meta( $id, $key, $value );
			}
		}

		if ( array_key_exists( self::META_RATING, $attachment ) ) {
			update_post_meta( $id, self::META_RATING, min( 5, max( 0, (int) $attachment[ self::META_RATING ] ) ) );
		}

		// Enqueue metadata embedding jobs — do NOT compress on edit.
		MM_Job_Queue::enqueue_all_sizes( $id, [], 'metadata', [ 'trigger' => 'edit' ] );

		return $post;
	}

	// -----------------------------------------------------------------------
	// Reading embedded metadata from file (live ExifTool display pane)
	// -----------------------------------------------------------------------

	/**
	 * Read all embedded metadata from an image using ExifTool.
	 * Returns a flat "Group:Tag" => value map for the admin display pane.
	 *
	 * @param  string $file_path Absolute path to the image.
	 * @return array<string, mixed>  Empty if ExifTool is unavailable or fails.
	 */
	public static function read_embedded( string $file_path ): array {
		if ( ! MM_Status::exiftool_available() ) {
			return [];
		}

		if ( ! file_exists( $file_path ) ) {
			return [];
		}

		$exiftool = MM_Status::exiftool_path();
		// -a  : extract duplicate tags (e.g. multiple keyword entries)
		// -G1 : include group name prefix for disambiguation
		// -s  : tag names not descriptions
		// -j  : JSON output
		$cmd = escapeshellcmd( $exiftool ) . ' -a -G1 -s -j ' . escapeshellarg( $file_path ) . ' 2>/dev/null';

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_shell_exec
		$json = shell_exec( $cmd );
		if ( ! $json ) {
			return [];
		}

		$arr = json_decode( $json, true );
		if ( ! is_array( $arr ) || empty( $arr[0] ) ) {
			return [];
		}

		// Remove ExifTool housekeeping keys that confuse users in the display pane.
		foreach ( [ 'ExifTool:ExifToolVersion', 'File:FileName', 'File:Directory',
			'File:FilePermissions', 'File:FileAccessDate',
			'File:FileModifyDate', 'File:FileInodeChangeDate' ] as $k ) {
			unset( $arr[0][ $k ] );
		}

		return $arr[0];
	}

	// -----------------------------------------------------------------------
	// Helpers
	// -----------------------------------------------------------------------

	/**
	 * Render a 0–5 star rating <select> for the attachment edit screen.
	 *
	 * @param  int    $attachment_id
	 * @param  string $current_value
	 * @return string HTML
	 */
	private static function rating_field_html( int $attachment_id, string $current_value ): string {
		$options = [
			0 => __( '0 — Unrated', 'metamanager' ),
			1 => '★',
			2 => '★★',
			3 => '★★★',
			4 => '★★★★',
			5 => '★★★★★',
		];
		$html = sprintf(
			'<select id="attachments-%1$d-mm_rating" name="attachments[%1$d][mm_rating]">',
			absint( $attachment_id )
		);
		foreach ( $options as $val => $label ) {
			$html .= sprintf(
				'<option value="%d"%s>%s</option>',
				$val,
				selected( (int) $current_value, $val, false ),
				esc_html( $label )
			);
		}
		$html .= '</select>';
		return $html;
	}

	/**
	 * Normalise a date string from any ExifTool format to YYYY-MM-DD.
	 *
	 * ExifTool may return: "2024:01:15 12:30:00", "2024:01:15", "20240115",
	 * "2024-01-15T12:30:00+00:00", or an HTML date input "2024-01-15".
	 *
	 * @param  string $raw Raw date string.
	 * @return string      "YYYY-MM-DD" or empty string if unparseable.
	 */
	public static function normalise_date( string $raw ): string {
		$d = preg_replace( '/[\sT].*$/', '', trim( $raw ) );  // Strip time.
		$d = str_replace( ':', '-', $d );                       // EXIF colon → hyphen.
		if ( preg_match( '/^(\d{4})(\d{2})(\d{2})$/', $d, $m ) ) {
			$d = "{$m[1]}-{$m[2]}-{$m[3]}";
		}
		return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $d ) ? $d : '';
	}
}
