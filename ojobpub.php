<?php
/**
 * Plugin Name:       oJobPub
 * Plugin URI:        https://docs.letsemploy.org
 * Description:       Publishes your job openings as an open oJobPub feed at /.well-known/ojobpub.json, so job boards and search engines can find them without an API key.
 * Version:           0.2.1
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Author:            oJobPub contributors
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       ojobpub
 * Domain Path:       /languages
 *
 * @package OJobPub
 */

defined( 'ABSPATH' ) || exit;

define( 'OJOBPUB_VERSION', '0.2.1' );
define( 'OJOBPUB_FILE', __FILE__ );
define( 'OJOBPUB_DIR', plugin_dir_path( __FILE__ ) );

spl_autoload_register(
	static function ( $class_name ) {
		$prefix = 'OJobPub\\';
		if ( 0 !== strpos( $class_name, $prefix ) ) {
			return;
		}
		$file = OJOBPUB_DIR . 'includes/' . str_replace( '\\', '/', substr( $class_name, strlen( $prefix ) ) ) . '.php';
		if ( is_readable( $file ) ) {
			require $file;
		}
	}
);

register_activation_hook( __FILE__, array( 'OJobPub\\Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'OJobPub\\Plugin', 'deactivate' ) );

OJobPub\Plugin::init();
