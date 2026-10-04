<?php
/**
 * Bootstrap, source registry and lifecycle hooks.
 *
 * @package OJobPub
 */

namespace OJobPub;

use OJobPub\Sources\Native_Source;
use OJobPub\Sources\Source;
use OJobPub\Sources\WP_Job_Manager_Source;

defined( 'ABSPATH' ) || exit;

/**
 * Main plugin class.
 */
final class Plugin {

	/**
	 * Registered sources, keyed by id.
	 *
	 * @var Source[]|null
	 */
	private static $sources = null;

	/**
	 * Wires everything up.
	 */
	public static function init() {
		PostType::init();
		Endpoint::init();
		Feed_Service::init();
		Static_File::init();
		Json_Ld::init();
		if ( is_admin() ) {
			Admin::init();
		}
	}

	/**
	 * All sources, extendable via the `ojobpub_sources` filter.
	 *
	 * @return Source[]
	 */
	public static function sources() {
		if ( null === self::$sources ) {
			$list = array( new Native_Source(), new WP_Job_Manager_Source(), new Sources\Job_Postings_Source() );

			/**
			 * Registers additional job sources (adapters for other job plugins or an ATS).
			 *
			 * @param Source[] $list Sources.
			 */
			$list = apply_filters( 'ojobpub_sources', $list );

			self::$sources = array();
			foreach ( $list as $source ) {
				if ( $source instanceof Source ) {
					self::$sources[ $source->id() ] = $source;
				}
			}
		}
		return self::$sources;
	}

	/**
	 * The configured source, or the native one if the configured source is unavailable.
	 *
	 * @return Source
	 */
	public static function source() {
		$sources = self::sources();
		$id      = Settings::get( 'source' );
		if ( isset( $sources[ $id ] ) && $sources[ $id ]->is_available() ) {
			return $sources[ $id ];
		}
		return $sources['native'];
	}

	/**
	 * Activation: rewrite rules and hourly refresh.
	 */
	public static function activate() {
		PostType::register();
		Endpoint::add_rewrite_rule();
		flush_rewrite_rules();
		if ( ! wp_next_scheduled( Feed_Service::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', Feed_Service::CRON_HOOK );
		}
	}

	/**
	 * Deactivation: remove cron, our static file and rewrite rules. Data stays.
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( Feed_Service::CRON_HOOK );
		Static_File::remove();
		delete_transient( Feed_Service::CACHE_KEY );
		flush_rewrite_rules();
	}
}
