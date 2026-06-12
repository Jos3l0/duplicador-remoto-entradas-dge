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
		add_action( 'add_meta_boxes', array( $this, 'add_sync_status_meta_box' ) );
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
		if ( false === strpos( $hook, 'ew-rpd' ) && 'post.php' !== $hook && 'edit.php' !== $hook && 'post-new.php' !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'ew-rpd-admin',
			EW_RPD_URL . 'admin/css/admin-style.css',
			array(),
			EW_RPD_VERSION
		);

		if ( 'post.php' === $hook || 'post-new.php' === $hook || 'edit.php' === $hook || false !== strpos( $hook, 'ew-rpd' ) ) {
			wp_enqueue_script(
				'ew-rpd-metabox',
				EW_RPD_URL . 'admin/js/admin-script.js',
				array(),
				EW_RPD_VERSION,
				true
			);

			wp_localize_script(
				'ew-rpd-metabox',
				'ewRpdMetabox',
				array(
					'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
					'nonce'      => wp_create_nonce( 'ew_rpd_metabox_sync' ),
					'bulkNonce'  => wp_create_nonce( 'ew_rpd_bulk_sync' ),
					'syncing'    => __( 'Sincronizando...', 'ew-remote-post-duplicator' ),
					'syncBtn'    => __( 'Sincronizar ahora', 'ew-remote-post-duplicator' ),
					'retryBtn'   => __( 'Reintentar', 'ew-remote-post-duplicator' ),
				)
			);
		}
	}

	/**
	 * Add sync status meta box to configured post types.
	 *
	 * @param string $post_type Current post type.
	 * @return void
	 */
	public function add_sync_status_meta_box( $post_type ) {
		$allowed = (array) $this->settings->get( 'post_types', array( 'post' ) );

		if ( ! in_array( $post_type, $allowed, true ) ) {
			return;
		}

		add_meta_box(
			'ew-rpd-sync-status',
			__( 'Sincronizacion remota', 'ew-remote-post-duplicator' ),
			array( $this, 'render_sync_status_meta_box' ),
			$post_type,
			'side',
			'default'
		);
	}

	/**
	 * Render sync status meta box.
	 *
	 * @param WP_Post $post Post object.
	 * @return void
	 */
	public function render_sync_status_meta_box( $post ) {
		$remote_id   = absint( get_post_meta( $post->ID, '_ew_rpd_remote_post_id', true ) );
		$remote_url  = (string) get_post_meta( $post->ID, '_ew_rpd_remote_url', true );
		$last_sync   = (string) get_post_meta( $post->ID, '_ew_rpd_last_sync_gmt', true );
		$last_hash   = (string) get_post_meta( $post->ID, '_ew_rpd_last_hash', true );
		$is_enabled  = (bool) $this->settings->get( 'enabled', 0 );

		if ( 'publish' !== $post->post_status ) {
			echo '<p style="color:#856404;background:#fff3cd;padding:8px;border-radius:4px;">';
			echo esc_html__( 'La entrada no esta publicada. Solo se sincronizan entradas publicadas.', 'ew-remote-post-duplicator' );
			echo '</p>';
			return;
		}

		if ( ! $is_enabled ) {
			echo '<p style="color:#856404;background:#fff3cd;padding:8px;border-radius:4px;">';
			echo esc_html__( 'La sincronizacion automatica esta desactivada en los ajustes.', 'ew-remote-post-duplicator' );
			echo '</p>';
			return;
		}

		$destination_url = untrailingslashit( (string) $this->settings->get( 'destination_url', '' ) );

		?>
		<div class="ew-rpd-metabox-content">
			<div class="ew-rpd-metabox-status">
				<?php if ( $remote_id > 0 ) : ?>
					<span class="ew-rpd-status-icon ew-rpd-status-synced" title="<?php esc_attr_e( 'Sincronizado', 'ew-remote-post-duplicator' ); ?>">&#x2705;</span>
					<span class="ew-rpd-status-text" style="color:#008a20;"><?php echo esc_html__( 'Sincronizado', 'ew-remote-post-duplicator' ); ?></span>
				<?php else : ?>
					<span class="ew-rpd-status-icon ew-rpd-status-pending" title="<?php esc_attr_e( 'No sincronizado', 'ew-remote-post-duplicator' ); ?>">&#x2610;</span>
					<span class="ew-rpd-status-text" style="color:#646970;"><?php echo esc_html__( 'No sincronizado', 'ew-remote-post-duplicator' ); ?></span>
				<?php endif; ?>
			</div>

			<?php if ( $remote_id > 0 ) : ?>
				<div class="ew-rpd-metabox-detail">
					<p>
						<strong><?php echo esc_html__( 'ID remoto:', 'ew-remote-post-duplicator' ); ?></strong>
						<code><?php echo absint( $remote_id ); ?></code>
					</p>

					<?php if ( '' !== $remote_url ) : ?>
						<p>
							<a href="<?php echo esc_url( $remote_url ); ?>" target="_blank" rel="noopener noreferrer" class="button button-small">
								<?php echo esc_html__( 'Ver entrada remota', 'ew-remote-post-duplicator' ); ?> &#x2197;
							</a>
						</p>
					<?php elseif ( '' !== $destination_url ) : ?>
						<p>
							<a href="<?php echo esc_url( $destination_url . '/?p=' . absint( $remote_id ) ); ?>" target="_blank" rel="noopener noreferrer" class="button button-small">
								<?php echo esc_html__( 'Ver entrada remota', 'ew-remote-post-duplicator' ); ?> &#x2197;
							</a>
						</p>
					<?php endif; ?>

					<?php if ( '' !== $last_sync ) : ?>
						<p class="ew-rpd-metabox-date">
							<?php
							printf(
								/* translators: %s: date and time of last sync */
								esc_html__( 'Ultima sincronizacion: %s', 'ew-remote-post-duplicator' ),
								'<br><strong>' . esc_html( get_date_from_gmt( $last_sync, 'Y-m-d H:i:s' ) ) . '</strong>'
							);
							?>
						</p>
					<?php endif; ?>
				</div>
			<?php endif; ?>

			<div class="ew-rpd-metabox-actions">
				<button type="button"
					class="button button-small ew-rpd-sync-trigger"
					data-post-id="<?php echo absint( $post->ID ); ?>"
					<?php echo ( $remote_id > 0 ) ? '' : 'style="font-weight:600;"'; ?>>
					<?php echo ( $remote_id > 0 ) ? esc_html__( 'Sincronizar de nuevo', 'ew-remote-post-duplicator' ) : esc_html__( 'Sincronizar ahora', 'ew-remote-post-duplicator' ); ?>
				</button>
				<span class="spinner ew-rpd-spinner" style="float:none;margin:4px 0 0 4px;"></span>
				<span class="ew-rpd-metabox-result" style="display:none;"></span>
			</div>
		</div>
		<?php
	}
}
