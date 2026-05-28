<?php
/**
 * Core plugin bootstrap.
 *
 * @package EW_Remote_Post_Duplicator
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plugin bootstrap class.
 */
final class EW_RPD_Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var EW_RPD_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Logger service.
	 *
	 * @var EW_RPD_Logger
	 */
	private $logger;

	/**
	 * Settings service.
	 *
	 * @var EW_RPD_Settings
	 */
	private $settings;

	/**
	 * Sync service.
	 *
	 * @var EW_RPD_Sync
	 */
	private $sync;

	/**
	 * Get singleton instance.
	 *
	 * @return EW_RPD_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->load_dependencies();

		$this->logger   = new EW_RPD_Logger();
		$this->settings = new EW_RPD_Settings( $this->logger );
		$this->sync     = new EW_RPD_Sync( $this->settings, $this->logger );

		add_action( 'init', array( $this, 'load_textdomain' ) );
		add_action( 'init', array( $this, 'register_remote_copy_meta' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
	}

	/**
	 * Load dependencies.
	 *
	 * @return void
	 */
	private function load_dependencies() {
		require_once EW_RPD_PATH . 'includes/class-ew-rpd-logger.php';
		require_once EW_RPD_PATH . 'includes/class-ew-rpd-settings.php';
		require_once EW_RPD_PATH . 'includes/class-ew-rpd-http-client.php';
		require_once EW_RPD_PATH . 'includes/class-ew-rpd-media.php';
		require_once EW_RPD_PATH . 'includes/class-ew-rpd-sync.php';
	}

	/**
	 * Activation callback.
	 *
	 * @return void
	 */
	public static function activate() {
		if ( ! class_exists( 'EW_RPD_Logger' ) ) {
			require_once EW_RPD_PATH . 'includes/class-ew-rpd-logger.php';
		}

		if ( ! class_exists( 'EW_RPD_Settings' ) ) {
			require_once EW_RPD_PATH . 'includes/class-ew-rpd-settings.php';
		}

		$defaults = EW_RPD_Settings::get_defaults();
		$current  = get_option( EW_RPD_OPTION_NAME );

		if ( ! is_array( $current ) ) {
			add_option( EW_RPD_OPTION_NAME, $defaults, '', false );
		}

		$logger = new EW_RPD_Logger();
		$logger->ensure_log_directory();
		$logger->info( 'Plugin activated.' );
	}

	/**
	 * Deactivation callback.
	 *
	 * @return void
	 */
	public static function deactivate() {
		if ( ! class_exists( 'EW_RPD_Logger' ) ) {
			require_once EW_RPD_PATH . 'includes/class-ew-rpd-logger.php';
		}

		$logger = new EW_RPD_Logger();
		$logger->info( 'Plugin deactivated.' );
	}

	/**
	 * Load translations.
	 *
	 * @return void
	 */
	public function load_textdomain() {
		load_plugin_textdomain( 'ew-remote-post-duplicator', false, dirname( EW_RPD_BASENAME ) . '/languages' );
	}

	/**
	 * Register meta used to prevent sync loops when plugin also exists on destination.
	 *
	 * @return void
	 */
	public function register_remote_copy_meta() {
		$post_types = get_post_types( array( 'show_in_rest' => true ), 'names' );

		foreach ( $post_types as $post_type ) {
			register_post_meta(
				$post_type,
				'_ew_rpd_remote_copy',
				array(
					'type'              => 'boolean',
					'single'            => true,
					'show_in_rest'      => true,
					'sanitize_callback' => 'rest_sanitize_boolean',
					'auth_callback'     => array( $this, 'can_edit_post_meta' ),
				)
			);

			register_post_meta(
				$post_type,
				'_ew_rpd_source_url',
				array(
					'type'              => 'string',
					'single'            => true,
					'show_in_rest'      => true,
					'sanitize_callback' => 'esc_url_raw',
					'auth_callback'     => array( $this, 'can_edit_post_meta' ),
				)
			);

			register_post_meta(
				$post_type,
				'_ew_rpd_source_post_id',
				array(
					'type'              => 'string',
					'single'            => true,
					'show_in_rest'      => true,
					'sanitize_callback' => 'sanitize_text_field',
					'auth_callback'     => array( $this, 'can_edit_post_meta' ),
				)
			);
		}
	}

	/**
	 * Meta edit capability callback.
	 *
	 * @param bool   $allowed   Current allowed state.
	 * @param string $meta_key  Meta key.
	 * @param int    $post_id   Post ID.
	 * @param int    $user_id   User ID.
	 * @return bool
	 */
	public function can_edit_post_meta( $allowed, $meta_key, $post_id, $user_id ) {
		unset( $allowed, $meta_key );

		return user_can( $user_id, 'edit_post', $post_id );
	}

	/**
	 * Enqueue admin assets.
	 *
	 * @param string $hook Current admin hook.
	 * @return void
	 */
	public function enqueue_admin_assets( $hook ) {
		if ( false === strpos( $hook, 'ew-rpd' ) && 'post.php' !== $hook && 'edit.php' !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'ew-rpd-admin',
			EW_RPD_URL . 'admin/css/admin-style.css',
			array(),
			EW_RPD_VERSION
		);
	}
}
