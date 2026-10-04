<?php
/**
 * Feed generation, caching and change tracking.
 *
 * @package OJobPub
 */

namespace OJobPub;

use OJobPub\Feed\Builder;
use OJobPub\Feed\Normalizer;

defined( 'ABSPATH' ) || exit;

/**
 * Produces the current feed and keeps `lastUpdated` honest: it only moves when the
 * published content actually changes (including jobs dropping out after applyBefore).
 */
final class Feed_Service {

	const CACHE_KEY = 'ojobpub_feed';
	const STATE_KEY = 'ojobpub_state';
	const CRON_HOOK = 'ojobpub_refresh';
	const CACHE_TTL = HOUR_IN_SECONDS;

	/**
	 * Whether an invalidation is pending for this request.
	 *
	 * @var bool
	 */
	private static $dirty = false;

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'save_post', array( __CLASS__, 'on_post_change' ), 99 );
		add_action( 'deleted_post', array( __CLASS__, 'on_post_change' ) );
		add_action( 'trashed_post', array( __CLASS__, 'on_post_change' ) );
		add_action( 'untrashed_post', array( __CLASS__, 'on_post_change' ) );
		add_action( 'set_object_terms', array( __CLASS__, 'on_post_change' ) );
		add_action( 'added_post_meta', array( __CLASS__, 'on_meta_change' ), 10, 2 );
		add_action( 'updated_post_meta', array( __CLASS__, 'on_meta_change' ), 10, 2 );
		add_action( 'deleted_post_meta', array( __CLASS__, 'on_meta_change' ), 10, 2 );
		add_action( 'update_option_' . Settings::OPTION, array( __CLASS__, 'invalidate' ) );
		add_action( 'update_option_blogname', array( __CLASS__, 'invalidate' ) );
		add_action( 'edited_term', array( __CLASS__, 'on_term_change' ), 10, 3 );
		add_action( self::CRON_HOOK, array( __CLASS__, 'refresh' ) );
		add_action( 'shutdown', array( __CLASS__, 'flush' ) );
	}

	/**
	 * Post-level change.
	 *
	 * @param int $post_id Post ID.
	 */
	public static function on_post_change( $post_id ) {
		if ( in_array( get_post_type( $post_id ), Plugin::source()->post_types(), true ) ) {
			self::invalidate();
		}
	}

	/**
	 * Meta-level change.
	 *
	 * @param int|int[] $meta_ids Unused.
	 * @param int       $post_id  Post ID.
	 */
	public static function on_meta_change( $meta_ids, $post_id ) {
		self::on_post_change( $post_id );
	}

	/**
	 * Term renamed: affects category/tag names or job type mapping.
	 *
	 * @param int    $term_id  Unused.
	 * @param int    $tt_id    Unused.
	 * @param string $taxonomy Taxonomy.
	 */
	public static function on_term_change( $term_id, $tt_id, $taxonomy ) {
		foreach ( Plugin::source()->post_types() as $post_type ) {
			if ( in_array( $taxonomy, get_object_taxonomies( $post_type ), true ) ) {
				self::invalidate();
				return;
			}
		}
	}

	/**
	 * Marks the feed stale; the rebuild happens once at the end of the request.
	 */
	public static function invalidate() {
		self::$dirty = true;
	}

	/**
	 * Applies a pending invalidation.
	 */
	public static function flush() {
		if ( ! self::$dirty ) {
			return;
		}
		self::$dirty = false;
		self::refresh();
	}

	/**
	 * Drops the cache and rebuilds. Runs hourly via cron so expired jobs disappear
	 * even from a static file, and whenever relevant data changes.
	 */
	public static function refresh() {
		delete_transient( self::CACHE_KEY );
		self::get();
	}

	/**
	 * Current feed, from cache if possible.
	 *
	 * @return array|null Keys json, etag, last_updated, count, issues; null if the employer is not configured.
	 */
	public static function get() {
		$cached = get_transient( self::CACHE_KEY );
		if ( is_array( $cached ) && isset( $cached['json'] ) ) {
			return $cached;
		}

		$feed = self::build();
		if ( null === $feed ) {
			return null;
		}

		set_transient( self::CACHE_KEY, $feed, self::CACHE_TTL );

		/**
		 * Fires after the feed has been regenerated.
		 *
		 * @param array $feed Keys json, etag, last_updated, count, issues.
		 */
		do_action( 'ojobpub_feed_generated', $feed );

		return $feed;
	}

	/**
	 * Builds the feed without touching the cache.
	 *
	 * @return array|null
	 */
	public static function build() {
		$normalizer = new Normalizer();
		$builder    = new Builder( $normalizer );
		$source     = Plugin::source();

		/**
		 * Filters the raw employer data before normalization.
		 *
		 * @param array $employer Keys name, city, country, industry, url.
		 */
		$employer = apply_filters( 'ojobpub_employer', Settings::employer() );

		/**
		 * Filters the raw jobs before normalization.
		 *
		 * @param iterable $jobs   Raw jobs keyed by reference.
		 * @param Sources\Source $source Active source.
		 */
		$jobs = apply_filters( 'ojobpub_raw_jobs', $source->jobs( Settings::defaults_for_jobs() ), $source );

		$content = $builder->content( $employer, $jobs, current_datetime()->format( 'Y-m-d' ) );
		if ( null === $content ) {
			return null;
		}

		$hash  = Builder::hash( $content );
		$state = get_option( self::STATE_KEY );
		if ( ! is_array( $state ) || ! isset( $state['hash'], $state['time'] ) || $state['hash'] !== $hash ) {
			$state = array(
				'hash' => $hash,
				'time' => time(),
			);
			update_option( self::STATE_KEY, $state, false );
		}

		$document = Builder::document( $content, (int) $state['time'] );

		/**
		 * Filters the final document right before encoding.
		 *
		 * The output must stay valid against the oJobPub schema (no additional properties).
		 *
		 * @param array $document oJobPub document.
		 */
		$document = apply_filters( 'ojobpub_document', $document );

		return array(
			'json'         => Builder::encode( $document ),
			'etag'         => '"' . substr( $hash, 0, 32 ) . '"',
			'last_updated' => (int) $state['time'],
			'count'        => count( $content['jobs'] ),
			'issues'       => $normalizer->issues(),
		);
	}
}
