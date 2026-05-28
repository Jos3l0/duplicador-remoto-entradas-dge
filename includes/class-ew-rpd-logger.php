<?php
/**
 * Logger service.
 *
 * @package EW_Remote_Post_Duplicator
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * File logger.
 */
class EW_RPD_Logger {

	/**
	 * Relative directory under uploads.
	 *
	 * @var string
	 */
	const LOG_DIR = 'ew-remote-post-duplicator';

	/**
	 * Log filename.
	 *
	 * @var string
	 */
	const LOG_FILE = 'sync.log';

	/**
	 * Ensure log directory exists.
	 *
	 * @return void
	 */
	public function ensure_log_directory() {
		$directory = $this->get_log_directory();

		if ( ! file_exists( $directory ) ) {
			wp_mkdir_p( $directory );
		}

		if ( is_dir( $directory ) && is_writable( $directory ) ) {
			$index_file = trailingslashit( $directory ) . 'index.php';
			$htaccess   = trailingslashit( $directory ) . '.htaccess';

			if ( ! file_exists( $index_file ) ) {
				file_put_contents( $index_file, "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			}

			if ( ! file_exists( $htaccess ) ) {
				file_put_contents( $htaccess, "Deny from all\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			}
		}
	}

	/**
	 * Get log directory path.
	 *
	 * @return string
	 */
	public function get_log_directory() {
		$uploads = wp_upload_dir();

		return trailingslashit( $uploads['basedir'] ) . self::LOG_DIR;
	}

	/**
	 * Get log file path.
	 *
	 * @return string
	 */
	public function get_log_file() {
		return trailingslashit( $this->get_log_directory() ) . self::LOG_FILE;
	}

	/**
	 * Log info message.
	 *
	 * @param string $message Message.
	 * @param array  $context Context.
	 * @return void
	 */
	public function info( $message, array $context = array() ) {
		$this->write( 'INFO', $message, $context );
	}

	/**
	 * Log warning message.
	 *
	 * @param string $message Message.
	 * @param array  $context Context.
	 * @return void
	 */
	public function warning( $message, array $context = array() ) {
		$this->write( 'WARNING', $message, $context );
	}

	/**
	 * Log error message.
	 *
	 * @param string $message Message.
	 * @param array  $context Context.
	 * @return void
	 */
	public function error( $message, array $context = array() ) {
		$this->write( 'ERROR', $message, $context );
	}

	/**
	 * Read last log lines.
	 *
	 * @param int $lines Number of lines.
	 * @return array
	 */
	public function read_last_lines( $lines = 80 ) {
		$file = $this->get_log_file();

		if ( ! file_exists( $file ) || ! is_readable( $file ) ) {
			return array();
		}

		$content = file( $file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file

		if ( ! is_array( $content ) ) {
			return array();
		}

		return array_slice( $content, -absint( $lines ) );
	}

	/**
	 * Write log line.
	 *
	 * @param string $level   Log level.
	 * @param string $message Message.
	 * @param array  $context Context.
	 * @return void
	 */
	private function write( $level, $message, array $context = array() ) {
		$this->ensure_log_directory();

		$file = $this->get_log_file();

		if ( ! is_dir( dirname( $file ) ) || ! is_writable( dirname( $file ) ) ) {
			return;
		}

		$context = $this->mask_sensitive_context( $context );
		$line    = sprintf(
			"[%s] [%s] %s %s\n",
			gmdate( 'c' ),
			sanitize_key( $level ),
			sanitize_text_field( $message ),
			empty( $context ) ? '' : wp_json_encode( $context )
		);

		file_put_contents( $file, $line, FILE_APPEND | LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	}

	/**
	 * Mask sensitive values.
	 *
	 * @param array $context Context.
	 * @return array
	 */
	private function mask_sensitive_context( array $context ) {
		$sensitive_keys = array( 'password', 'application_password', 'authorization', 'auth' );

		foreach ( $context as $key => $value ) {
			if ( in_array( strtolower( (string) $key ), $sensitive_keys, true ) ) {
				$context[ $key ] = '***';
			}
		}

		return $context;
	}
}
