<?php
/**
 * Plugin Name:       All-IN Settings Transfer for WooCommerce
 * Plugin URI:        https://a-in.co.il/plugins/settings-transfer/
 * Description:       Export and import WooCommerce settings (woocommerce_settings and woocommerce_*_settings) as JSON, with a short report.
 * Version:           1.4.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Requires Plugins:  woocommerce
 * Author:            Eli Shvarzman
 * Author URI:        https://a-in.co.il/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       all-in-settings-transfer-for-woocommerce
 * Domain Path:       /languages
 *
 * @package AINST_Settings_Transfer
 */

defined( 'ABSPATH' ) || exit;

// Declare HPOS compatibility. The plugin only reads and writes options, never orders.
add_action( 'before_woocommerce_init', function() {
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
	}
} );

/**
 * Exports and imports WooCommerce settings.
 *
 * Security model (1.3.0):
 * - Only administrators (manage_options) can open the screen, export or import.
 *   Exports can contain payment gateway credentials, so they are admin-only too.
 * - Import only writes options on an allowlist: "woocommerce_settings" and
 *   "woocommerce_<tab>_settings". Every other key in the file is skipped and
 *   reported. Values must be scalars or arrays (no objects) and strings are
 *   run through wp_kses_post().
 * - Every action is protected by a nonce and by a capability check.
 */
class AINST_Settings_Transfer {

	const CAPABILITY    = 'manage_options';
	const PAGE_SLUG     = 'ainst-settings-transfer';
	const MAX_FILE_SIZE = 2097152; // 2 MB.

	/**
	 * Hook the plugin.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_admin_page' ) );
		add_action( 'admin_init', array( $this, 'maybe_handle_action' ) );
	}

	/**
	 * Is this option name allowed to be exported / imported?
	 *
	 * @param string $name Option name.
	 * @return bool
	 */
	public static function is_allowed_option( $name ) {
		$allowed = is_string( $name )
			&& ( 'woocommerce_settings' === $name || 1 === preg_match( '/^woocommerce_[a-z0-9_]+_settings$/', $name ) );

		/**
		 * Filter whether an option may be imported or exported.
		 *
		 * @param bool   $allowed Allowed by the built-in allowlist.
		 * @param string $name    Option name.
		 */
		return (bool) apply_filters( 'ainst_is_allowed_option', $allowed, $name );
	}

	/**
	 * Register the admin screen under WooCommerce.
	 */
	public function add_admin_page() {
		add_submenu_page(
			'woocommerce',
			__( 'Settings Transfer', 'all-in-settings-transfer-for-woocommerce' ),
			__( 'Settings Transfer', 'all-in-settings-transfer-for-woocommerce' ),
			self::CAPABILITY,
			self::PAGE_SLUG,
			array( $this, 'admin_page_html' )
		);
	}

	/**
	 * Handle the export / import form posts.
	 */
	public function maybe_handle_action() {
		if ( empty( $_POST['ainst_action'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Only checks which form was sent; each branch verifies its own nonce below.
			return;
		}
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Insufficient permissions', 'all-in-settings-transfer-for-woocommerce' ), '', array( 'response' => 403 ) );
		}
		$action = sanitize_key( wp_unslash( $_POST['ainst_action'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified per action below.

		if ( 'export' === $action ) {
			check_admin_referer( 'ainst_export', 'ainst_export_nonce' );
			$this->export_settings();
		} elseif ( 'import' === $action ) {
			check_admin_referer( 'ainst_import', 'ainst_import_nonce' );
			$this->import_settings();
		}
	}

	/**
	 * Render the admin screen.
	 */
	public function admin_page_html() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}
		$notice = get_transient( $this->notice_key() );
		if ( $notice ) {
			delete_transient( $this->notice_key() );
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'All-IN Settings Transfer for WooCommerce', 'all-in-settings-transfer-for-woocommerce' ); ?></h1>
			<?php if ( is_array( $notice ) && ! empty( $notice['message'] ) ) : ?>
				<div class="notice notice-<?php echo esc_attr( 'error' === $notice['type'] ? 'error' : 'success' ); ?> is-dismissible">
					<p><?php echo esc_html( $notice['message'] ); ?></p>
					<?php if ( ! empty( $notice['skipped'] ) ) : ?>
						<p>
							<?php esc_html_e( 'Skipped (not WooCommerce settings):', 'all-in-settings-transfer-for-woocommerce' ); ?>
							<code><?php echo esc_html( implode( ', ', array_slice( $notice['skipped'], 0, 50 ) ) ); ?></code>
						</p>
					<?php endif; ?>
				</div>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Export Settings', 'all-in-settings-transfer-for-woocommerce' ); ?></h2>
			<p><?php esc_html_e( 'The file can contain payment gateway keys and other secrets. Store it safely.', 'all-in-settings-transfer-for-woocommerce' ); ?></p>
			<form method="post">
				<?php wp_nonce_field( 'ainst_export', 'ainst_export_nonce' ); ?>
				<input type="hidden" name="ainst_action" value="export">
				<button type="submit" class="button button-primary">
					<?php esc_html_e( 'Download JSON Export', 'all-in-settings-transfer-for-woocommerce' ); ?>
				</button>
			</form>

			<h2><?php esc_html_e( 'Import Settings', 'all-in-settings-transfer-for-woocommerce' ); ?></h2>
			<p><?php esc_html_e( 'Only WooCommerce settings (woocommerce_settings and woocommerce_*_settings) are imported. Any other entry in the file is skipped.', 'all-in-settings-transfer-for-woocommerce' ); ?></p>
			<form method="post" enctype="multipart/form-data">
				<?php wp_nonce_field( 'ainst_import', 'ainst_import_nonce' ); ?>
				<input type="hidden" name="ainst_action" value="import">
				<input type="file" name="ainst_import_file" accept=".json,application/json" required>
				<p>
					<button type="submit" class="button button-secondary">
						<?php esc_html_e( 'Upload & Import JSON', 'all-in-settings-transfer-for-woocommerce' ); ?>
					</button>
				</p>
			</form>
		</div>
		<?php
	}

	/**
	 * Send the JSON export as a download.
	 */
	private function export_settings() {
		global $wpdb;

		$like  = $wpdb->esc_like( 'woocommerce_' ) . '%' . $wpdb->esc_like( '_settings' );
		$names = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-off admin export; there is no API to list options by pattern.
			$wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $like )
		);

		if ( false !== get_option( 'woocommerce_settings', false ) ) {
			$names[] = 'woocommerce_settings';
		}

		$data = array();
		foreach ( array_unique( $names ) as $name ) {
			if ( self::is_allowed_option( $name ) ) {
				$data[ $name ] = get_option( $name );
			}
		}

		$json = wp_json_encode( $data, JSON_PRETTY_PRINT );
		if ( ! $json ) {
			wp_die( esc_html__( 'Failed to encode settings to JSON.', 'all-in-settings-transfer-for-woocommerce' ) );
		}

		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=woocommerce-settings.json' );
		echo $json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON file download, not HTML.
		exit;
	}

	/**
	 * Import the uploaded JSON file (allowlisted options only).
	 */
	private function import_settings() {
		// Nonce and capability were checked in maybe_handle_action().
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		if ( empty( $_FILES['ainst_import_file'] ) || ! is_array( $_FILES['ainst_import_file'] ) ) {
			$this->redirect_with_notice( __( 'File upload error.', 'all-in-settings-transfer-for-woocommerce' ), 'error' );
		}
		$file = array(
			'name'     => isset( $_FILES['ainst_import_file']['name'] ) ? sanitize_file_name( wp_unslash( $_FILES['ainst_import_file']['name'] ) ) : '',
			'tmp_name' => isset( $_FILES['ainst_import_file']['tmp_name'] ) ? sanitize_text_field( $_FILES['ainst_import_file']['tmp_name'] ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Server-generated temp path.
			'size'     => isset( $_FILES['ainst_import_file']['size'] ) ? absint( $_FILES['ainst_import_file']['size'] ) : 0,
			'error'    => isset( $_FILES['ainst_import_file']['error'] ) ? absint( $_FILES['ainst_import_file']['error'] ) : UPLOAD_ERR_NO_FILE,
		);
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( UPLOAD_ERR_OK !== $file['error'] || '' === $file['tmp_name'] || ! is_uploaded_file( $file['tmp_name'] ) ) {
			$this->redirect_with_notice( __( 'File upload error.', 'all-in-settings-transfer-for-woocommerce' ), 'error' );
		}
		if ( 'json' !== strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) ) ) {
			$this->redirect_with_notice( __( 'Please upload a .json file.', 'all-in-settings-transfer-for-woocommerce' ), 'error' );
		}
		if ( $file['size'] <= 0 || $file['size'] > self::MAX_FILE_SIZE ) {
			$this->redirect_with_notice( __( 'The file is empty or larger than 2 MB.', 'all-in-settings-transfer-for-woocommerce' ), 'error' );
		}

		$json = file_get_contents( $file['tmp_name'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a local uploaded temp file.
		$data = json_decode( (string) $json, true );
		if ( ! is_array( $data ) ) {
			$this->redirect_with_notice( __( 'Invalid JSON file.', 'all-in-settings-transfer-for-woocommerce' ), 'error' );
		}

		$added   = 0;
		$updated = 0;
		$skipped = array();
		foreach ( $data as $name => $val ) {
			$name = (string) $name;
			if ( ! self::is_allowed_option( $name ) || ! $this->is_safe_value( $val ) ) {
				$skipped[] = sanitize_text_field( $name );
				continue;
			}
			$val = $this->sanitize_value( $val );
			if ( false === get_option( $name, false ) ) {
				add_option( $name, $val, '', 'yes' );
				++$added;
			} else {
				update_option( $name, $val );
				++$updated;
			}
		}

		$msg = sprintf(
			/* translators: 1: added count, 2: updated count, 3: skipped count */
			__( 'Import complete: %1$d added, %2$d updated, %3$d skipped.', 'all-in-settings-transfer-for-woocommerce' ),
			$added,
			$updated,
			count( $skipped )
		);
		$this->redirect_with_notice( $msg, 'success', $skipped );
	}

	/**
	 * Values may be scalars, null or nested arrays (JSON objects decode to arrays).
	 *
	 * @param mixed $val   Value.
	 * @param int   $depth Current depth.
	 * @return bool
	 */
	private function is_safe_value( $val, $depth = 0 ) {
		if ( $depth > 10 ) {
			return false;
		}
		if ( is_array( $val ) ) {
			foreach ( $val as $v ) {
				if ( ! $this->is_safe_value( $v, $depth + 1 ) ) {
					return false;
				}
			}
			return true;
		}
		return is_null( $val ) || is_scalar( $val );
	}

	/**
	 * Sanitize every string in the value (settings may hold limited HTML, e.g. gateway descriptions).
	 *
	 * @param mixed $val Value.
	 * @return mixed
	 */
	private function sanitize_value( $val ) {
		return map_deep(
			$val,
			static function ( $v ) {
				return is_string( $v ) ? wp_kses_post( $v ) : $v;
			}
		);
	}

	/**
	 * Per-user transient key for the result notice.
	 *
	 * @return string
	 */
	private function notice_key() {
		return 'ainst_notice_' . get_current_user_id();
	}

	/**
	 * Store a notice and go back to the screen.
	 *
	 * @param string   $msg     Message.
	 * @param string   $type    success|error.
	 * @param string[] $skipped Skipped option names.
	 */
	private function redirect_with_notice( $msg, $type = 'success', $skipped = array() ) {
		set_transient(
			$this->notice_key(),
			array(
				'message' => $msg,
				'type'    => $type,
				'skipped' => $skipped,
			),
			5 * MINUTE_IN_SECONDS
		);
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) );
		exit;
	}
}

new AINST_Settings_Transfer();
