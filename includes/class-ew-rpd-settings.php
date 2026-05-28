<?php
/**
 * Settings service.
 *
 * @package EW_Remote_Post_Duplicator
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin settings page.
 */
class EW_RPD_Settings {

	/**
	 * Logger.
	 *
	 * @var EW_RPD_Logger
	 */
	private $logger;

	/**
	 * Constructor.
	 *
	 * @param EW_RPD_Logger $logger Logger.
	 */
	public function __construct( EW_RPD_Logger $logger ) {
		$this->logger = $logger;

		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_post_ew_rpd_test_connection', array( $this, 'handle_test_connection' ) );
		add_filter( 'plugin_action_links_' . EW_RPD_BASENAME, array( $this, 'plugin_action_links' ) );
	}

	/**
	 * Get default settings.
	 *
	 * @return array
	 */
	public static function get_defaults() {
		return array(
			'enabled'              => 0,
			'destination_url'      => '',
			'username'             => '',
			'application_password' => '',
			'post_types'           => array( 'post' ),
			'category_slugs'       => '',
			'tag_slugs'            => '',
			'destination_status'   => 'publish',
			'sync_featured_image'  => 1,
			'create_remote_terms'  => 1,
			'sync_slug'            => 1,
			'sync_date'            => 1,
			'update_remote'        => 1,
			'send_loop_meta'       => 1,
			'timeout'              => 30,
		);
	}

	/**
	 * Get all settings.
	 *
	 * @return array
	 */
	public function all() {
		$options = get_option( EW_RPD_OPTION_NAME, array() );

		if ( ! is_array( $options ) ) {
			$options = array();
		}

		return wp_parse_args( $options, self::get_defaults() );
	}

	/**
	 * Get setting value.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Default value.
	 * @return mixed
	 */
	public function get( $key, $default = null ) {
		$options = $this->all();

		return array_key_exists( $key, $options ) ? $options[ $key ] : $default;
	}

	/**
	 * Register admin menu.
	 *
	 * @return void
	 */
	public function register_menu() {
		add_options_page(
			__( 'Duplicador remoto', 'ew-remote-post-duplicator' ),
			__( 'Duplicador remoto', 'ew-remote-post-duplicator' ),
			'manage_options',
			'ew-rpd-settings',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Register settings API fields.
	 *
	 * @return void
	 */
	public function register_settings() {
		register_setting(
			'ew_rpd_settings_group',
			EW_RPD_OPTION_NAME,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_settings' ),
				'default'           => self::get_defaults(),
			)
		);

		add_settings_section(
			'ew_rpd_main_section',
			__( 'Conexion y reglas de sincronizacion', 'ew-remote-post-duplicator' ),
			array( $this, 'render_main_section' ),
			'ew-rpd-settings'
		);

		$fields = array(
			'enabled'              => __( 'Activar sincronizacion', 'ew-remote-post-duplicator' ),
			'destination_url'      => __( 'URL del WordPress destino', 'ew-remote-post-duplicator' ),
			'username'             => __( 'Usuario REST destino', 'ew-remote-post-duplicator' ),
			'application_password' => __( 'Application Password', 'ew-remote-post-duplicator' ),
			'post_types'           => __( 'Tipos de contenido', 'ew-remote-post-duplicator' ),
			'category_slugs'       => __( 'Categorias permitidas', 'ew-remote-post-duplicator' ),
			'tag_slugs'            => __( 'Etiquetas permitidas', 'ew-remote-post-duplicator' ),
			'destination_status'   => __( 'Estado en destino', 'ew-remote-post-duplicator' ),
			'sync_options'         => __( 'Opciones de sincronizacion', 'ew-remote-post-duplicator' ),
			'timeout'              => __( 'Timeout HTTP', 'ew-remote-post-duplicator' ),
		);

		foreach ( $fields as $id => $title ) {
			add_settings_field(
				$id,
				$title,
				array( $this, 'render_field' ),
				'ew-rpd-settings',
				'ew_rpd_main_section',
				array( 'id' => $id )
			);
		}
	}

	/**
	 * Add plugin action links.
	 *
	 * @param array $links Links.
	 * @return array
	 */
	public function plugin_action_links( $links ) {
		$settings_link = sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'options-general.php?page=ew-rpd-settings' ) ),
			esc_html__( 'Configurar', 'ew-remote-post-duplicator' )
		);

		array_unshift( $links, $settings_link );

		return $links;
	}

	/**
	 * Sanitize settings.
	 *
	 * @param array $input Raw input.
	 * @return array
	 */
	public function sanitize_settings( $input ) {
		$input    = is_array( $input ) ? $input : array();
		$current  = $this->all();
		$defaults = self::get_defaults();

		$output = array();

		$output['enabled']             = empty( $input['enabled'] ) ? 0 : 1;
		$output['destination_url']     = isset( $input['destination_url'] ) ? untrailingslashit( esc_url_raw( trim( wp_unslash( $input['destination_url'] ) ) ) ) : '';
		$output['username']            = isset( $input['username'] ) ? sanitize_text_field( wp_unslash( $input['username'] ) ) : '';
		$output['destination_status']  = isset( $input['destination_status'] ) ? sanitize_key( wp_unslash( $input['destination_status'] ) ) : $defaults['destination_status'];
		$output['category_slugs']      = isset( $input['category_slugs'] ) ? $this->sanitize_csv_slugs( wp_unslash( $input['category_slugs'] ) ) : '';
		$output['tag_slugs']           = isset( $input['tag_slugs'] ) ? $this->sanitize_csv_slugs( wp_unslash( $input['tag_slugs'] ) ) : '';
		$output['sync_featured_image'] = empty( $input['sync_featured_image'] ) ? 0 : 1;
		$output['create_remote_terms'] = empty( $input['create_remote_terms'] ) ? 0 : 1;
		$output['sync_slug']           = empty( $input['sync_slug'] ) ? 0 : 1;
		$output['sync_date']           = empty( $input['sync_date'] ) ? 0 : 1;
		$output['update_remote']       = empty( $input['update_remote'] ) ? 0 : 1;
		$output['send_loop_meta']      = empty( $input['send_loop_meta'] ) ? 0 : 1;
		$output['timeout']             = isset( $input['timeout'] ) ? max( 5, min( 120, absint( $input['timeout'] ) ) ) : $defaults['timeout'];

		if ( ! in_array( $output['destination_status'], array( 'publish', 'draft', 'pending', 'private' ), true ) ) {
			$output['destination_status'] = 'publish';
		}

		$password = isset( $input['application_password'] ) ? trim( (string) wp_unslash( $input['application_password'] ) ) : '';

		if ( '' === $password ) {
			$output['application_password'] = isset( $current['application_password'] ) ? $current['application_password'] : '';
		} else {
			$output['application_password'] = sanitize_text_field( $password );
		}

		$post_types            = isset( $input['post_types'] ) && is_array( $input['post_types'] ) ? array_map( 'sanitize_key', wp_unslash( $input['post_types'] ) ) : array();
		$available_post_types  = array_keys( $this->get_available_post_types() );
		$output['post_types']  = array_values( array_intersect( $post_types, $available_post_types ) );

		if ( empty( $output['post_types'] ) ) {
			$output['post_types'] = array( 'post' );
		}

		return wp_parse_args( $output, $defaults );
	}

	/**
	 * Sanitize comma-separated slugs.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	private function sanitize_csv_slugs( $value ) {
		$items = array_filter( array_map( 'trim', explode( ',', (string) $value ) ) );
		$items = array_map( 'sanitize_title', $items );
		$items = array_filter( $items );

		return implode( ',', array_unique( $items ) );
	}

	/**
	 * Section description.
	 *
	 * @return void
	 */
	public function render_main_section() {
		echo '<p>' . esc_html__( 'Configura el sitio destino. El plugin se instala en el WordPress origen y duplica entradas publicadas hacia el WordPress destino.', 'ew-remote-post-duplicator' ) . '</p>';
	}

	/**
	 * Render field.
	 *
	 * @param array $args Field arguments.
	 * @return void
	 */
	public function render_field( $args ) {
		$id      = isset( $args['id'] ) ? sanitize_key( $args['id'] ) : '';
		$options = $this->all();
		$name    = EW_RPD_OPTION_NAME . '[' . $id . ']';

		switch ( $id ) {
			case 'enabled':
				$this->render_checkbox( $name, 'enabled', (bool) $options['enabled'], __( 'Activar duplicacion automatica al publicar o actualizar.', 'ew-remote-post-duplicator' ) );
				break;

			case 'destination_url':
				printf(
					'<input type="url" class="regular-text" name="%1$s" value="%2$s" placeholder="https://destino.com" required />',
					esc_attr( $name ),
					esc_attr( $options['destination_url'] )
				);
				echo '<p class="description">' . esc_html__( 'Usa HTTPS siempre que sea posible.', 'ew-remote-post-duplicator' ) . '</p>';
				break;

			case 'username':
				printf(
					'<input type="text" class="regular-text" name="%1$s" value="%2$s" autocomplete="off" />',
					esc_attr( $name ),
					esc_attr( $options['username'] )
				);
				break;

			case 'application_password':
				printf(
					'<input type="password" class="regular-text" name="%1$s" value="" autocomplete="new-password" placeholder="%2$s" />',
					esc_attr( $name ),
					esc_attr__( 'Dejar vacio para conservar el valor actual', 'ew-remote-post-duplicator' )
				);
				if ( ! empty( $options['application_password'] ) ) {
					echo '<p class="description ew-rpd-ok">' . esc_html__( 'Hay un Application Password guardado.', 'ew-remote-post-duplicator' ) . '</p>';
				}
				break;

			case 'post_types':
				foreach ( $this->get_available_post_types() as $post_type => $label ) {
					$field_id = 'ew-rpd-post-type-' . $post_type;
					printf(
						'<label for="%1$s" class="ew-rpd-checkbox-line"><input id="%1$s" type="checkbox" name="%2$s[]" value="%3$s" %4$s /> %5$s</label>',
						esc_attr( $field_id ),
						esc_attr( $name ),
						esc_attr( $post_type ),
						checked( in_array( $post_type, (array) $options['post_types'], true ), true, false ),
						esc_html( $label . ' (' . $post_type . ')' )
					);
				}
				break;

			case 'category_slugs':
				printf(
					'<input type="text" class="regular-text" name="%1$s" value="%2$s" placeholder="noticias,comunicados" />',
					esc_attr( $name ),
					esc_attr( $options['category_slugs'] )
				);
				echo '<p class="description">' . esc_html__( 'Opcional. Si lo dejas vacio, sincroniza cualquier categoria. Usa slugs separados por coma.', 'ew-remote-post-duplicator' ) . '</p>';
				break;

			case 'tag_slugs':
				printf(
					'<input type="text" class="regular-text" name="%1$s" value="%2$s" placeholder="destacado,principal" />',
					esc_attr( $name ),
					esc_attr( $options['tag_slugs'] )
				);
				echo '<p class="description">' . esc_html__( 'Opcional. Si lo dejas vacio, sincroniza cualquier etiqueta. Usa slugs separados por coma.', 'ew-remote-post-duplicator' ) . '</p>';
				break;

			case 'destination_status':
				printf( '<select name="%s">', esc_attr( $name ) );
				foreach ( array( 'publish', 'draft', 'pending', 'private' ) as $status ) {
					printf(
						'<option value="%1$s" %2$s>%3$s</option>',
						esc_attr( $status ),
						selected( $options['destination_status'], $status, false ),
						esc_html( $status )
					);
				}
				echo '</select>';
				break;

			case 'sync_options':
				$this->render_checkbox( EW_RPD_OPTION_NAME . '[sync_featured_image]', 'sync_featured_image', (bool) $options['sync_featured_image'], __( 'Sincronizar imagen destacada.', 'ew-remote-post-duplicator' ) );
				$this->render_checkbox( EW_RPD_OPTION_NAME . '[create_remote_terms]', 'create_remote_terms', (bool) $options['create_remote_terms'], __( 'Crear categorias/etiquetas en destino si no existen.', 'ew-remote-post-duplicator' ) );
				$this->render_checkbox( EW_RPD_OPTION_NAME . '[sync_slug]', 'sync_slug', (bool) $options['sync_slug'], __( 'Sincronizar slug.', 'ew-remote-post-duplicator' ) );
				$this->render_checkbox( EW_RPD_OPTION_NAME . '[sync_date]', 'sync_date', (bool) $options['sync_date'], __( 'Sincronizar fecha de publicacion.', 'ew-remote-post-duplicator' ) );
				$this->render_checkbox( EW_RPD_OPTION_NAME . '[update_remote]', 'update_remote', (bool) $options['update_remote'], __( 'Actualizar la entrada remota cuando se edite la original.', 'ew-remote-post-duplicator' ) );
				$this->render_checkbox( EW_RPD_OPTION_NAME . '[send_loop_meta]', 'send_loop_meta', (bool) $options['send_loop_meta'], __( 'Enviar meta tecnica para evitar bucles si el plugin tambien existe en destino.', 'ew-remote-post-duplicator' ) );
				break;

			case 'timeout':
				printf(
					'<input type="number" min="5" max="120" name="%1$s" value="%2$d" /> <span>%3$s</span>',
					esc_attr( $name ),
					absint( $options['timeout'] ),
					esc_html__( 'segundos', 'ew-remote-post-duplicator' )
				);
				break;
		}
	}

	/**
	 * Render checkbox.
	 *
	 * @param string $name    Field name.
	 * @param string $id      Field ID suffix.
	 * @param bool   $checked Checked.
	 * @param string $label   Label.
	 * @return void
	 */
	private function render_checkbox( $name, $id, $checked, $label ) {
		$field_id = 'ew-rpd-' . sanitize_key( $id );

		printf(
			'<label for="%1$s" class="ew-rpd-checkbox-line"><input id="%1$s" type="checkbox" name="%2$s" value="1" %3$s /> %4$s</label>',
			esc_attr( $field_id ),
			esc_attr( $name ),
			checked( $checked, true, false ),
			esc_html( $label )
		);
	}

	/**
	 * Render settings page.
	 *
	 * @return void
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$this->render_notice_from_query();
		?>
		<div class="wrap ew-rpd-wrap">
			<h1><?php echo esc_html__( 'Duplicador remoto de entradas DGE', 'ew-remote-post-duplicator' ); ?></h1>
			<p><?php echo esc_html__( 'Duplica entradas desde este WordPress origen hacia otro WordPress destino usando REST API.', 'ew-remote-post-duplicator' ); ?></p>

			<form method="post" action="options.php" class="ew-rpd-card">
				<?php
				settings_fields( 'ew_rpd_settings_group' );
				do_settings_sections( 'ew-rpd-settings' );
				submit_button( __( 'Guardar configuracion', 'ew-remote-post-duplicator' ) );
				?>
			</form>

			<div class="ew-rpd-grid">
				<div class="ew-rpd-card">
					<h2><?php echo esc_html__( 'Probar conexion', 'ew-remote-post-duplicator' ); ?></h2>
					<p><?php echo esc_html__( 'Verifica que el usuario y Application Password puedan autenticarse en el WordPress destino.', 'ew-remote-post-duplicator' ); ?></p>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<?php wp_nonce_field( 'ew_rpd_test_connection' ); ?>
						<input type="hidden" name="action" value="ew_rpd_test_connection" />
						<?php submit_button( __( 'Probar conexion REST', 'ew-remote-post-duplicator' ), 'secondary', 'submit', false ); ?>
					</form>
				</div>

				<div class="ew-rpd-card">
					<h2><?php echo esc_html__( 'Sincronizacion manual', 'ew-remote-post-duplicator' ); ?></h2>
					<p><?php echo esc_html__( 'Tambien puedes sincronizar desde la lista de entradas usando la accion "Sincronizar remoto".', 'ew-remote-post-duplicator' ); ?></p>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<?php wp_nonce_field( 'ew_rpd_manual_sync' ); ?>
						<input type="hidden" name="action" value="ew_rpd_manual_sync" />
						<label for="ew-rpd-manual-post-id"><?php echo esc_html__( 'ID de entrada', 'ew-remote-post-duplicator' ); ?></label>
						<input id="ew-rpd-manual-post-id" type="number" min="1" name="post_id" />
						<?php submit_button( __( 'Sincronizar ahora', 'ew-remote-post-duplicator' ), 'secondary', 'submit', false ); ?>
					</form>
				</div>
			</div>

			<div class="ew-rpd-card">
				<h2><?php echo esc_html__( 'Ultimos logs', 'ew-remote-post-duplicator' ); ?></h2>
				<pre class="ew-rpd-log"><?php echo esc_html( implode( "\n", $this->logger->read_last_lines( 80 ) ) ); ?></pre>
			</div>
		</div>
		<?php
	}

	/**
	 * Render query notices.
	 *
	 * @return void
	 */
	private function render_notice_from_query() {
		if ( empty( $_GET['ew_rpd_message'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		$message = sanitize_key( wp_unslash( $_GET['ew_rpd_message'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$type    = 'success';
		$text    = '';

		switch ( $message ) {
			case 'connection_ok':
				$text = __( 'Conexion REST correcta.', 'ew-remote-post-duplicator' );
				break;
			case 'connection_fail':
				$type = 'error';
				$text = __( 'No se pudo conectar. Revisa los logs.', 'ew-remote-post-duplicator' );
				break;
			case 'manual_ok':
				$text = __( 'Sincronizacion manual completada.', 'ew-remote-post-duplicator' );
				break;
			case 'manual_fail':
				$type = 'error';
				$text = __( 'Sincronizacion manual fallida. Revisa los logs.', 'ew-remote-post-duplicator' );
				break;
		}

		if ( '' === $text ) {
			return;
		}

		printf(
			'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( $type ),
			esc_html( $text )
		);
	}

	/**
	 * Handle REST connection test.
	 *
	 * @return void
	 */
	public function handle_test_connection() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'No tienes permisos suficientes.', 'ew-remote-post-duplicator' ) );
		}

		check_admin_referer( 'ew_rpd_test_connection' );

		$client   = new EW_RPD_HTTP_Client( $this, $this->logger );
		$response = $client->request( 'GET', '/wp-json/wp/v2/users/me?context=edit' );

		if ( is_wp_error( $response ) ) {
			$this->logger->error( 'Connection test failed.', array( 'error' => $response->get_error_message() ) );
			$this->redirect_with_message( 'connection_fail' );
		}

		$this->logger->info( 'Connection test ok.', array( 'user' => isset( $response['name'] ) ? $response['name'] : '' ) );
		$this->redirect_with_message( 'connection_ok' );
	}

	/**
	 * Redirect to settings page.
	 *
	 * @param string $message Message key.
	 * @return void
	 */
	public function redirect_with_message( $message ) {
		wp_safe_redirect(
			add_query_arg(
				array( 'ew_rpd_message' => sanitize_key( $message ) ),
				admin_url( 'options-general.php?page=ew-rpd-settings' )
			)
		);
		exit;
	}

	/**
	 * Get available post types.
	 *
	 * @return array
	 */
	private function get_available_post_types() {
		$post_types = get_post_types(
			array(
				'show_ui' => true,
			),
			'objects'
		);

		$blocked = array( 'attachment', 'revision', 'nav_menu_item', 'custom_css', 'customize_changeset', 'oembed_cache', 'user_request', 'wp_block', 'wp_template', 'wp_template_part', 'wp_global_styles', 'wp_navigation' );
		$output  = array();

		foreach ( $post_types as $post_type => $object ) {
			if ( in_array( $post_type, $blocked, true ) ) {
				continue;
			}

			$output[ $post_type ] = $object->labels->singular_name;
		}

		return $output;
	}
}
