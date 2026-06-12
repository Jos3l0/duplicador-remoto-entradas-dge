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
		add_filter( 'post_row_actions', array( $this, 'add_row_action' ), 10, 2 );
		add_filter( 'page_row_actions', array( $this, 'add_row_action' ), 10, 2 );

		// Register list-table columns both early and in admin context to support admin screens loaded after plugin bootstrap.
		add_action( 'init', array( $this, 'register_admin_columns' ), 30 );
		add_action( 'admin_init', array( $this, 'register_admin_columns' ), 5 );

		// Fallback for the native Posts list table, whose generic filters may run even when dynamic hooks are bypassed.
		add_filter( 'manage_posts_columns', array( $this, 'add_sync_status_column_for_list_table' ), 10, 2 );
		add_action( 'manage_posts_custom_column', array( $this, 'render_sync_status_column' ), 10, 2 );
	}

	/**
	 * Register admin list-table columns for allowed post types.
	 *
	 * @return void
	 */
	public function register_admin_columns() {
		$post_types = (array) $this->settings->get( 'post_types', array( 'post' ) );

		foreach ( $post_types as $post_type ) {
			$post_type = sanitize_key( $post_type );

			if ( '' === $post_type || ! post_type_exists( $post_type ) ) {
				continue;
			}

			add_filter( "manage_{$post_type}_posts_columns", array( $this, 'add_sync_status_column' ) );
			add_action( "manage_{$post_type}_posts_custom_column", array( $this, 'render_sync_status_column' ), 10, 2 );
		}
	}

	/**
	 * Add sync status column after title.
	 *
	 * @param array $columns Existing columns.
	 * @return array
	 */
	public function add_sync_status_column( $columns ) {
		if ( isset( $columns['ew_rpd_sync_status'] ) ) {
			return $columns;
		}

		$new_columns = array();
		$inserted    = false;

		foreach ( $columns as $key => $label ) {
			$new_columns[ $key ] = $label;

			if ( 'title' === $key ) {
				$new_columns['ew_rpd_sync_status'] = __( 'Sincronizado', 'ew-remote-post-duplicator' );
				$inserted = true;
			}
		}

		if ( ! $inserted ) {
			$new_columns['ew_rpd_sync_status'] = __( 'Sincronizado', 'ew-remote-post-duplicator' );
		}

		return $new_columns;
	}

	/**
	 * Add sync status column for the generic posts list-table filter.
	 *
	 * @param array  $columns   Existing columns.
	 * @param string $post_type Current post type.
	 * @return array
	 */
	public function add_sync_status_column_for_list_table( $columns, $post_type ) {
		$post_type = sanitize_key( (string) $post_type );

		if ( '' === $post_type || ! $this->is_allowed_post_type( $post_type ) ) {
			return $columns;
		}

		return $this->add_sync_status_column( $columns );
	}

	/**
	 * Render sync status column.
	 *
	 * @param string $column  Column key.
	 * @param int    $post_id Post ID.
	 * @return void
	 */
	public function render_sync_status_column( $column, $post_id ) {
		if ( 'ew_rpd_sync_status' !== $column ) {
			return;
		}

		$post_id   = absint( $post_id );
		$remote_id = absint( get_post_meta( $post_id, '_ew_rpd_remote_post_id', true ) );
		$status    = sanitize_key( (string) get_post_meta( $post_id, '_ew_rpd_last_sync_status', true ) );
		$error     = (string) get_post_meta( $post_id, '_ew_rpd_last_sync_error', true );
		$remote_url = esc_url( (string) get_post_meta( $post_id, '_ew_rpd_remote_url', true ) );
		$sync_gmt  = (string) get_post_meta( $post_id, '_ew_rpd_last_sync_gmt', true );

		if ( '' === $status && $remote_id > 0 ) {
			$status = 'synced';
		} elseif ( '' === $status ) {
			$status = 'not_synced';
		}

		$label = __( 'No sincronizado', 'ew-remote-post-duplicator' );
		$class = 'not-synced';
		$icon  = 'dashicons-minus';

		if ( 'synced' === $status ) {
			$label = __( 'Sincronizado', 'ew-remote-post-duplicator' );
			$class = 'synced';
			$icon  = 'dashicons-cloud';
		} elseif ( 'error' === $status ) {
			$label = __( 'Error de sincronizacion', 'ew-remote-post-duplicator' );
			$class = 'error';
			$icon  = 'dashicons-cloud';
		} elseif ( 'partial' === $status ) {
			$label = __( 'Sincronizacion parcial', 'ew-remote-post-duplicator' );
			$class = 'partial';
			$icon  = 'dashicons-cloud';
		}

		$title_parts = array( $label );

		if ( $remote_id > 0 ) {
			$title_parts[] = sprintf( __( 'ID remoto: %d', 'ew-remote-post-duplicator' ), $remote_id );
		}

		if ( '' !== $sync_gmt ) {
			$title_parts[] = sprintf( __( 'Ultima sincronizacion: %s', 'ew-remote-post-duplicator' ), get_date_from_gmt( $sync_gmt, 'Y-m-d H:i' ) );
		}

		if ( '' !== $error ) {
			$title_parts[] = sprintf( __( 'Error: %s', 'ew-remote-post-duplicator' ), wp_strip_all_tags( $error ) );
		}

		printf(
			'<span class="ew-rpd-sync-status ew-rpd-sync-status-%1$s" title="%2$s"><span class="dashicons %3$s" aria-hidden="true"></span><span class="ew-rpd-sync-badge" aria-hidden="true"></span><span class="screen-reader-text">%4$s</span></span>',
			esc_attr( $class ),
			esc_attr( implode( ' | ', $title_parts ) ),
			esc_attr( $icon ),
			esc_html( $label )
		);

		if ( $remote_id > 0 ) {
			echo '<div class="ew-rpd-sync-meta">ID ' . esc_html( (string) $remote_id ) . '</div>';
		}

		if ( '' !== $remote_url ) {
			printf(
				'<div class="ew-rpd-sync-meta"><a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a></div>',
				esc_url( $remote_url ),
				esc_html__( 'Ver remoto', 'ew-remote-post-duplicator' )
			);
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
	 * Handle manual sync action.
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
			'<a href="%1$s">%2$s</a>',
			esc_url( $url ),
			esc_html__( 'Sincronizar remoto', 'ew-remote-post-duplicator' )
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
			if ( $manual ) {
				$this->update_sync_status( $post_id, 'error', $validation->get_error_message() );
			}

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
			$hash      = $this->build_content_hash( $post );
			$old_hash  = (string) get_post_meta( $post_id, '_ew_rpd_last_hash', true );

			if ( ! $manual && $remote_id > 0 && $hash === $old_hash ) {
				$this->update_sync_status( $post_id, 'synced', '' );
				$this->logger->info( 'Post unchanged. Remote sync skipped.', array( 'post_id' => $post_id, 'remote_id' => $remote_id ) );
				return $remote_id;
			}

			if ( ! $manual && $remote_id > 0 && ! $this->settings->get( 'update_remote', 1 ) ) {
				$this->logger->info( 'Remote update disabled. Sync skipped.', array( 'post_id' => $post_id, 'remote_id' => $remote_id ) );
				return $remote_id;
			}

			$payload = $this->build_payload( $post );
			$path    = $this->get_remote_post_path( $post->post_type, $remote_id );
			$method  = $remote_id > 0 ? 'POST' : 'POST';
			$result  = $this->client->request( $method, $path, $payload );

			if ( is_wp_error( $result ) && ! empty( $payload['meta'] ) && $this->looks_like_meta_rejection( $result ) ) {
				$this->logger->warning( 'Remote rejected technical meta. Retrying without meta.', array( 'post_id' => $post_id ) );
				unset( $payload['meta'] );
				$result = $this->client->request( $method, $path, $payload );
			}

			if ( is_wp_error( $result ) ) {
				$this->update_sync_status( $post_id, 'error', $result->get_error_message() );
				$this->logger->error( 'Post sync failed.', array( 'post_id' => $post_id, 'error' => $result->get_error_message() ) );
				return $result;
			}

			if ( empty( $result['id'] ) ) {
				$error = new WP_Error( 'ew_rpd_missing_remote_id', __( 'El destino no devolvio ID remoto.', 'ew-remote-post-duplicator' ) );
				$this->update_sync_status( $post_id, 'error', $error->get_error_message() );
				$this->logger->error( 'Post sync failed without remote ID.', array( 'post_id' => $post_id ) );
				return $error;
			}

			$remote_id = absint( $result['id'] );

			update_post_meta( $post_id, '_ew_rpd_remote_post_id', $remote_id );
			update_post_meta( $post_id, '_ew_rpd_remote_url', isset( $result['link'] ) ? esc_url_raw( $result['link'] ) : '' );
			update_post_meta( $post_id, '_ew_rpd_last_hash', $hash );
			$this->update_sync_status( $post_id, 'synced', '' );

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

		// Sync Elementor data if present.
		$elementor_data = $this->prepare_elementor_data_for_remote( $post->ID );
		if ( '' !== $elementor_data ) {
			if ( ! isset( $payload['meta'] ) ) {
				$payload['meta'] = array();
			}
			$payload['meta']['_elementor_data'] = $elementor_data;
		}

		if ( $this->settings->get( 'send_loop_meta', 1 ) ) {
			$payload['meta'] = array(
				'_ew_rpd_remote_copy'    => true,
				'_ew_rpd_source_url'      => home_url(),
				'_ew_rpd_source_post_id'  => (string) $post->ID,
			);
		}

		return $payload;
	}

	/**
	 * Prepare post content by uploading embedded local media and replacing local URLs/IDs.
	 *
	 * @param WP_Post $post Post object.
	 * @return string
	 */
	private function prepare_content_for_remote( WP_Post $post ) {
		$content = (string) $post->post_content;
		$ids     = $this->collect_content_media_ids( $content );

		if ( empty( $ids ) ) {
			return $content;
		}

		$map = array();

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

		if ( empty( $map ) ) {
			return $content;
		}

		$content = $this->replace_embedded_media_urls( $content, $map );
		$content = $this->replace_embedded_media_ids( $content, $map );

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
	 * Prepare Elementor data by migrating embedded media and replacing local URLs.
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
	 * Extract all absolute and relative URLs from content.
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
}
