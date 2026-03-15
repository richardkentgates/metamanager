<?php
/**
 * Upload Receipt Notifications
 *
 * Sends email receipts to the uploading user and the admin whenever one or
 * more images are added to the Media Library.  Multiple files uploaded in
 * quick succession are batched into a single email: a one-time WP-Cron event
 * fires 60 seconds after the first attachment in a batch, collects everything
 * queued by then, and dispatches the receipts.
 *
 * If wp_mail() returns false, the batch is persisted to the
 * mm_failed_upload_notices option so the admin can retry from the admin panel.
 *
 * @package Metamanager
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class MM_Upload_Notify
 */
class MM_Upload_Notify {

	/** Transient that accumulates attachment IDs during the 60-second batch window. */
	const BATCH_TRANSIENT = 'mm_upload_batch';

	/** WP-Cron event name. */
	const CRON_EVENT = 'mm_send_upload_receipt';

	/** Batch window in seconds — cron fires this long after the first upload. */
	const BATCH_DELAY = 60;

	/** Option name that stores failed notification records. */
	const FAILED_OPTION = 'mm_failed_upload_notices';

	// -----------------------------------------------------------------------
	// Boot
	// -----------------------------------------------------------------------

	public static function init(): void {
		// Queue each raw upload into the current batch.
		add_action( 'add_attachment', [ __CLASS__, 'on_attachment_added' ] );

		// Cron: send accumulated batch.
		add_action( self::CRON_EVENT, [ __CLASS__, 'send_batch' ] );

		// Admin UI: show failed-notice warnings with Retry button.
		add_action( 'admin_notices', [ __CLASS__, 'show_failed_notices' ] );

		// AJAX: retry a failed notification entry.
		add_action( 'wp_ajax_mm_retry_upload_notice', [ __CLASS__, 'ajax_retry_notice' ] );

		// AJAX: dismiss (delete without retry) a failed notification entry.
		add_action( 'wp_ajax_mm_dismiss_upload_notice', [ __CLASS__, 'ajax_dismiss_notice' ] );

		// Per-user receipt preference on the user profile page.
		add_action( 'show_user_profile',        [ __CLASS__, 'render_profile_field' ] );
		add_action( 'edit_user_profile',        [ __CLASS__, 'render_profile_field' ] );
		add_action( 'personal_options_update',  [ __CLASS__, 'save_profile_field' ] );
		add_action( 'edit_user_profile_update', [ __CLASS__, 'save_profile_field' ] );
	}

	// -----------------------------------------------------------------------
	// Upload hook
	// -----------------------------------------------------------------------

	/**
	 * Called for every new attachment. Appends to the batch transient and
	 * schedules the send-cron if it hasn't been scheduled yet.
	 *
	 * @param int $attachment_id The new attachment post ID.
	 */
	public static function on_attachment_added( int $attachment_id ): void {
		$user_id = get_current_user_id();

		// Read current batch (may not yet exist).
		$batch = get_transient( self::BATCH_TRANSIENT );
		if ( ! is_array( $batch ) ) {
			$batch = [];
		}

		// Append this item.
		$batch[] = [
			'attachment_id' => $attachment_id,
			'user_id'       => $user_id,
			'added_at'      => time(),
		];

		// Persist with a TTL long enough to survive the batch window.
		set_transient( self::BATCH_TRANSIENT, $batch, self::BATCH_DELAY + 120 );

		// Schedule the send-cron exactly once per batch (first upload wins).
		if ( ! wp_next_scheduled( self::CRON_EVENT ) ) {
			wp_schedule_single_event( time() + self::BATCH_DELAY, self::CRON_EVENT );
		}
	}

	// -----------------------------------------------------------------------
	// Cron handler
	// -----------------------------------------------------------------------

	/**
	 * Read the accumulated batch, send receipt emails, and clear the transient.
	 * Called by WP-Cron ~60 seconds after the first upload in each batch.
	 */
	public static function send_batch(): void {
		$batch = get_transient( self::BATCH_TRANSIENT );
		delete_transient( self::BATCH_TRANSIENT );

		if ( ! is_array( $batch ) || empty( $batch ) ) {
			return;
		}

		$site_name  = wp_specialchars_decode( (string) get_option( 'blogname', 'WordPress' ), ENT_QUOTES );
		$site_url   = home_url();
		$admin_url  = admin_url( 'upload.php' );
		$admin_mail = (string) get_option( 'admin_email', '' );

		// Group items by user_id so each uploader gets their own section.
		$by_user = [];
		foreach ( $batch as $item ) {
			$uid = (int) ( $item['user_id'] ?? 0 );
			$by_user[ $uid ][] = (int) $item['attachment_id'];
		}

		$all_ids = array_merge( ...array_values( $by_user ) );

		// -----------------------------------------------------------------
		// 1. Email to each individual uploader (non-admin uploaders only;
		//    admin gets a combined email below to avoid duplicates).
		// -----------------------------------------------------------------
		foreach ( $by_user as $uid => $ids ) {
			$user = $uid > 0 ? get_userdata( $uid ) : false;
			if ( ! $user || ! $user->user_email ) {
				continue;
			}
			// If this user is the admin address, they'll get the admin email — skip.
			if ( strtolower( $user->user_email ) === strtolower( $admin_mail ) ) {
				continue;
			}

			// Respect the user's per-profile opt-out preference.
			if ( ! self::user_wants_receipt( $uid ) ) {
				continue;
			}

			$subject = sprintf(
				/* translators: 1: site name */
				__( '[%s] Upload Receipt — your images have been received', 'metamanager' ),
				$site_name
			);

			$body = self::build_receipt_body(
				$ids,
				sprintf(
					/* translators: 1: display name */
					__( 'Hi %s,', 'metamanager' ),
					$user->display_name
				),
				__( 'The following images you uploaded are now in the Media Library:', 'metamanager' ),
				$admin_url,
				$site_url
			);

			$result = self::send_mail( $user->user_email, $subject, $body );

			if ( ! $result ) {
				self::record_failure( $user->user_email, $subject, $body, $ids, $uid );
			}
		}

		// -----------------------------------------------------------------
		// 2. Combined email to the admin covering ALL uploads.
		// -----------------------------------------------------------------
		if ( $admin_mail ) {
			$subject = sprintf(
				/* translators: 1: site name, 2: count */
				_n(
					'[%1$s] Upload Receipt — %2$d image uploaded',
					'[%1$s] Upload Receipt — %2$d images uploaded',
					count( $all_ids ),
					'metamanager'
				),
				$site_name,
				count( $all_ids )
			);

			// Build body with per-user grouping for the admin.
			$intro_lines = [ __( 'The following images were uploaded to the Media Library:', 'metamanager' ), '' ];
			foreach ( $by_user as $uid => $ids ) {
				$user        = $uid > 0 ? get_userdata( $uid ) : false;
				$uploader    = $user ? $user->display_name . ' <' . $user->user_email . '>' : __( '(unknown user)', 'metamanager' );
				$intro_lines[] = sprintf( __( 'Uploaded by: %s', 'metamanager' ), $uploader );
				foreach ( $ids as $id ) {
					$title = html_entity_decode( (string) get_the_title( $id ), ENT_QUOTES );
					$url   = wp_get_attachment_url( $id );
					$intro_lines[] = '  • ' . ( $title ?: '#' . $id ) . ( $url ? ' — ' . $url : '' );
				}
				$intro_lines[] = '';
			}
			$intro_lines[] = __( 'View Media Library:', 'metamanager' ) . ' ' . $admin_url;
			$intro_lines[] = '';
			$intro_lines[] = '— ' . $site_name . ' · ' . $site_url;

			$body = implode( "\n", $intro_lines );

			$result = self::send_mail( $admin_mail, $subject, $body );

			if ( ! $result ) {
				self::record_failure( $admin_mail, $subject, $body, $all_ids, 0 );
			}
		}

		// -----------------------------------------------------------------
		// 3. Any additional CCs configured in settings.
		// -----------------------------------------------------------------
		$extra = MM_Settings::get_upload_notify_extra_emails();
		if ( ! empty( $extra ) ) {
			$subject = sprintf(
				_n(
					'[%1$s] Upload Receipt — %2$d image uploaded',
					'[%1$s] Upload Receipt — %2$d images uploaded',
					count( $all_ids ),
					'metamanager'
				),
				$site_name,
				count( $all_ids )
			);

			$body_lines = [ __( 'The following images were uploaded to the Media Library:', 'metamanager' ), '' ];
			foreach ( $all_ids as $id ) {
				$title = html_entity_decode( (string) get_the_title( $id ), ENT_QUOTES );
				$url   = wp_get_attachment_url( $id );
				$body_lines[] = '  • ' . ( $title ?: '#' . $id ) . ( $url ? ' — ' . $url : '' );
			}
			$body_lines[] = '';
			$body_lines[] = __( 'View Media Library:', 'metamanager' ) . ' ' . $admin_url;
			$body_lines[] = '';
			$body_lines[] = '— ' . $site_name . ' · ' . $site_url;

			$body = implode( "\n", $body_lines );

			foreach ( $extra as $email ) {
				$result = self::send_mail( $email, $subject, $body );
				if ( ! $result ) {
					self::record_failure( $email, $subject, $body, $all_ids, 0 );
				}
			}
		}
	}

	// -----------------------------------------------------------------------
	// Mail helper
	// -----------------------------------------------------------------------

	/**
	 * Thin wrapper around wp_mail() that catches PHP-level errors.
	 *
	 * @param string $to      Recipient.
	 * @param string $subject Subject.
	 * @param string $body    Plain-text body.
	 * @return bool True on success.
	 */
	private static function send_mail( string $to, string $subject, string $body ): bool {
		try {
			$sent = wp_mail( $to, $subject, $body );
		} catch ( \Throwable $e ) {
			error_log( '[Metamanager] wp_mail() threw an exception: ' . $e->getMessage() );
			$sent = false;
		}
		return (bool) $sent;
	}

	// -----------------------------------------------------------------------
	// Failure persistence
	// -----------------------------------------------------------------------

	/**
	 * Write a failed email attempt to the persistent failures option.
	 *
	 * @param string $to             Recipient.
	 * @param string $subject        Subject line.
	 * @param string $body           Email body.
	 * @param int[]  $attachment_ids Attachment IDs in this batch.
	 * @param int    $user_id        Uploader user ID (0 = admin/combined email).
	 */
	private static function record_failure(
		string $to,
		string $subject,
		string $body,
		array $attachment_ids,
		int $user_id
	): void {
		$failures = self::get_failed_notices();

		$key = md5( $to . $subject . implode( ',', $attachment_ids ) . (string) time() );

		$failures[ $key ] = [
			'key'            => $key,
			'to'             => $to,
			'subject'        => $subject,
			'body'           => $body,
			'attachment_ids' => $attachment_ids,
			'user_id'        => $user_id,
			'failed_at'      => time(),
			'retry_count'    => 0,
		];

		update_option( self::FAILED_OPTION, $failures, false );

		error_log( '[Metamanager] Upload receipt email failed for ' . $to . ' — stored for retry.' );
	}

	// -----------------------------------------------------------------------
	// Admin notices for failed emails
	// -----------------------------------------------------------------------

	/**
	 * Render an admin notice for each recorded failed upload notification.
	 * Only shown to admins (manage_options).
	 */
	public static function show_failed_notices(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$failures = self::get_failed_notices();
		if ( empty( $failures ) ) {
			return;
		}

		foreach ( $failures as $key => $entry ) {
			$count   = count( (array) ( $entry['attachment_ids'] ?? [] ) );
			$to      = esc_html( $entry['to'] ?? '' );
			$retries = (int) ( $entry['retry_count'] ?? 0 );
			$date    = wp_date(
				get_option( 'date_format' ) . ' ' . get_option( 'time_format' ),
				(int) ( $entry['failed_at'] ?? time() )
			);

			$retry_nonce   = wp_create_nonce( 'mm_retry_upload_notice_' . $key );
			$dismiss_nonce = wp_create_nonce( 'mm_dismiss_upload_notice_' . $key );

			printf(
				'<div class="notice notice-error" id="mm-upload-fail-%s" style="display:flex;align-items:flex-start;gap:16px;padding:.75em 1em;">
					<div style="flex:1;">
						<p style="margin:.25em 0;"><strong>%s</strong> %s</p>
						<p style="margin:.25em 0;font-size:12px;color:#888;">%s &bull; %s &bull; %s</p>
					</div>
					<div style="display:flex;gap:8px;align-items:center;padding-top:.25em;">
						<button class="button button-primary mm-retry-upload-notice"
								data-key="%s" data-nonce="%s">%s</button>
						<button class="button mm-dismiss-upload-notice"
								data-key="%s" data-nonce="%s">%s</button>
					</div>
				</div>',
				esc_attr( $key ),
				esc_html__( '[Metamanager] Upload receipt email failed:', 'metamanager' ),
				sprintf(
					/* translators: 1: recipient, 2: image count */
					esc_html__( 'Could not send receipt to %1$s for %2$d image(s).', 'metamanager' ),
					'<strong>' . $to . '</strong>',
					$count
				),
				sprintf( esc_html__( 'Failed: %s', 'metamanager' ), esc_html( $date ) ),
				sprintf( esc_html__( 'Retries: %d', 'metamanager' ), $retries ),
				esc_html__( 'The email will not be re-attempted automatically.', 'metamanager' ),
				esc_attr( $key ), esc_attr( $retry_nonce ),   esc_html__( 'Retry', 'metamanager' ),
				esc_attr( $key ), esc_attr( $dismiss_nonce ), esc_html__( 'Dismiss', 'metamanager' )
			);
		}

		// Inline JS — only output if there's at least one failed notice.
		?>
		<script>
		jQuery(function($){
			$(document).on('click', '.mm-retry-upload-notice', function(){
				var btn = $(this);
				var key = btn.data('key');
				var nonce = btn.data('nonce');
				btn.prop('disabled', true).text('<?php echo esc_js( __( 'Retrying…', 'metamanager' ) ); ?>');
				$.post(ajaxurl, {action: 'mm_retry_upload_notice', key: key, nonce: nonce}, function(resp){
					if (resp.success) {
						$('#mm-upload-fail-' + key).slideUp(300, function(){ $(this).remove(); });
					} else {
						btn.prop('disabled', false).text('<?php echo esc_js( __( 'Retry', 'metamanager' ) ); ?>');
						btn.after('<span style="color:#d63638;font-size:12px;margin-left:4px;">' + (resp.data || '<?php echo esc_js( __( 'Still failed.', 'metamanager' ) ); ?>') + '</span>');
					}
				}, 'json');
			});

			$(document).on('click', '.mm-dismiss-upload-notice', function(){
				var btn = $(this);
				var key = btn.data('key');
				var nonce = btn.data('nonce');
				btn.prop('disabled', true);
				$.post(ajaxurl, {action: 'mm_dismiss_upload_notice', key: key, nonce: nonce}, function(){
					$('#mm-upload-fail-' + key).slideUp(300, function(){ $(this).remove(); });
				}, 'json');
			});
		});
		</script>
		<?php
	}

	// -----------------------------------------------------------------------
	// AJAX: retry
	// -----------------------------------------------------------------------

	/**
	 * Re-attempt sending a single failed notification entry.
	 */
	public static function ajax_retry_notice(): void {
		$key = sanitize_key( $_POST['key'] ?? '' );
		check_ajax_referer( 'mm_retry_upload_notice_' . $key, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'metamanager' ) );
		}

		$failures = self::get_failed_notices();
		if ( ! isset( $failures[ $key ] ) ) {
			// Already gone — treat as success.
			wp_send_json_success();
		}

		$entry = $failures[ $key ];
		$sent  = self::send_mail(
			(string) ( $entry['to'] ?? '' ),
			(string) ( $entry['subject'] ?? '' ),
			(string) ( $entry['body'] ?? '' )
		);

		if ( $sent ) {
			unset( $failures[ $key ] );
			update_option( self::FAILED_OPTION, $failures, false );
			wp_send_json_success();
		}

		// Still failing — increment retry count.
		$failures[ $key ]['retry_count'] = ( (int) ( $failures[ $key ]['retry_count'] ?? 0 ) ) + 1;
		update_option( self::FAILED_OPTION, $failures, false );

		wp_send_json_error( __( 'Email still could not be delivered. Check your site\'s email configuration.', 'metamanager' ) );
	}

	// -----------------------------------------------------------------------
	// AJAX: dismiss
	// -----------------------------------------------------------------------

	/**
	 * Delete a failed notification entry without retrying.
	 */
	public static function ajax_dismiss_notice(): void {
		$key = sanitize_key( $_POST['key'] ?? '' );
		check_ajax_referer( 'mm_dismiss_upload_notice_' . $key, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'metamanager' ) );
		}

		$failures = self::get_failed_notices();
		unset( $failures[ $key ] );
		update_option( self::FAILED_OPTION, $failures, false );

		wp_send_json_success();
	}

	// -----------------------------------------------------------------------
	// Body builder
	// -----------------------------------------------------------------------

	/**
	 * Build the plain-text body for a per-user receipt email.
	 *
	 * @param int[]  $ids        Attachment IDs.
	 * @param string $greeting   First line.
	 * @param string $intro      Second line.
	 * @param string $admin_url  Link to the Media Library.
	 * @param string $site_url   Site URL.
	 * @return string
	 */
	private static function build_receipt_body(
		array $ids,
		string $greeting,
		string $intro,
		string $admin_url,
		string $site_url
	): string {
		$site_name = wp_specialchars_decode( (string) get_option( 'blogname', 'WordPress' ), ENT_QUOTES );
		$lines     = [ $greeting, '', $intro, '' ];
		foreach ( $ids as $id ) {
			$title   = html_entity_decode( (string) get_the_title( $id ), ENT_QUOTES );
			$url     = wp_get_attachment_url( $id );
			$lines[] = '  • ' . ( $title ?: '#' . $id ) . ( $url ? ' — ' . $url : '' );
		}
		$lines[] = '';
		$lines[] = __( 'View your Media Library:', 'metamanager' ) . ' ' . $admin_url;
		$lines[] = '';
		$lines[] = '— ' . $site_name . ' · ' . $site_url;
		return implode( "\n", $lines );
	}

	// -----------------------------------------------------------------------
	// Per-user receipt preference (user profile field)
	// -----------------------------------------------------------------------

	/** User-meta key that stores the per-user receipt preference. */
	const META_USER_RECEIPT = 'mm_upload_receipt';

	/**
	 * Whether a given user wants to receive upload receipt emails.
	 * Defaults to true (opted in) when no preference has been saved yet.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return bool
	 */
	public static function user_wants_receipt( int $user_id ): bool {
		$value = get_user_meta( $user_id, self::META_USER_RECEIPT, true );
		// Empty string means never saved — default to opted in.
		if ( '' === $value ) {
			return true;
		}
		return (bool) $value;
	}

	/**
	 * Render the opt-in/out checkbox on the user profile edit page.
	 *
	 * @param \WP_User $user The user being edited.
	 */
	public static function render_profile_field( \WP_User $user ): void {
		$checked = self::user_wants_receipt( $user->ID );
		?>
		<h2><?php esc_html_e( 'Metamanager', 'metamanager' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Upload receipts', 'metamanager' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="mm_upload_receipt" value="1"<?php checked( $checked ); ?>>
						<?php esc_html_e( 'Send me an email receipt when I upload images to the Media Library', 'metamanager' ); ?>
					</label>
					<p class="description"><?php esc_html_e( 'Multiple files uploaded within 60 seconds are batched into one email.', 'metamanager' ); ?></p>
					<?php wp_nonce_field( 'mm_upload_receipt_' . $user->ID, 'mm_upload_receipt_nonce' ); ?>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Save the per-user receipt preference submitted from the profile page.
	 *
	 * @param int $user_id The user being updated.
	 */
	public static function save_profile_field( int $user_id ): void {
		if ( ! isset( $_POST['mm_upload_receipt_nonce'] ) ) {
			return;
		}
		check_admin_referer( 'mm_upload_receipt_' . $user_id, 'mm_upload_receipt_nonce' );

		if ( ! current_user_can( 'edit_user', $user_id ) ) {
			return;
		}

		$wants = isset( $_POST['mm_upload_receipt'] ) && '1' === $_POST['mm_upload_receipt'];
		update_user_meta( $user_id, self::META_USER_RECEIPT, $wants ? '1' : '0' );
	}

	// -----------------------------------------------------------------------
	// Getters (public API for other classes)
	// -----------------------------------------------------------------------

	/**
	 * Returns the current array of failed notification entries.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function get_failed_notices(): array {
		$value = get_option( self::FAILED_OPTION, [] );
		return is_array( $value ) ? $value : [];
	}
}
