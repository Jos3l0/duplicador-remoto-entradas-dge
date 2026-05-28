<?php
/**
 * REST HTTP client.
 *
 * @package EW_Remote_Post_Duplicator
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * HTTP client for destination WordPress.
 */
class EW_RPD_HTTP_Client {

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
	 * Constructor.
	 *
	 * @param EW_RPD_Settings $settings Settings.
	 * @param EW_RPD_Logger   $logger   Logger.
	 */
	public function __construct( EW_RPD_Settings $settings, EW_RPD_Logger $logger ) {
		$this->settings = $settings;
		$this->logger   = $logger;
	}

	/**
	 * Perform JSON REST request.
	 *
	 * @param string $method  HTTP method.
	 * @param string $path    REST path beginning with slash.
	 * @param array  $payload Payload.
	 * @return array|WP_Error
	 */
	public function request( $method, $path, array $payload = array() ) {
		$url = $this->build_url( $path );

		if ( is_wp_error( $url ) ) {
			return $url;
		}

		$args = array(
			'method'      => strtoupper( $method ),
			'timeout'     => absint( $this->settings->get( 'timeout', 30 ) ),
			'redirection' => 3,
			'headers'     => array(
				'Authorization' => $this->get_authorization_header(),
				'Content-Type'  => 'application/json; charset=' . get_option( 'blog_charset' ),
				'Accept'        => 'application/json',
			),
		);

		if ( ! empty( $payload ) ) {
			$args['body'] = wp_json_encode( $payload );
		}

		$response = wp_remote_request( $url, $args );

		return $this->parse_response( $response, $url, $method );
	}

	/**
	 * Upload media to destination.
	 *
	 * @param string $file_path File path.
	 * @param string $filename  Filename.
	 * @param string $mime_type Mime type.
	 * @return array|WP_Error
	 */
	public function upload_media( $file_path, $filename, $mime_type ) {
		$url = $this->build_url( '/wp-json/wp/v2/media' );

		if ( is_wp_error( $url ) ) {
			return $url;
		}

		if ( ! is_readable( $file_path ) ) {
			return new WP_Error( 'ew_rpd_media_not_readable', __( 'El archivo local no se puede leer.', 'ew-remote-post-duplicator' ) );
		}

		$body = file_get_contents( $file_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		if ( false === $body ) {
			return new WP_Error( 'ew_rpd_media_read_failed', __( 'No se pudo leer el archivo local.', 'ew-remote-post-duplicator' ) );
		}

		$args = array(
			'method'      => 'POST',
			'timeout'     => absint( $this->settings->get( 'timeout', 30 ) ),
			'redirection' => 3,
			'headers'     => array(
				'Authorization'       => $this->get_authorization_header(),
				'Content-Disposition' => 'attachment; filename="' . sanitize_file_name( $filename ) . '"',
				'Content-Type'        => $mime_type,
				'Accept'              => 'application/json',
			),
			'body'        => $body,
		);

		$response = wp_remote_post( $url, $args );

		return $this->parse_response( $response, $url, 'POST media' );
	}

	/**
	 * Build absolute destination URL.
	 *
	 * @param string $path REST path.
	 * @return string|WP_Error
	 */
	private function build_url( $path ) {
		$base = untrailingslashit( (string) $this->settings->get( 'destination_url', '' ) );
		$base = esc_url_raw( $base, array( 'http', 'https' ) );

		if ( empty( $base ) || ! $this->is_valid_base_url( $base ) ) {
			return new WP_Error( 'ew_rpd_invalid_url', __( 'URL destino invalida.', 'ew-remote-post-duplicator' ) );
		}

		return $base . '/' . ltrim( $path, '/' );
	}

	/**
	 * Validate destination base URL without rejecting valid public domains
	 * that wp_http_validate_url() may reject in some hosting environments.
	 *
	 * @param string $url URL.
	 * @return bool
	 */
	private function is_valid_base_url( $url ) {
		$parts = wp_parse_url( $url );

		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return false;
		}

		if ( ! in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true ) ) {
			return false;
		}

		$host = strtolower( trim( $parts['host'] ) );

		if ( '' === $host || false !== strpos( $host, ' ' ) ) {
			return false;
		}

		if ( isset( $parts['port'] ) ) {
			$port = absint( $parts['port'] );
			if ( $port < 1 || $port > 65535 ) {
				return false;
			}
		}

		return (bool) preg_match( '/^[a-z0-9.-]+$/', $host );
	}

	/**
	 * Return stable destination key for media mapping.
	 *
	 * @return string
	 */
	public function get_destination_key() {
		$destination_url = untrailingslashit( strtolower( (string) $this->settings->get( 'destination_url', '' ) ) );

		return substr( hash( 'sha256', $destination_url ), 0, 12 );
	}

	/**
	 * Get Basic Auth header.
	 *
	 * @return string
	 */
	private function get_authorization_header() {
		$username = (string) $this->settings->get( 'username', '' );
		$password = (string) $this->settings->get( 'application_password', '' );

		return 'Basic ' . base64_encode( $username . ':' . $password ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Parse remote response.
	 *
	 * @param array|WP_Error $response Response.
	 * @param string         $url      URL.
	 * @param string         $method   Method.
	 * @return array|WP_Error
	 */
	private function parse_response( $response, $url, $method ) {
		if ( is_wp_error( $response ) ) {
			$this->logger->error( 'HTTP request failed.', array( 'method' => $method, 'url' => $url, 'error' => $response->get_error_message() ) );
			return $response;
		}

		$code = absint( wp_remote_retrieve_response_code( $response ) );
		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( $code < 200 || $code >= 300 ) {
			$message = is_array( $data ) && isset( $data['message'] ) ? wp_strip_all_tags( $data['message'] ) : wp_strip_all_tags( $body );
			$error   = new WP_Error( 'ew_rpd_remote_http_error', $message, array( 'status' => $code, 'body' => $data ) );
			$this->logger->error( 'Remote HTTP error.', array( 'method' => $method, 'url' => $url, 'status' => $code, 'message' => $message ) );
			return $error;
		}

		if ( '' !== $body && null === $data && JSON_ERROR_NONE !== json_last_error() ) {
			$error = new WP_Error( 'ew_rpd_invalid_json', __( 'Respuesta JSON invalida del sitio destino.', 'ew-remote-post-duplicator' ) );
			$this->logger->error( 'Invalid JSON response.', array( 'method' => $method, 'url' => $url, 'status' => $code ) );
			return $error;
		}

		return is_array( $data ) ? $data : array();
	}
}
