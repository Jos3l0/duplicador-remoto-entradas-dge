<?php
/**
 * Media synchronization service.
 *
 * @package EW_Remote_Post_Duplicator
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles featured and embedded media upload.
 */
class EW_RPD_Media {

	/**
	 * HTTP client.
	 *
	 * @var EW_RPD_HTTP_Client
	 */
	private $client;

	/**
	 * Logger.
	 *
	 * @var EW_RPD_Logger
	 */
	private $logger;

	/**
	 * Constructor.
	 *
	 * @param EW_RPD_HTTP_Client $client HTTP client.
	 * @param EW_RPD_Logger      $logger Logger.
	 */
	public function __construct( EW_RPD_HTTP_Client $client, EW_RPD_Logger $logger ) {
		$this->client = $client;
		$this->logger = $logger;
	}

	/**
	 * Sync featured image if available.
	 *
	 * @param int $post_id Local post ID.
	 * @return int
	 */
	public function sync_featured_image( $post_id ) {
		$attachment_id = get_post_thumbnail_id( $post_id );

		if ( ! $attachment_id ) {
			return 0;
		}

		$result = $this->sync_attachment( $attachment_id, $post_id );

		if ( is_wp_error( $result ) || empty( $result['id'] ) ) {
			return 0;
		}

		$this->logger->info(
			'Featured image synchronized.',
			array(
				'post_id'         => $post_id,
				'remote_media_id' => absint( $result['id'] ),
			)
		);

		return absint( $result['id'] );
	}

	/**
	 * Sync any local attachment to the destination media library.
	 *
	 * @param int $attachment_id Local attachment ID.
	 * @param int $context_post_id Optional local post ID for logging.
	 * @return array|WP_Error Remote media object subset or error.
	 */
	public function sync_attachment( $attachment_id, $context_post_id = 0 ) {
		$attachment_id = absint( $attachment_id );

		if ( ! $attachment_id || 'attachment' !== get_post_type( $attachment_id ) ) {
			return new WP_Error( 'ew_rpd_invalid_attachment', __( 'El medio local no es un adjunto valido.', 'ew-remote-post-duplicator' ) );
		}

		$destination_key = $this->client->get_destination_key();
		$meta_prefix     = '_ew_rpd_remote_media_' . $destination_key;
		$cached_id       = absint( get_post_meta( $attachment_id, $meta_prefix . '_id', true ) );
		$cached_url      = (string) get_post_meta( $attachment_id, $meta_prefix . '_url', true );
		$cached_sizes    = get_post_meta( $attachment_id, $meta_prefix . '_sizes', true );

		if ( $cached_id > 0 && '' !== $cached_url ) {
			return array(
				'id'         => $cached_id,
				'source_url' => esc_url_raw( $cached_url ),
				'sizes'      => is_array( $cached_sizes ) ? $cached_sizes : array(),
			);
		}

		$file_path = get_attached_file( $attachment_id );

		if ( ! $file_path || ! file_exists( $file_path ) ) {
			$this->logger->warning(
				'Attachment file not found.',
				array(
					'post_id'       => absint( $context_post_id ),
					'attachment_id' => $attachment_id,
				)
			);
			return new WP_Error( 'ew_rpd_attachment_file_missing', __( 'No existe el archivo fisico del medio local.', 'ew-remote-post-duplicator' ) );
		}

		$filetype = wp_check_filetype( basename( $file_path ) );
		$mime     = ! empty( $filetype['type'] ) ? $filetype['type'] : 'application/octet-stream';
		$result   = $this->client->upload_media( $file_path, basename( $file_path ), $mime );

		if ( is_wp_error( $result ) ) {
			$this->logger->error(
				'Attachment upload failed.',
				array(
					'post_id'       => absint( $context_post_id ),
					'attachment_id' => $attachment_id,
					'error'         => $result->get_error_message(),
				)
			);
			return $result;
		}

		if ( empty( $result['id'] ) ) {
			return new WP_Error( 'ew_rpd_missing_media_id', __( 'El destino no devolvio ID para el medio.', 'ew-remote-post-duplicator' ) );
		}

		$remote_id  = absint( $result['id'] );
		$remote_url = ! empty( $result['source_url'] ) ? esc_url_raw( $result['source_url'] ) : '';
		$sizes      = $this->extract_remote_sizes( $result );

		update_post_meta( $attachment_id, $meta_prefix . '_id', $remote_id );
		update_post_meta( $attachment_id, $meta_prefix . '_url', $remote_url );
		update_post_meta( $attachment_id, $meta_prefix . '_sizes', $sizes );

		$this->update_remote_media_metadata( $attachment_id, $remote_id );

		$this->logger->info(
			'Attachment synchronized.',
			array(
				'post_id'         => absint( $context_post_id ),
				'attachment_id'   => $attachment_id,
				'remote_media_id' => $remote_id,
			)
		);

		return array(
			'id'         => $remote_id,
			'source_url' => $remote_url,
			'sizes'      => $sizes,
		);
	}

	/**
	 * Extract remote generated image sizes from REST response.
	 *
	 * @param array $result REST media response.
	 * @return array
	 */
	private function extract_remote_sizes( array $result ) {
		$sizes = array();

		if ( empty( $result['media_details']['sizes'] ) || ! is_array( $result['media_details']['sizes'] ) ) {
			return $sizes;
		}

		foreach ( $result['media_details']['sizes'] as $size_name => $size_data ) {
			if ( ! empty( $size_data['source_url'] ) ) {
				$sizes[ sanitize_key( $size_name ) ] = esc_url_raw( $size_data['source_url'] );
			}
		}

		return $sizes;
	}

	/**
	 * Update title, caption, description and alt text on the remote media item.
	 *
	 * @param int $attachment_id Local attachment ID.
	 * @param int $remote_id Remote media ID.
	 * @return void
	 */
	private function update_remote_media_metadata( $attachment_id, $remote_id ) {
		$attachment = get_post( $attachment_id );

		if ( ! $attachment instanceof WP_Post || ! $remote_id ) {
			return;
		}

		$payload = array(
			'title'       => $attachment->post_title,
			'caption'     => $attachment->post_excerpt,
			'description' => $attachment->post_content,
			'alt_text'    => (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ),
		);

		$result = $this->client->request( 'POST', '/wp-json/wp/v2/media/' . absint( $remote_id ), $payload );

		if ( is_wp_error( $result ) ) {
			$this->logger->warning(
				'Remote media metadata update failed.',
				array(
					'attachment_id'   => absint( $attachment_id ),
					'remote_media_id' => absint( $remote_id ),
					'error'           => $result->get_error_message(),
				)
			);
		}
	}

	/**
	 * Sync any file by URL to the destination media library.
	 *
	 * Handles PDFs, images, documents, and any file type
	 * referenced by local URL - even if not registered as a WordPress attachment.
	 *
	 * @param string $url             URL to sync.
	 * @param int    $context_post_id Optional local post ID for logging.
	 * @return array|WP_Error Remote media object subset or error.
	 */
	public function sync_file_by_url( $url, $context_post_id = 0 ) {
		$url = esc_url_raw( $url );

		if ( '' === $url ) {
			return new WP_Error( 'ew_rpd_empty_url', __( 'URL vacia.', 'ew-remote-post-duplicator' ) );
		}

		if ( ! $this->is_local_url( $url ) ) {
			return new WP_Error( 'ew_rpd_external_url', __( 'URL externa; no se migra.', 'ew-remote-post-duplicator' ) );
		}

		$attachment_id = absint( attachment_url_to_postid( $url ) );

		if ( $attachment_id > 0 && 'attachment' === get_post_type( $attachment_id ) ) {
			return $this->sync_attachment( $attachment_id, $context_post_id );
		}

		$file_path = $this->url_to_local_path( $url );

		if ( ! $file_path || ! file_exists( $file_path ) ) {
			$this->logger->warning( 'File not found by URL.', array( 'post_id' => absint( $context_post_id ), 'url' => $url ) );
			return new WP_Error( 'ew_rpd_file_not_found', __( 'Archivo local no encontrado.', 'ew-remote-post-duplicator' ) );
		}

		$filetype = wp_check_filetype( basename( $file_path ) );
		$mime     = ! empty( $filetype['type'] ) ? $filetype['type'] : 'application/octet-stream';
		$result   = $this->client->upload_media( $file_path, basename( $file_path ), $mime );

		if ( is_wp_error( $result ) ) {
			$this->logger->error( 'File upload failed.', array( 'post_id' => absint( $context_post_id ), 'url' => $url, 'file_path' => $file_path, 'error' => $result->get_error_message() ) );
			return $result;
		}

		if ( empty( $result['id'] ) ) {
			return new WP_Error( 'ew_rpd_missing_media_id', __( 'El destino no devolvio ID para el medio.', 'ew-remote-post-duplicator' ) );
		}

		$remote_id  = absint( $result['id'] );
		$remote_url = ! empty( $result['source_url'] ) ? esc_url_raw( $result['source_url'] ) : '';
		$sizes      = $this->extract_remote_sizes( $result );

		$this->logger->info( 'File synchronized by URL.', array( 'post_id' => absint( $context_post_id ), 'url' => $url, 'remote_media_id' => $remote_id ) );

		return array( 'id' => $remote_id, 'source_url' => $remote_url, 'sizes' => $sizes );
	}

	/**
	 * Check if a URL belongs to the local site.
	 *
	 * Supports additional local domains via the `ew_rpd_local_domains` filter.
	 *
	 * @param string $url URL to check.
	 * @return bool
	 */
	public function is_local_url( $url ) {
		$url_lower = strtolower( $url );
		$site_url  = untrailingslashit( strtolower( site_url() ) );
		$home_url  = untrailingslashit( strtolower( home_url() ) );

		if ( 0 === strpos( $url_lower, $site_url . '/' ) || 0 === strpos( $url_lower, $home_url . '/' ) ) {
			return true;
		}

		if ( 0 === strpos( $url_lower, '/wp-content/' ) || 0 === strpos( $url_lower, '/wp-includes/' ) ) {
			return true;
		}

		$upload_dir = wp_get_upload_dir();
		if ( ! empty( $upload_dir['baseurl'] ) ) {
			$baseurl = untrailingslashit( strtolower( $upload_dir['baseurl'] ) );
			if ( 0 === strpos( $url_lower, $baseurl . '/' ) ) {
				return true;
			}
		}

		$additional_domains = apply_filters( 'ew_rpd_local_domains', array() );
		if ( is_array( $additional_domains ) ) {
			foreach ( $additional_domains as $domain ) {
				$domain = untrailingslashit( strtolower( (string) $domain ) );
				if ( '' !== $domain && 0 === strpos( $url_lower, $domain . '/' ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Convert a local URL to a local file path.
	 *
	 * @param string $url Local URL.
	 * @return string|false
	 */
	private function url_to_local_path( $url ) {
		$url = esc_url_raw( $url );

		$upload_dir = wp_get_upload_dir();
		if ( ! empty( $upload_dir['baseurl'] ) && ! empty( $upload_dir['basedir'] ) ) {
			$baseurl = untrailingslashit( $upload_dir['baseurl'] );
			$basedir = untrailingslashit( $upload_dir['basedir'] );

			if ( 0 === strpos( $url, $baseurl . '/' ) ) {
				return $basedir . substr( $url, strlen( $baseurl ) );
			}
		}

		$site_url = untrailingslashit( site_url() );
		if ( 0 === strpos( $url, $site_url . '/' ) ) {
			return ABSPATH . substr( $url, strlen( $site_url ) + 1 );
		}

		$home_url = untrailingslashit( home_url() );
		if ( 0 === strpos( $url, $home_url . '/' ) ) {
			return ABSPATH . substr( $url, strlen( $home_url ) + 1 );
		}

		if ( 0 === strpos( $url, '/' ) ) {
			return ABSPATH . ltrim( $url, '/' );
		}

		return false;
	}
}
