<?php
/**
 * Uninstall: removes settings and caches. Job posts are content and stay, like
 * WordPress keeps posts of any deactivated post type.
 *
 * @package OJobPub
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'ojobpub_settings' );
delete_option( 'ojobpub_state' );
delete_option( 'ojobpub_static_file' );
delete_transient( 'ojobpub_feed' );
