<?php
/**
 * Metamanager Metadata Class
 *
 * Handles all metadata field definitions, saving custom fields, building job
 * payloads, and reading embedded metadata from files for display.
 *
 * Principles:
 * - Creator, Copyright, Owner are per-image fields — never set by bulk.
 * - Publisher and Website are site provenance fields — safe for bulk injection.
 * - We use one set of meta keys throughout (mm_creator, mm_copyright, mm_owner).
 *
 * @package Metamanager
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class MM_Metadata
 */
class MM_Metadata {

	// -----------------------------------------------------------------------
	// Field definitions
	// -----------------------------------------------------------------------

	/**
	 * Logical field map: PHP key => [ExifTool tag names to write].
	 *
	 * This is the single source of truth. The shell daemon reads the same
	 * logical keys from the job JSON and maps them using an identical
	 * declaration in the bash script.
	 *
	 * @return array<string, string[]>
	 */
	public static function field_map(): array {
		return [
			'Title'       => [ 'Title', 'IPTC:ObjectName', 'XMP:Title' ],
			'Description' => [ 'EXIF:ImageDescription', 'IPTC:Caption-Abstract', 'XMP:Description' ],
			'Caption'     => [ 'IPTC:Caption-Abstract', 'XMP:Caption' ],
			'AltText'     => [ 'XMP:AltTextAccessibility' ],
			'Creator'     => [ 'EXIF:Artist', 'IPTC:By-line', 'XMP:Creator' ],
			'Copyright'   => [ 'EXIF:Copyright', 'IPTC:CopyrightNotice', 'XMP:Rights' ],
			'Owner'       => [ 'XMP:Owner', 'EXIF:OwnerName' ],
			'Publisher'   => [ 'IPTC:Source', 'XMP:Publisher' ],
			'Website'     => [ 'XMP:WebStatement', 'IPTC:Source' ],
		];
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

		// Per-image attribution fields (set individually by editors).
		$creator   = (string) get_post_meta( $attachment_id, 'mm_creator', true );
		$copyright = (string) get_post_meta( $attachment_id, 'mm_copyright', true );
		$owner     = (string) get_post_meta( $attachment_id, 'mm_owner', true );

		// Site provenance fields (neutral — do not assert authorship or rights).
		$publisher = get_bloginfo( 'name' );
		$website   = home_url();

		return array_filter( [
			'Title'       => $post->post_title,
			'Description' => $post->post_content,
			'Caption'     => $post->post_excerpt,
			'AltText'     => (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ),
			'Creator'     => $creator,
			'Copyright'   => $copyright,
			'Owner'       => $owner,
			'Publisher'   => $publisher,
			'Website'     => $website,
		] );
	}

	// -----------------------------------------------------------------------
	// WordPress attachment edit screen fields
	// -----------------------------------------------------------------------

	/**
	 * attachment_fields_to_edit filter: add our custom fields.
	 *
	 * @param array    $form_fields Existing fields array.
	 * @param \WP_Post $post        Attachment post object.
	 * @return array
	 */
	public static function register_fields( array $form_fields, \WP_Post $post ): array {
		if ( ! wp_attachment_is_image( $post->ID ) ) {
			return $form_fields;
		}

		$form_fields['mm_creator'] = [
			'label' => esc_html__( 'Creator', 'metamanager' ),
			'input' => 'text',
			'value' => (string) get_post_meta( $post->ID, 'mm_creator', true ),
			'helps' => esc_html__( 'Original creator or photographer of this image. Embedded in EXIF Artist, IPTC By-line, and XMP Creator.', 'metamanager' ),
		];

		$form_fields['mm_copyright'] = [
			'label' => esc_html__( 'Copyright', 'metamanager' ),
			'input' => 'text',
			'value' => (string) get_post_meta( $post->ID, 'mm_copyright', true ),
			'helps' => esc_html__( 'Copyright notice for this image. Embedded in EXIF Copyright, IPTC CopyrightNotice, and XMP Rights.', 'metamanager' ),
		];

		$form_fields['mm_owner'] = [
			'label' => esc_html__( 'Owner', 'metamanager' ),
			'input' => 'text',
			'value' => (string) get_post_meta( $post->ID, 'mm_owner', true ),
			'helps' => esc_html__( 'Current rights holder or asset owner. Embedded in EXIF OwnerName and XMP Owner.', 'metamanager' ),
		];

		return $form_fields;
	}

	// -----------------------------------------------------------------------
	// Saving custom fields
	// -----------------------------------------------------------------------

	/**
	 * attachment_fields_to_save filter: persist custom fields and enqueue
	 * metadata jobs for all sizes.
	 *
	 * @param array $post       Post array (mutable).
	 * @param array $attachment Submitted field values.
	 * @return array
	 */
	public static function on_fields_save( array $post, array $attachment ): array {
		if ( empty( $post['ID'] ) || ! wp_attachment_is_image( $post['ID'] ) ) {
			return $post;
		}

		$id = (int) $post['ID'];

		$fields = [
			'mm_creator'   => 'mm_creator',
			'mm_copyright' => 'mm_copyright',
			'mm_owner'     => 'mm_owner',
		];

		foreach ( $fields as $form_key => $meta_key ) {
			if ( isset( $attachment[ $form_key ] ) ) {
				update_post_meta( $id, $meta_key, sanitize_text_field( $attachment[ $form_key ] ) );
			}
		}

		// Enqueue metadata embedding jobs — do NOT enqueue compression on edit.
		MM_Job_Queue::enqueue_all_sizes( $id, [], 'metadata', [ 'trigger' => 'edit' ] );

		return $post;
	}

	// -----------------------------------------------------------------------
	// Reading embedded metadata from file (for admin display)
	// -----------------------------------------------------------------------

	/**
	 * Read all embedded metadata from an image using ExifTool.
	 * Returns a flat associative array of Group:Tag => Value for display.
	 *
	 * @param string $file_path Absolute path to the image.
	 * @return array<string, string>  Empty if ExifTool is unavailable or fails.
	 */
	public static function read_embedded( string $file_path ): array {
		if ( ! MM_Status::exiftool_available() ) {
			return [];
		}

		if ( ! file_exists( $file_path ) ) {
			return [];
		}

		$exiftool = MM_Status::exiftool_path();
		$cmd      = escapeshellcmd( $exiftool ) . ' -a -G1 -s -j ' . escapeshellarg( $file_path ) . ' 2>/dev/null';

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_shell_exec
		$json = shell_exec( $cmd );
		if ( ! $json ) {
			return [];
		}

		$arr = json_decode( $json, true );
		if ( ! is_array( $arr ) || empty( $arr[0] ) ) {
			return [];
		}

		// Remove ExifTool system keys (SourceFile etc.) — surfacing them confuses users.
		unset( $arr[0]['ExifTool:ExifToolVersion'], $arr[0]['File:FileName'], $arr[0]['File:Directory'] );

		return $arr[0];
	}
}
