<?php
/**
 * Post synchronization service.
 *
 * @package EW_Remote_Post_Duplicator
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Synchronizes local posts to remote WordPress.
 */
class EW_RPD_Sync {

	/**
	 * Settings.
	 *
	 * @var EW_RPD_Settings
	 */
	private $settings;

	/**
	 * Logger.
	 *
	 * @var EW_RPD_Logger
	 */
	private $logger;

	/**
	 * HTTP client.
	 *
	 * @var EW_RPD_HTTP_Client
	 */
	private $client;

	/**
	 * Media service.
	 *
	 * @var EW_RPD_Media
	 */
	private $media;

	/**
	 * Current in-memory syncing IDs.
	 *
	 * @var array
	 */
	private static $syncing = array();

	/**
	 * Constructor.
	 *
	 * @param EW_RPD_Settings $settings Settings.
	 * @param EW_RPD_Logger   $logger   Logger.
	 */
	public function __construct( EW_RPD_Settings $settings, EW_RPD_Logger $logger ) {
		$this->settings = $settings;
		$this->logger   = $logger;
		$this->client   = new EW_RPD_HTTP_Client( $settings, $logger );
		$this->media    = new EW_RPD_Media( $this->client, $logger );

		add_action( 'save_post', array( $this, 'maybe_sync_on_save' ), 30, 3 );
		add_action( 'transition_post_status', array( $this, 'maybe_sync_on_status_transition' ), 30, 3 );
		add_action( 'admin_post_ew_rpd_manual_sync', array( $this, 'handle_manual_sync' ) );
		add_action( 'wp_ajax_ew_rpd_metabox_sync', array( $this, 'handle_metabox_sync' ) );
		add_action( 'wp_ajax_ew_rpd_row_sync', array( $this, 'handle_row_sync' ) );
		add_action( 'wp_ajax_ew_rpd_bulk_sync_batch', array( $this, 'handle_bulk_sync_batch' ) );
		add_filter( 'post_row_actions', array( $this, 'add_row_action' ), 10, 2 );
		add_filter( 'page_row_actions', array( $this, 'add_row_action' ), 10, 2 );

		$this->register_list_columns();
	}

	/**
	 * Register custom column for post list.
	 *
	 * @return void
	 */
	private function register_list_columns() {
		$post_types = (array) $this->settings->get( 'post_types', array( 'post' ) );

		foreach ( $post_types as $post_type ) {
			if ( 'post' === $post_type ) {
				add_filter( 'manage_posts_columns', array( $this, 'add_sync_column' ) );
				add_action( 'manage_posts_custom_column', array( $this, 'render_sync_column' ), 10, 2 );
			} elseif ( 'page' === $post_type ) {
				add_filter( 'manage_pages_columns', array( $this, 'add_sync_column' ) );
				add_action( 'manage_pages_custom_column', array( $this, 'render_sync_column' ), 10, 2 );
			} else {
				add_filter( 'manage_' . $post_type . '_posts_columns', array( $this, 'add_sync_column' ) );
				add_action( 'manage_' . $post_type . '_posts_custom_column', array( $this, 'render_sync_column' ), 10, 2 );
			}
		}
	}

	/**
	 * Add sync status column after title.
	 *
	 * @param array $columns Existing columns.
	 * @return array
	 */
	public function add_sync_column( $columns ) {
		$new_columns = array();

		foreach ( $columns as $key => $value ) {
			$new_columns[ $key ] = $value;

			if ( 'title' === $key ) {
				$new_columns['ew_rpd_sync'] = __( 'Sincronizado', 'ew-remote-post-duplicator' );
			}
		}

		if ( ! isset( $new_columns['ew_rpd_sync'] ) ) {
			$new_columns['ew_rpd_sync'] = __( 'Sincronizado', 'ew-remote-post-duplicator' );
		}

		return $new_columns;
	}

	/**
	 * Render sync status column.
	 *
	 * @param string $column_name Column name.
	 * @param int    $post_id     Post ID.
	 * @return void
	 */
	public function render_sync_column( $column_name, $post_id ) {
		if ( 'ew_rpd_sync' !== $column_name ) {
			return;
		}

		$post = get_post( $post_id );

		if ( ! $post instanceof WP_Post || 'publish' !== $post->post_status ) {
			echo '<span class="dashicons dashicons-minus ew-rpd-col-icon ew-rpd-col-gray" title="' . esc_attr__( 'Solo se sincronizan entradas publicadas', 'ew-remote-post-duplicator' ) . '"></span>';
			return;
		}

		$remote_id   = absint( get_post_meta( $post_id, '_ew_rpd_remote_post_id', true ) );
		$remote_url  = (string) get_post_meta( $post_id, '_ew_rpd_remote_url', true );
		$last_sync   = (string) get_post_meta( $post_id, '_ew_rpd_last_sync_gmt', true );
		$last_error  = (string) get_post_meta( $post_id, '_ew_rpd_last_error', true );

		if ( $remote_id > 0 ) {
			$tooltip_parts = array();

			$tooltip_parts[] = sprintf(
				/* translators: %d: remote post ID */
				__( 'ID remoto: %d', 'ew-remote-post-duplicator' ),
				$remote_id
			);

			if ( '' !== $last_sync ) {
				$local_date = get_date_from_gmt( $last_sync, 'Y-m-d H:i:s' );
				$tooltip_parts[] = sprintf(
					/* translators: %s: date of last sync */
					__( 'Ultima sincronizacion: %s', 'ew-remote-post-duplicator' ),
					$local_date
				);
			}

			$tooltip = implode( ' | ', $tooltip_parts );

			echo '<span class="dashicons dashicons-cloud ew-rpd-col-icon ew-rpd-col-blue" title="' . esc_attr( $tooltip ) . '"></span>';

			if ( '' !== $remote_url ) {
				echo ' <a href="' . esc_url( $remote_url ) . '" target="_blank" rel="noopener noreferrer" class="ew-rpd-col-link" title="' . esc_attr__( 'Ver entrada remota', 'ew-remote-post-duplicator' ) . '"><span class="dashicons dashicons-admin-links"></span></a>';
			}
		} elseif ( '' !== $last_error ) {
			$tooltip = sprintf(
				/* translators: %s: error message */
				__( 'Error: %s', 'ew-remote-post-duplicator' ),
				$last_error
			);

			echo '<span class="dashicons dashicons-warning ew-rpd-col-icon ew-rpd-col-orange" title="' . esc_attr( $tooltip ) . '"></span>';
		} else {
			echo '<span class="dashicons dashicons-cloud ew-rpd-col-icon ew-rpd-col-gray" title="' . esc_attr__( 'No sincronizado', 'ew-remote-post-duplicator' ) . '"></span>';
		}
	}

	/**
	 * Sync on save.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 * @param bool    $update  Whether this is an update.
	 * @return void
	 */
	public function maybe_sync_on_save( $post_id, $post, $update ) {
		unset( $update );

		if ( ! $this->is_auto_sync_enabled() ) {
			return;
		}

		if ( ! $post instanceof WP_Post || 'publish' !== $post->post_status ) {
			return;
		}

		$this->sync_post( $post_id, false );
	}

	/**
	 * Sync when scheduled posts transition to publish.
	 *
	 * @param string  $new_status New status.
	 * @param string  $old_status Old status.
	 * @param WP_Post $post       Post object.
	 * @return void
	 */
	public function maybe_sync_on_status_transition( $new_status, $old_status, $post ) {
		unset( $old_status );

		if ( ! $this->is_auto_sync_enabled() ) {
			return;
		}

		if ( ! $post instanceof WP_Post || 'publish' !== $new_status ) {
			return;
		}

		$this->sync_post( $post->ID, false );
	}

	/**
	 * Handle manual sync action from settings page.
	 *
	 * @return void
	 */
	public function handle_manual_sync() {
		check_admin_referer( 'ew_rpd_manual_sync' );

		$post_id = isset( $_REQUEST['post_id'] ) ? absint( wp_unslash( $_REQUEST['post_id'] ) ) : 0; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_die( esc_html__( 'No tienes permisos suficientes para sincronizar esta entrada.', 'ew-remote-post-duplicator' ) );
		}

		$result  = $this->sync_post( $post_id, true );
		$message = is_wp_error( $result ) ? 'manual_fail' : 'manual_ok';

		wp_safe_redirect(
			add_query_arg(
				array( 'ew_rpd_message' => $message ),
				admin_url( 'options-general.php?page=ew-rpd-settings' )
			)
		);
		exit;
	}

	/**
	 * Handle AJAX sync request from meta box.
	 *
	 * @return void
	 */
	public function handle_metabox_sync() {
		check_ajax_referer( 'ew_rpd_metabox_sync', 'nonce' );

		$post_id = isset( $_POST['post_id'] ) ? absint( wp_unslash( $_POST['post_id'] ) ) : 0;

		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error(
				array( 'message' => __( 'No tienes permisos suficientes para sincronizar esta entrada.', 'ew-remote-post-duplicator' ) ),
				403
			);
		}

		$result = $this->sync_post( $post_id, true );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error(
				array(
					'message'   => $result->get_error_message(),
					'errorCode' => $result->get_error_code(),
				),
				400
			);
		}

		$remote_url = (string) get_post_meta( $post_id, '_ew_rpd_remote_url', true );
		$last_sync  = (string) get_post_meta( $post_id, '_ew_rpd_last_sync_gmt', true );

		wp_send_json_success(
			array(
				'message'   => __( 'Entrada sincronizada correctamente.', 'ew-remote-post-duplicator' ),
				'remoteId'  => absint( $result ),
				'remoteUrl' => esc_url( $remote_url ),
				'lastSync'  => '' !== $last_sync ? get_date_from_gmt( $last_sync, 'Y-m-d H:i:s' ) : '',
			)
		);
	}

	/**
	 * Handle AJAX sync request from row action link.
	 *
	 * @return void
	 */
	public function handle_row_sync() {
		check_ajax_referer( 'ew_rpd_row_sync', 'nonce' );

		$post_id = isset( $_POST['post_id'] ) ? absint( wp_unslash( $_POST['post_id'] ) ) : 0;

		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error(
				array( 'message' => __( 'No tienes permisos suficientes para sincronizar esta entrada.', 'ew-remote-post-duplicator' ) ),
				403
			);
		}

		$result = $this->sync_post( $post_id, true );

		if ( is_wp_error( $result ) ) {
			update_post_meta( $post_id, '_ew_rpd_last_error', sanitize_text_field( $result->get_error_message() ) );
			update_post_meta( $post_id, '_ew_rpd_last_sync_gmt', current_time( 'mysql', true ) );

			wp_send_json_error(
				array(
					'message'   => $result->get_error_message(),
					'errorCode' => $result->get_error_code(),
					'hasError'  => true,
				),
				400
			);
		}

		$remote_url = (string) get_post_meta( $post_id, '_ew_rpd_remote_url', true );
		$last_sync  = (string) get_post_meta( $post_id, '_ew_rpd_last_sync_gmt', true );

		wp_send_json_success(
			array(
				'message'   => __( 'Entrada sincronizada correctamente.', 'ew-remote-post-duplicator' ),
				'remoteId'  => absint( $result ),
				'remoteUrl' => esc_url( $remote_url ),
				'lastSync'  => '' !== $last_sync ? get_date_from_gmt( $last_sync, 'Y-m-d H:i:s' ) : '',
				'postId'    => $post_id,
			)
		);
	}

	/**
	 * Handle AJAX bulk sync batch for a category.
	 *
	 * Accepts: category_slug, offset, batch_size.
	 * Returns: processed, total, results[], done, offset.
	 *
	 * @return void
	 */
	public function handle_bulk_sync_batch() {
		check_ajax_referer( 'ew_rpd_bulk_sync', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'No tienes permisos suficientes.', 'ew-remote-post-duplicator' ) ), 403 );
		}

		$category_slug = isset( $_POST['category_slug'] ) ? sanitize_title( wp_unslash( $_POST['category_slug'] ) ) : '';
		$offset        = isset( $_POST['offset'] ) ? absint( wp_unslash( $_POST['offset'] ) ) : 0;
		$batch_size    = isset( $_POST['batch_size'] ) ? min( 10, max( 1, absint( wp_unslash( $_POST['batch_size'] ) ) ) ) : 5;

		if ( '' === $category_slug ) {
			wp_send_json_error( array( 'message' => __( 'Categoria no especificada.', 'ew-remote-post-duplicator' ) ), 400 );
		}

		$term = get_term_by( 'slug', $category_slug, 'category' );

		if ( ! $term instanceof WP_Term ) {
			wp_send_json_error( array( 'message' => __( 'Categoria no encontrada.', 'ew-remote-post-duplicator' ) ), 400 );
		}

		$allowed_post_types = (array) $this->settings->get( 'post_types', array( 'post' ) );

		$query = new WP_Query(
			array(
				'post_type'      => $allowed_post_types,
				'post_status'    => 'publish',
				'category_name'  => $category_slug,
				'posts_per_page' => $batch_size,
				'offset'         => $offset,
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'fields'         => 'ids',
				'no_found_rows'  => false,
				'meta_query'     => array(
					array(
						'key'     => '_ew_rpd_remote_copy',
						'compare' => 'NOT EXISTS',
					),
				),
			)
		);

		$total_posts  = absint( $query->found_posts );
		$results      = array();
		$processed    = 0;
		$errors       = 0;

		foreach ( $query->posts as $post_id ) {
			$result = $this->sync_post( $post_id, true );

			if ( is_wp_error( $result ) ) {
				$results[] = array(
					'postId'  => absint( $post_id ),
					'title'   => get_the_title( $post_id ),
					'status'  => 'error',
					'message' => $result->get_error_message(),
				);
				$errors++;
			} else {
				$remote_url = (string) get_post_meta( $post_id, '_ew_rpd_remote_url', true );
				$results[]  = array(
					'postId'    => absint( $post_id ),
					'title'     => get_the_title( $post_id ),
					'status'    => 'ok',
					'remoteId'  => absint( $result ),
					'remoteUrl' => esc_url( $remote_url ),
				);
				$processed++;
			}
		}

		$new_offset = $offset + count( $query->posts );
		$done       = $new_offset >= $total_posts;

		wp_send_json_success(
			array(
				'processed'  => $processed,
				'errors'     => $errors,
				'total'      => $total_posts,
				'done'       => $done,
				'offset'     => $new_offset,
				'results'    => $results,
				'category'   => $category_slug,
			)
		);
	}

	/**
	 * Add row action.
	 *
	 * @param array   $actions Existing actions.
	 * @param WP_Post $post    Post object.
	 * @return array
	 */
	public function add_row_action( $actions, $post ) {
		if ( ! $post instanceof WP_Post || ! current_user_can( 'edit_post', $post->ID ) ) {
			return $actions;
		}

		if ( ! $this->is_allowed_post_type( $post->post_type ) ) {
			return $actions;
		}

		$url = wp_nonce_url(
			add_query_arg(
				array(
					'action'  => 'ew_rpd_manual_sync',
					'post_id' => absint( $post->ID ),
				),
				admin_url( 'admin-post.php' )
			),
			'ew_rpd_manual_sync'
		);

		$actions['ew_rpd_sync'] = sprintf(
			'<a href="%1$s" class="ew-rpd-row-sync" data-post-id="%3$d" data-nonce="%4$s">%2$s</a>',
			esc_url( $url ),
			esc_html__( 'Sincronizar remoto', 'ew-remote-post-duplicator' ),
			absint( $post->ID ),
			wp_create_nonce( 'ew_rpd_row_sync' )
		);

		return $actions;
	}

	/**
	 * Sync a post to remote WordPress.
	 *
	 * @param int  $post_id Post ID.
	 * @param bool $manual  Manual sync.
	 * @return int|WP_Error Remote post ID or error.
	 */
	public function sync_post( $post_id, $manual = false ) {
		$post_id = absint( $post_id );
		$post    = get_post( $post_id );

		if ( ! $post instanceof WP_Post ) {
			return new WP_Error( 'ew_rpd_missing_post', __( 'La entrada no existe.', 'ew-remote-post-duplicator' ) );
		}

		$validation = $this->validate_post_for_sync( $post, $manual );

		if ( is_wp_error( $validation ) ) {
			$this->logger->warning( 'Post skipped.', array( 'post_id' => $post_id, 'reason' => $validation->get_error_message() ) );
			return $validation;
		}

		if ( isset( self::$syncing[ $post_id ] ) ) {
			return new WP_Error( 'ew_rpd_already_syncing', __( 'La entrada ya se esta sincronizando.', 'ew-remote-post-duplicator' ) );
		}

		$lock_key = 'ew_rpd_sync_lock_' . $post_id;

		if ( ! $manual && get_transient( $lock_key ) ) {
			return new WP_Error( 'ew_rpd_locked', __( 'Sincronizacion omitida por bloqueo temporal.', 'ew-remote-post-duplicator' ) );
		}

		self::$syncing[ $post_id ] = true;
		set_transient( $lock_key, 1, 30 );

		try {
			$remote_id = absint( get_post_meta( $post_id, '_ew_rpd_remote_post_id', true ) );

			// If no remote ID stored, search destination by slug to avoid duplicates.
			if ( 0 === $remote_id && ! empty( $post->post_name ) ) {
				$found_id = $this->find_remote_post_by_slug( $post->post_type, $post->post_name );

				if ( $found_id > 0 ) {
					$remote_id = $found_id;
					update_post_meta( $post_id, '_ew_rpd_remote_post_id', $remote_id );
					$this->logger->info( 'Found existing remote post by slug. Will update instead of create.', array( 'post_id' => $post_id, 'remote_id' => $remote_id, 'slug' => $post->post_name ) );
				}
			}

			$original_remote_id = $remote_id;

			$hash      = $this->build_content_hash( $post );
			$old_hash  = (string) get_post_meta( $post_id, '_ew_rpd_last_hash', true );

			if ( ! $manual && $remote_id > 0 && $hash === $old_hash ) {
				$this->logger->info( 'Post unchanged. Remote sync skipped.', array( 'post_id' => $post_id, 'remote_id' => $remote_id ) );
				return $remote_id;
			}

			if ( ! $manual && $remote_id > 0 && ! $this->settings->get( 'update_remote', 1 ) ) {
				$this->logger->info( 'Remote update disabled. Sync skipped.', array( 'post_id' => $post_id, 'remote_id' => $remote_id ) );
				return $remote_id;
			}

			$payload = $this->build_payload( $post );
			$path    = $this->get_remote_post_path( $post->post_type, $remote_id );
			$method  = 'POST';
			$result  = $this->client->request( $method, $path, $payload );

			if ( is_wp_error( $result ) && ! empty( $payload['meta'] ) && $this->looks_like_meta_rejection( $result ) ) {
				$this->logger->warning( 'Remote rejected technical meta. Retrying without meta.', array( 'post_id' => $post_id ) );
				unset( $payload['meta'] );
				$result = $this->client->request( $method, $path, $payload );
			}

			// If remote ID was specified but the post no longer exists on destination,
			// clear the stored ID and retry as a create.
			if ( is_wp_error( $result ) && $original_remote_id > 0 && $this->looks_like_remote_not_found( $result ) ) {
				$this->logger->warning( 'Remote post not found on destination. Clearing stored ID and creating new.', array( 'post_id' => $post_id, 'old_remote_id' => $original_remote_id ) );
				delete_post_meta( $post_id, '_ew_rpd_remote_post_id' );
				delete_post_meta( $post_id, '_ew_rpd_remote_url' );
				$remote_id = 0;
				$path      = $this->get_remote_post_path( $post->post_type, 0 );
				$result    = $this->client->request( $method, $path, $payload );
			}

			if ( is_wp_error( $result ) ) {
				$error_message = $result->get_error_message();
				$this->logger->error( 'Post sync failed.', array( 'post_id' => $post_id, 'error' => $error_message ) );
				update_post_meta( $post_id, '_ew_rpd_last_error', sanitize_text_field( $error_message ) );
				update_post_meta( $post_id, '_ew_rpd_last_sync_gmt', current_time( 'mysql', true ) );
				return $result;
			}

			if ( empty( $result['id'] ) ) {
				$error = new WP_Error( 'ew_rpd_missing_remote_id', __( 'El destino no devolvio ID remoto.', 'ew-remote-post-duplicator' ) );
				$this->logger->error( 'Post sync failed without remote ID.', array( 'post_id' => $post_id ) );
				update_post_meta( $post_id, '_ew_rpd_last_error', sanitize_text_field( $error->get_error_message() ) );
				update_post_meta( $post_id, '_ew_rpd_last_sync_gmt', current_time( 'mysql', true ) );
				return $error;
			}

			$remote_id = absint( $result['id'] );

			update_post_meta( $post_id, '_ew_rpd_remote_post_id', $remote_id );
			update_post_meta( $post_id, '_ew_rpd_remote_url', isset( $result['link'] ) ? esc_url_raw( $result['link'] ) : '' );
			update_post_meta( $post_id, '_ew_rpd_last_hash', $hash );
			update_post_meta( $post_id, '_ew_rpd_last_sync_gmt', current_time( 'mysql', true ) );
			delete_post_meta( $post_id, '_ew_rpd_last_error' );

			$this->logger->info( 'Post synchronized.', array( 'post_id' => $post_id, 'remote_id' => $remote_id, 'manual' => (bool) $manual ) );

			return $remote_id;
		} finally {
			delete_transient( $lock_key );
			unset( self::$syncing[ $post_id ] );
		}
	}

	/**
	 * Update local sync status metadata used by admin columns.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $status  Status slug.
	 * @param string $error   Optional error message.
	 * @return void
	 */
	private function update_sync_status( $post_id, $status, $error = '' ) {
		$post_id = absint( $post_id );
		$status  = sanitize_key( $status );
		$error   = is_scalar( $error ) ? sanitize_textarea_field( (string) $error ) : '';

		if ( ! in_array( $status, array( 'synced', 'error', 'partial', 'not_synced' ), true ) ) {
			$status = 'error';
		}

		update_post_meta( $post_id, '_ew_rpd_last_sync_status', $status );
		update_post_meta( $post_id, '_ew_rpd_last_sync_gmt', current_time( 'mysql', true ) );

		if ( '' === $error ) {
			delete_post_meta( $post_id, '_ew_rpd_last_sync_error' );
		} else {
			update_post_meta( $post_id, '_ew_rpd_last_sync_error', $error );
		}
	}

	/**
	 * Validate post before sync.
	 *
	 * @param WP_Post $post   Post object.
	 * @param bool    $manual Manual sync.
	 * @return true|WP_Error
	 */
	private function validate_post_for_sync( WP_Post $post, $manual ) {
		if ( wp_is_post_revision( $post->ID ) || wp_is_post_autosave( $post->ID ) ) {
			return new WP_Error( 'ew_rpd_revision', __( 'Revision/autoguardado omitido.', 'ew-remote-post-duplicator' ) );
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return new WP_Error( 'ew_rpd_autosave', __( 'Autoguardado omitido.', 'ew-remote-post-duplicator' ) );
		}

		if ( 'publish' !== $post->post_status ) {
			return new WP_Error( 'ew_rpd_not_published', __( 'Solo se sincronizan entradas publicadas.', 'ew-remote-post-duplicator' ) );
		}

		if ( get_post_meta( $post->ID, '_ew_rpd_remote_copy', true ) ) {
			return new WP_Error( 'ew_rpd_remote_copy', __( 'Esta entrada es una copia remota; se omite para evitar bucles.', 'ew-remote-post-duplicator' ) );
		}

		if ( ! $this->is_allowed_post_type( $post->post_type ) ) {
			return new WP_Error( 'ew_rpd_post_type', __( 'Tipo de contenido no permitido.', 'ew-remote-post-duplicator' ) );
		}

		if ( ! $manual && ! $this->passes_taxonomy_filters( $post->ID ) ) {
			return new WP_Error( 'ew_rpd_tax_filter', __( 'No coincide con las categorias/etiquetas configuradas.', 'ew-remote-post-duplicator' ) );
		}

		if ( ! $this->has_required_credentials() ) {
			return new WP_Error( 'ew_rpd_missing_credentials', __( 'Faltan credenciales REST o URL destino.', 'ew-remote-post-duplicator' ) );
		}

		$post_type_object = get_post_type_object( $post->post_type );

		if ( ! $post_type_object || empty( $post_type_object->show_in_rest ) ) {
			return new WP_Error( 'ew_rpd_post_type_rest', __( 'El tipo de contenido no expone REST API.', 'ew-remote-post-duplicator' ) );
		}

		return true;
	}

	/**
	 * Check auto sync flag.
	 *
	 * @return bool
	 */
	private function is_auto_sync_enabled() {
		return (bool) $this->settings->get( 'enabled', 0 );
	}

	/**
	 * Check credentials.
	 *
	 * @return bool
	 */
	private function has_required_credentials() {
		return '' !== (string) $this->settings->get( 'destination_url', '' )
			&& '' !== (string) $this->settings->get( 'username', '' )
			&& '' !== (string) $this->settings->get( 'application_password', '' );
	}

	/**
	 * Check allowed post type.
	 *
	 * @param string $post_type Post type.
	 * @return bool
	 */
	private function is_allowed_post_type( $post_type ) {
		$allowed = (array) $this->settings->get( 'post_types', array( 'post' ) );

		return in_array( $post_type, $allowed, true );
	}

	/**
	 * Check category/tag filters.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	private function passes_taxonomy_filters( $post_id ) {
		$category_filter = $this->csv_to_array( $this->settings->get( 'category_slugs', '' ) );
		$tag_filter      = $this->csv_to_array( $this->settings->get( 'tag_slugs', '' ) );

		if ( empty( $category_filter ) && empty( $tag_filter ) ) {
			return true;
		}

		if ( ! empty( $category_filter ) ) {
			$category_slugs = wp_get_post_terms( $post_id, 'category', array( 'fields' => 'slugs' ) );

			if ( ! is_wp_error( $category_slugs ) && array_intersect( $category_filter, $category_slugs ) ) {
				return true;
			}
		}

		if ( ! empty( $tag_filter ) ) {
			$tag_slugs = wp_get_post_terms( $post_id, 'post_tag', array( 'fields' => 'slugs' ) );

			if ( ! is_wp_error( $tag_slugs ) && array_intersect( $tag_filter, $tag_slugs ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Convert CSV to array.
	 *
	 * @param string $csv CSV.
	 * @return array
	 */
	private function csv_to_array( $csv ) {
		$items = array_filter( array_map( 'trim', explode( ',', (string) $csv ) ) );

		return array_values( array_unique( array_map( 'sanitize_title', $items ) ) );
	}

	/**
	 * Build remote payload.
	 *
	 * @param WP_Post $post Post object.
	 * @return array
	 */
	private function build_payload( WP_Post $post ) {
		$payload = array(
			'title'   => $post->post_title,
			'content' => $this->prepare_content_for_remote( $post ),
			'excerpt' => $post->post_excerpt,
			'status'  => sanitize_key( $this->settings->get( 'destination_status', 'publish' ) ),
		);

		if ( $this->settings->get( 'sync_slug', 1 ) && ! empty( $post->post_name ) ) {
			$payload['slug'] = $post->post_name;
		}

		if ( $this->settings->get( 'sync_date', 1 ) ) {
			$payload['date']     = mysql_to_rfc3339( $post->post_date );
			$payload['date_gmt'] = mysql_to_rfc3339( get_gmt_from_date( $post->post_date ) );
		}

		$categories = $this->sync_terms( $post->ID, 'category', 'categories' );
		$tags       = $this->sync_terms( $post->ID, 'post_tag', 'tags' );

		if ( ! empty( $categories ) ) {
			$payload['categories'] = $categories;
		}

		if ( ! empty( $tags ) ) {
			$payload['tags'] = $tags;
		}

		if ( $this->settings->get( 'sync_featured_image', 1 ) ) {
			$featured_media = $this->media->sync_featured_image( $post->ID );

			if ( $featured_media > 0 ) {
				$payload['featured_media'] = $featured_media;
			}
		}

		if ( $this->settings->get( 'send_loop_meta', 1 ) ) {
			$payload['meta'] = array(
				'_ew_rpd_remote_copy'    => true,
				'_ew_rpd_source_url'      => home_url(),
				'_ew_rpd_source_post_id'  => (string) $post->ID,
			);
		}

		$elementor_data = $this->prepare_elementor_data_for_remote( $post->ID );
		if ( '' !== $elementor_data ) {
			if ( ! isset( $payload['meta'] ) ) {
				$payload['meta'] = array();
			}
			$payload['meta']['_elementor_data'] = $elementor_data;
		}

		return $payload;
	}

	/**
	 * Prepare post content by uploading embedded local media and replacing local URLs/IDs.
	 *
	 * Handles both WordPress attachments (by ID) and arbitrary file URLs (PDFs, images, etc.).
	 *
	 * @param WP_Post $post Post object.
	 * @return string
	 */
	private function prepare_content_for_remote( WP_Post $post ) {
		$content = (string) $post->post_content;

		// Step 1: Migrate WordPress attachments by ID.
		$ids = $this->collect_content_media_ids( $content );
		$map = array();

		if ( ! empty( $ids ) ) {
			foreach ( $ids as $attachment_id ) {
				$remote = $this->media->sync_attachment( $attachment_id, $post->ID );

				if ( is_wp_error( $remote ) || empty( $remote['id'] ) ) {
					$this->logger->warning(
						'Embedded media skipped.',
						array(
							'post_id'       => $post->ID,
							'attachment_id' => $attachment_id,
							'error'         => is_wp_error( $remote ) ? $remote->get_error_message() : 'missing remote id',
						)
					);
					continue;
				}

				$map[ $attachment_id ] = $remote;
			}

			if ( ! empty( $map ) ) {
				$content = $this->replace_embedded_media_urls( $content, $map );
				$content = $this->replace_embedded_media_ids( $content, $map );
			}
		}

		// Step 2: Migrate any local file URLs by URL (PDFs, images not in media library, etc.).
		$url_map = $this->migrate_content_media_urls( $content, $post->ID );
		if ( ! empty( $url_map ) ) {
			$content = $this->replace_urls_in_content( $content, $url_map );
		}

		return $content;
	}

	/**
	 * Collect attachment IDs referenced by content blocks, shortcodes and local upload URLs.
	 *
	 * @param string $content Post content.
	 * @return array
	 */
	private function collect_content_media_ids( $content ) {
		$ids = array();

		if ( '' === (string) $content ) {
			return $ids;
		}

		$patterns = array(
			'/wp-image-(\d+)/i',
			'/\bdata-(?:id|attachment-id|attachment_id)=["\'](\d+)["\']/i',
			'/"id"\s*:\s*(\d+)/i',
		);

		foreach ( $patterns as $pattern ) {
			if ( preg_match_all( $pattern, $content, $matches ) ) {
				foreach ( $matches[1] as $id ) {
					$ids[] = absint( $id );
				}
			}
		}

		if ( preg_match_all( '/"ids"\s*:\s*\[([^\]]+)\]/i', $content, $matches ) ) {
			foreach ( $matches[1] as $id_list ) {
				foreach ( preg_split( '/\s*,\s*/', trim( $id_list ) ) as $id ) {
					$ids[] = absint( $id );
				}
			}
		}

		if ( preg_match_all( '/\[gallery\b[^\]]*\bids=["\']([^"\']+)["\'][^\]]*\]/i', $content, $matches ) ) {
			foreach ( $matches[1] as $id_list ) {
				foreach ( preg_split( '/\s*,\s*/', trim( $id_list ) ) as $id ) {
					$ids[] = absint( $id );
				}
			}
		}

		$ids = array_merge( $ids, $this->collect_media_ids_from_urls( $content ) );
		$ids = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );

		return array_values(
			array_filter(
				$ids,
				static function ( $id ) {
					return $id > 0 && 'attachment' === get_post_type( $id );
				}
			)
		);
	}

	/**
	 * Collect local attachment IDs from upload URLs in content.
	 *
	 * @param string $content Post content.
	 * @return array
	 */
	private function collect_media_ids_from_urls( $content ) {
		$upload_dir = wp_get_upload_dir();
		$baseurl    = isset( $upload_dir['baseurl'] ) ? untrailingslashit( $upload_dir['baseurl'] ) : '';

		if ( '' === $baseurl ) {
			return array();
		}

		$ids = array();

		if ( ! preg_match_all( '#https?:\\?/\\?/[^\s"\'<>\)]+#i', $content, $matches ) ) {
			return $ids;
		}

		foreach ( $matches[0] as $raw_url ) {
			$url = str_replace( '\\/', '/', html_entity_decode( $raw_url, ENT_QUOTES, get_option( 'blog_charset' ) ) );
			$url = esc_url_raw( $url );

			if ( '' === $url || 0 !== strpos( $url, $baseurl ) ) {
				continue;
			}

			$attachment_id = $this->attachment_url_to_id( $url );

			if ( $attachment_id > 0 ) {
				$ids[] = $attachment_id;
			}
		}

		return $ids;
	}

	/**
	 * Resolve attachment ID from full or intermediate image URL.
	 *
	 * @param string $url URL.
	 * @return int
	 */
	private function attachment_url_to_id( $url ) {
		$attachment_id = absint( attachment_url_to_postid( $url ) );

		if ( $attachment_id > 0 ) {
			return $attachment_id;
		}

		$normalized_url = preg_replace( '/-\d+x\d+(?=\.(?:jpe?g|png|gif|webp|avif)$)/i', '', $url );
		$normalized_url = preg_replace( '/-scaled(?=\.(?:jpe?g|png|gif|webp|avif)$)/i', '', $normalized_url );

		if ( $normalized_url && $normalized_url !== $url ) {
			return absint( attachment_url_to_postid( $normalized_url ) );
		}

		return 0;
	}

	/**
	 * Replace local embedded media URLs with remote media URLs.
	 *
	 * @param string $content Content.
	 * @param array  $map Local attachment ID to remote media data.
	 * @return string
	 */
	private function replace_embedded_media_urls( $content, array $map ) {
		foreach ( $map as $attachment_id => $remote ) {
			$remote_url = ! empty( $remote['source_url'] ) ? esc_url_raw( $remote['source_url'] ) : '';

			if ( '' === $remote_url ) {
				continue;
			}

			$url_variants = $this->get_local_attachment_url_variants( $attachment_id );
			$remote_sizes = ! empty( $remote['sizes'] ) && is_array( $remote['sizes'] ) ? $remote['sizes'] : array();

			foreach ( $url_variants as $size_name => $local_url ) {
				$replacement = isset( $remote_sizes[ $size_name ] ) ? esc_url_raw( $remote_sizes[ $size_name ] ) : $remote_url;
				$content     = $this->replace_url_variant( $content, $local_url, $replacement );
			}
		}

		return $content;
	}

	/**
	 * Replace normal and JSON-escaped URL variants.
	 *
	 * @param string $content Content.
	 * @param string $search Search URL.
	 * @param string $replacement Replacement URL.
	 * @return string
	 */
	private function replace_url_variant( $content, $search, $replacement ) {
		if ( '' === $search || '' === $replacement ) {
			return $content;
		}

		$content = str_replace( $search, $replacement, $content );
		$content = str_replace( str_replace( '/', '\\/', $search ), str_replace( '/', '\\/', $replacement ), $content );

		return $content;
	}

	/**
	 * Return local full and intermediate URLs keyed by size name.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array
	 */
	private function get_local_attachment_url_variants( $attachment_id ) {
		$urls     = array();
		$full_url = wp_get_attachment_url( $attachment_id );

		if ( $full_url ) {
			$urls['full'] = esc_url_raw( $full_url );
		}

		$metadata = wp_get_attachment_metadata( $attachment_id );

		if ( ! is_array( $metadata ) || empty( $metadata['file'] ) || empty( $metadata['sizes'] ) || ! is_array( $metadata['sizes'] ) ) {
			return $urls;
		}

		$upload_dir = wp_get_upload_dir();
		$baseurl    = isset( $upload_dir['baseurl'] ) ? untrailingslashit( $upload_dir['baseurl'] ) : '';
		$dirname    = trailingslashit( str_replace( '\\', '/', dirname( $metadata['file'] ) ) );

		foreach ( $metadata['sizes'] as $size_name => $size_data ) {
			if ( empty( $size_data['file'] ) || '' === $baseurl ) {
				continue;
			}

			$urls[ sanitize_key( $size_name ) ] = esc_url_raw( $baseurl . '/' . $dirname . $size_data['file'] );
		}

		return array_values( array_unique( $urls ) ) === $urls ? $urls : array_unique( $urls );
	}

	/**
	 * Replace local attachment IDs in known image/gallery contexts.
	 *
	 * @param string $content Content.
	 * @param array  $map Local attachment ID to remote media data.
	 * @return string
	 */
	private function replace_embedded_media_ids( $content, array $map ) {
		$id_map = array();

		foreach ( $map as $local_id => $remote ) {
			if ( ! empty( $remote['id'] ) ) {
				$id_map[ absint( $local_id ) ] = absint( $remote['id'] );
			}
		}

		if ( empty( $id_map ) ) {
			return $content;
		}

		foreach ( $id_map as $local_id => $remote_id ) {
			$content = preg_replace( '/\bwp-image-' . $local_id . '\b/', 'wp-image-' . $remote_id, $content );
			$content = preg_replace( '/(\bdata-(?:id|attachment-id|attachment_id)=["\'])' . $local_id . '(["\'])/i', '$1' . $remote_id . '$2', $content );
			$content = preg_replace( '/("id"\s*:\s*)' . $local_id . '\b/i', '$1' . $remote_id, $content );
		}

		$content = preg_replace_callback(
			'/"ids"\s*:\s*\[([^\]]+)\]/i',
			function ( $matches ) use ( $id_map ) {
				$ids = preg_split( '/\s*,\s*/', trim( $matches[1] ) );
				$ids = array_map(
					static function ( $id ) use ( $id_map ) {
						$id = absint( $id );
						return isset( $id_map[ $id ] ) ? $id_map[ $id ] : $id;
					},
					$ids
				);

				return '"ids":[' . implode( ',', array_map( 'absint', $ids ) ) . ']';
			},
			$content
		);

		$content = preg_replace_callback(
			'/\[gallery\b[^\]]*\]/i',
			function ( $matches ) use ( $id_map ) {
				return $this->replace_gallery_shortcode_ids( $matches[0], $id_map );
			},
			$content
		);

		return $content;
	}

	/**
	 * Replace ids attribute inside classic gallery shortcode.
	 *
	 * @param string $shortcode Gallery shortcode.
	 * @param array  $id_map Local to remote ID map.
	 * @return string
	 */
	private function replace_gallery_shortcode_ids( $shortcode, array $id_map ) {
		return preg_replace_callback(
			'/\bids=(["\'])([^"\']+)(["\'])/i',
			static function ( $matches ) use ( $id_map ) {
				$ids = preg_split( '/\s*,\s*/', trim( $matches[2] ) );
				$ids = array_map(
					static function ( $id ) use ( $id_map ) {
						$id = absint( $id );
						return isset( $id_map[ $id ] ) ? $id_map[ $id ] : $id;
					},
					$ids
				);

				return 'ids=' . $matches[1] . implode( ',', array_map( 'absint', $ids ) ) . $matches[3];
			},
			$shortcode
		);
	}

	/**
	 * Sync terms to remote and return IDs.
	 *
	 * @param int    $post_id  Post ID.
	 * @param string $taxonomy Local taxonomy.
	 * @param string $endpoint REST endpoint.
	 * @return array
	 */
	private function sync_terms( $post_id, $taxonomy, $endpoint ) {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return array();
		}

		$terms = wp_get_post_terms( $post_id, $taxonomy );

		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return array();
		}

		$remote_ids = array();

		foreach ( $terms as $term ) {
			$remote_id = $this->get_or_create_remote_term( $term, $endpoint );

			if ( $remote_id > 0 ) {
				$remote_ids[] = $remote_id;
			}
		}

		return array_values( array_unique( array_map( 'absint', $remote_ids ) ) );
	}

	/**
	 * Get or create remote term.
	 *
	 * @param WP_Term $term     Local term.
	 * @param string  $endpoint REST endpoint.
	 * @return int
	 */
	private function get_or_create_remote_term( WP_Term $term, $endpoint ) {
		$path     = sprintf( '/wp-json/wp/v2/%1$s?slug=%2$s&per_page=1', rawurlencode( $endpoint ), rawurlencode( $term->slug ) );
		$response = $this->client->request( 'GET', $path );

		if ( ! is_wp_error( $response ) && ! empty( $response[0]['id'] ) ) {
			return absint( $response[0]['id'] );
		}

		if ( ! $this->settings->get( 'create_remote_terms', 1 ) ) {
			return 0;
		}

		$created = $this->client->request(
			'POST',
			'/wp-json/wp/v2/' . rawurlencode( $endpoint ),
			array(
				'name'        => $term->name,
				'slug'        => $term->slug,
				'description' => $term->description,
			)
		);

		if ( is_wp_error( $created ) ) {
			$this->logger->warning( 'Remote term creation failed.', array( 'term' => $term->slug, 'endpoint' => $endpoint, 'error' => $created->get_error_message() ) );
			return 0;
		}

		return ! empty( $created['id'] ) ? absint( $created['id'] ) : 0;
	}

	/**
	 * Build remote post endpoint path.
	 *
	 * @param string $post_type Post type.
	 * @param int    $remote_id Remote ID.
	 * @return string
	 */
	private function get_remote_post_path( $post_type, $remote_id = 0 ) {
		$post_type_object = get_post_type_object( $post_type );
		$rest_base        = $post_type_object && ! empty( $post_type_object->rest_base ) ? $post_type_object->rest_base : $post_type;
		$path             = '/wp-json/wp/v2/' . rawurlencode( $rest_base );

		if ( $remote_id > 0 ) {
			$path .= '/' . absint( $remote_id );
		}

		return $path;
	}

	/**
	 * Search destination for an existing post by slug.
	 *
	 * @param string $post_type Post type.
	 * @param string $slug      Post slug.
	 * @return int Remote post ID, or 0 if not found.
	 */
	private function find_remote_post_by_slug( $post_type, $slug ) {
		$post_type_object = get_post_type_object( $post_type );
		$rest_base        = $post_type_object && ! empty( $post_type_object->rest_base ) ? $post_type_object->rest_base : $post_type;
		$path             = sprintf(
			'/wp-json/wp/v2/%s?slug=%s&per_page=1&status=publish,draft,pending,private',
			rawurlencode( $rest_base ),
			rawurlencode( $slug )
		);

		$response = $this->client->request( 'GET', $path );

		if ( is_wp_error( $response ) ) {
			$this->logger->warning( 'Slug lookup failed.', array( 'slug' => $slug, 'error' => $response->get_error_message() ) );
			return 0;
		}

		if ( ! empty( $response[0]['id'] ) ) {
			return absint( $response[0]['id'] );
		}

		return 0;
	}

	/**
	 * Build content hash.
	 *
	 * @param WP_Post $post Post object.
	 * @return string
	 */
	private function build_content_hash( WP_Post $post ) {
		$terms = array();

		foreach ( array( 'category', 'post_tag' ) as $taxonomy ) {
			$term_slugs = wp_get_post_terms( $post->ID, $taxonomy, array( 'fields' => 'slugs' ) );
			$terms[ $taxonomy ] = is_wp_error( $term_slugs ) ? array() : $term_slugs;
		}

		$data = array(
			'post_type'        => $post->post_type,
			'post_title'       => $post->post_title,
			'post_content'     => $post->post_content,
			'post_excerpt'     => $post->post_excerpt,
			'post_status'      => $post->post_status,
			'post_name'        => $post->post_name,
			'post_date'        => $post->post_date,
			'thumbnail_id'     => get_post_thumbnail_id( $post->ID ),
			'content_media_ids' => $this->collect_content_media_ids( $post->post_content ),
			'plugin_version'    => defined( 'EW_RPD_VERSION' ) ? EW_RPD_VERSION : '',
			'terms'            => $terms,
			'destination_url'  => $this->settings->get( 'destination_url', '' ),
			'destination_mode' => $this->settings->get( 'destination_status', 'publish' ),
		);

		return hash( 'sha256', wp_json_encode( $data ) );
	}

	/**
	 * Detect remote rejection of meta field.
	 *
	 * @param WP_Error $error Error.
	 * @return bool
	 */
	private function looks_like_meta_rejection( WP_Error $error ) {
		$data = $error->get_error_data();

		if ( is_array( $data ) && isset( $data['body']['data']['params']['meta'] ) ) {
			return true;
		}

		$message = strtolower( $error->get_error_message() );

		return false !== strpos( $message, 'meta' ) || false !== strpos( $message, 'rest_invalid_param' );
	}

	/**
	 * Detect if remote error means the post no longer exists on destination.
	 *
	 * @param WP_Error $error Error.
	 * @return bool
	 */
	private function looks_like_remote_not_found( WP_Error $error ) {
		$data   = $error->get_error_data();
		$status = is_array( $data ) && isset( $data['status'] ) ? absint( $data['status'] ) : 0;

		if ( 404 === $status ) {
			return true;
		}

		$message = strtolower( $error->get_error_message() );

		$patterns = array( 'no es válido', 'no es valido', 'not found', 'invalid', 'no existe', 'rest_post_invalid_id' );

		foreach ( $patterns as $pattern ) {
			if ( false !== strpos( $message, $pattern ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Prepare Elementor data by migrating embedded media and replacing local URLs.
	 *
	 * Reads _elementor_data postmeta, finds all local media URLs (PDFs, images,
	 * documents, etc.), uploads each file to the destination, and replaces the
	 * URLs in the JSON. External URLs are preserved unchanged.
	 *
	 * @param int $post_id Post ID.
	 * @return string Modified Elementor JSON or empty string.
	 */
	private function prepare_elementor_data_for_remote( $post_id ) {
		$elementor_data = get_post_meta( $post_id, '_elementor_data', true );

		if ( '' === (string) $elementor_data ) {
			return '';
		}

		$urls = $this->extract_all_urls_from_content( $elementor_data );

		if ( empty( $urls ) ) {
			return $elementor_data;
		}

		$url_map = array();

		foreach ( $urls as $url ) {
			$remote = $this->media->sync_file_by_url( $url, $post_id );

			if ( is_wp_error( $remote ) ) {
				$this->logger->warning(
					'Elementor media URL skipped.',
					array( 'post_id' => $post_id, 'url' => $url, 'error' => $remote->get_error_message() )
				);
				continue;
			}

			if ( ! empty( $remote['source_url'] ) ) {
				$url_map[ $url ] = $remote['source_url'];
			}
		}

		if ( empty( $url_map ) ) {
			return $elementor_data;
		}

		return $this->replace_urls_in_content( $elementor_data, $url_map );
	}

	/**
	 * Extract all absolute and relative media URLs from content.
	 *
	 * @param string $content Content.
	 * @return array
	 */
	private function extract_all_urls_from_content( $content ) {
		$urls = array();

		if ( '' === (string) $content ) {
			return $urls;
		}

		if ( preg_match_all( '#https?://[^\s"\'<>\)]+#i', $content, $matches ) ) {
			foreach ( $matches[0] as $url ) {
				$urls[] = $url;
			}
		}

		if ( preg_match_all( '#/(?:wp-content|wp-includes)/[^\s"\'<>\)]+#i', $content, $matches ) ) {
			foreach ( $matches[0] as $url ) {
				$urls[] = site_url( $url );
			}
		}

		return array_values( array_unique( $urls ) );
	}

	/**
	 * Replace all URLs in content with remote URLs.
	 *
	 * Preserves the original structure (Elementor tags, shortcodes, iframes)
	 * and only replaces URLs. Sorts by length descending to avoid
	 * partial replacements.
	 *
	 * @param string $content Content.
	 * @param array  $url_map Map of original URL to remote URL.
	 * @return string
	 */
	private function replace_urls_in_content( $content, array $url_map ) {
		if ( empty( $url_map ) ) {
			return $content;
		}

		uksort( $url_map, function( $a, $b ) {
			return strlen( $b ) - strlen( $a );
		} );

		foreach ( $url_map as $original => $replacement ) {
			$content = str_replace( $original, $replacement, $content );
			$content = str_replace( str_replace( '/', '\/', $original ), str_replace( '/', '\/', $replacement ), $content );
		}

		return $content;
	}

	/**
	 * Migrate all local file URLs found in content.
	 *
	 * Extracts URLs from Gutenberg blocks, <a href>, <img src>,
	 * and any local file reference, then uploads each via sync_file_by_url().
	 *
	 * @param string $content Content.
	 * @param int    $post_id Post ID.
	 * @return array Map of original URL => remote URL.
	 */
	private function migrate_content_media_urls( $content, $post_id ) {
		$urls = $this->collect_content_media_urls( $content );

		if ( empty( $urls ) ) {
			return array();
		}

		$url_map = array();

		foreach ( $urls as $url ) {
			$remote = $this->media->sync_file_by_url( $url, $post_id );

			if ( is_wp_error( $remote ) ) {
				$this->logger->warning(
					'Content media URL skipped.',
					array(
						'post_id' => $post_id,
						'url'     => $url,
						'error'   => $remote->get_error_message(),
					)
				);
				continue;
			}

			if ( ! empty( $remote['source_url'] ) ) {
				$url_map[ $url ] = $remote['source_url'];
			}
		}

		return $url_map;
	}

	/**
	 * Collect all local file URLs referenced in content.
	 *
	 * Extracts URLs from:
	 * - Gutenberg block comments with "url" attribute (e.g. pdfemb)
	 * - <a href="..."> tags pointing to local files
	 * - <img src="..."> tags
	 * - Any URL containing /wp-content/uploads/ with a file extension
	 *
	 * @param string $content Post content.
	 * @return array
	 */
	private function collect_content_media_urls( $content ) {
		$urls = array();

		if ( '' === (string) $content ) {
			return $urls;
		}

		// Pattern 1: Gutenberg block comments with "url":"..." (e.g. pdfemb)
		if ( preg_match_all( '/"url"\s*:\s*"([^"]+\.(?:pdf|png|jpe?g|gif|webp|avif|doc|docx|xls|xlsx|ppt|pptx|zip|mp3|mp4|mov|svg))"/i', $content, $matches ) ) {
			foreach ( $matches[1] as $url ) {
				$urls[] = $url;
			}
		}

		// Pattern 2: <a href="..."> pointing to local files
		if ( preg_match_all( '/<a[^>]+href=["\']([^"\']+\.(?:pdf|png|jpe?g|gif|webp|avif|doc|docx|xls|xlsx|ppt|pptx|zip|mp3|mp4|mov|svg))["\']/i', $content, $matches ) ) {
			foreach ( $matches[1] as $url ) {
				$urls[] = $url;
			}
		}

		// Pattern 3: <img src="...">
		if ( preg_match_all( '/<img[^>]+src=["\']([^"\']+)["\']/i', $content, $matches ) ) {
			foreach ( $matches[1] as $url ) {
				$urls[] = $url;
			}
		}

		// Pattern 4: Any URL in /wp-content/uploads/ with file extension
		if ( preg_match_all( '#https?://[^\s"\'<>\)]+/wp-content/uploads/[^\s"\'<>\)]+\.[a-z0-9]{2,5}#i', $content, $matches ) ) {
			foreach ( $matches[0] as $url ) {
				$urls[] = $url;
			}
		}

		// Pattern 5: Any URL in /wp-includes/ with file extension
		if ( preg_match_all( '#https?://[^\s"\'<>\)]+/wp-includes/[^\s"\'<>\)]+\.[a-z0-9]{2,5}#i', $content, $matches ) ) {
			foreach ( $matches[0] as $url ) {
				$urls[] = $url;
			}
		}

		// Filter only local URLs via media service.
		$local_urls = array();
		foreach ( array_unique( $urls ) as $url ) {
			if ( $this->media->is_local_url( $url ) ) {
				$local_urls[] = $url;
			}
		}

		return array_values( array_unique( $local_urls ) );
	}
}
