<?php
/**
 * Optional static copy of the feed for hosts that never pass /.well-known/ to WordPress.
 *
 * @package OJobPub
 */

namespace OJobPub;

defined( 'ABSPATH' ) || exit;

/**
 * Writes the feed to <home path>/.well-known/ojobpub.json on every regeneration.
 *
 * Many hosters answer /.well-known/ directly from disk (ACME challenges) and never hand
 * the request to index.php, so the rewrite rule cannot work there. The file is only
 * removed again if its content is still the one this plugin wrote.
 */
final class Static_File {

	const STATE_KEY = 'ojobpub_static_file';

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'ojobpub_feed_generated', array( __CLASS__, 'on_generated' ) );
		add_action( 'update_option_' . Settings::OPTION, array( __CLASS__, 'on_settings_change' ), 20, 2 );
	}

	/**
	 * Target path on disk.
	 *
	 * @return string
	 */
	public static function path() {
		if ( ! function_exists( 'get_home_path' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		return trailingslashit( get_home_path() ) . Endpoint::PATH;
	}

	/**
	 * Writes the file after each regeneration if enabled.
	 *
	 * @param array $feed Feed data.
	 */
	public static function on_generated( $feed ) {
		if ( Settings::get( 'static_file' ) ) {
			self::write( $feed['json'] );
		}
	}

	/**
	 * Removes the file when the option is switched off.
	 *
	 * @param array $old_value Previous settings.
	 * @param array $new_value New settings.
	 */
	public static function on_settings_change( $old_value, $new_value ) {
		if ( ! empty( $old_value['static_file'] ) && empty( $new_value['static_file'] ) ) {
			self::remove();
		}
	}

	/**
	 * Direct filesystem access or null. Credentials prompts are not possible in the background.
	 *
	 * @return \WP_Filesystem_Base|null
	 */
	private static function filesystem() {
		global $wp_filesystem;
		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		if ( 'direct' !== get_filesystem_method() ) {
			return null;
		}
		if ( ! WP_Filesystem() ) {
			return null;
		}
		return $wp_filesystem;
	}

	/**
	 * Writes the file.
	 *
	 * @param string $json Feed JSON.
	 * @return true|\WP_Error
	 */
	public static function write( $json ) {
		$fs = self::filesystem();
		if ( null === $fs ) {
			return self::fail( __( 'No direct filesystem access; the static file cannot be written.', 'ojobpub' ) );
		}
		$path = self::path();
		$dir  = dirname( $path );
		if ( ! $fs->is_dir( $dir ) && ! $fs->mkdir( $dir, FS_CHMOD_DIR ) ) {
			/* translators: %s: directory path */
			return self::fail( sprintf( __( 'Could not create %s.', 'ojobpub' ), $dir ) );
		}

		$state = get_option( self::STATE_KEY );
		if ( $fs->exists( $path ) && ( ! is_array( $state ) || hash( 'sha256', (string) $fs->get_contents( $path ) ) !== $state['hash'] ) ) {
			// Never overwrite a file somebody else put there.
			/* translators: %s: file path */
			return self::fail( sprintf( __( '%s exists and was not written by this plugin; it was left untouched.', 'ojobpub' ), $path ) );
		}

		if ( ! $fs->put_contents( $path, $json, FS_CHMOD_FILE ) ) {
			/* translators: %s: file path */
			return self::fail( sprintf( __( 'Could not write %s.', 'ojobpub' ), $path ) );
		}
		update_option(
			self::STATE_KEY,
			array(
				'path'  => $path,
				'hash'  => hash( 'sha256', $json ),
				'time'  => time(),
				'error' => '',
			),
			false
		);
		return true;
	}

	/**
	 * Removes the file if it is still ours.
	 */
	public static function remove() {
		$state = get_option( self::STATE_KEY );
		$fs    = self::filesystem();
		if ( is_array( $state ) && ! empty( $state['path'] ) && null !== $fs && $fs->exists( $state['path'] )
			&& hash( 'sha256', (string) $fs->get_contents( $state['path'] ) ) === $state['hash'] ) {
			$fs->delete( $state['path'] );
		}
		delete_option( self::STATE_KEY );
	}

	/**
	 * Status for the admin screen.
	 *
	 * @return array|null
	 */
	public static function status() {
		$state = get_option( self::STATE_KEY );
		return is_array( $state ) ? $state : null;
	}

	/**
	 * Records a failure.
	 *
	 * @param string $message Message.
	 * @return \WP_Error
	 */
	private static function fail( $message ) {
		$state          = get_option( self::STATE_KEY );
		$state          = is_array( $state ) ? $state : array( 'hash' => '' );
		$state['error'] = $message;
		update_option( self::STATE_KEY, $state, false );
		return new \WP_Error( 'ojobpub_static_file', $message );
	}
}
