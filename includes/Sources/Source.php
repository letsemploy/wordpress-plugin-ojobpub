<?php
/**
 * Data source contract.
 *
 * @package OJobPub
 */

namespace OJobPub\Sources;

defined( 'ABSPATH' ) || exit;

/**
 * A source reads job data from somewhere in WordPress and yields raw oJobPub job arrays.
 *
 * Third-party adapters can be registered with the `ojobpub_sources` filter.
 */
interface Source {

	/**
	 * Stable identifier stored in the settings, e.g. "native".
	 *
	 * @return string
	 */
	public function id();

	/**
	 * Human readable name for the settings screen.
	 *
	 * @return string
	 */
	public function label();

	/**
	 * Whether the source can be used on this site (e.g. the other plugin is active).
	 *
	 * @return bool
	 */
	public function is_available();

	/**
	 * Post types whose changes must invalidate the feed cache.
	 *
	 * @return string[]
	 */
	public function post_types();

	/**
	 * Raw job arrays keyed by a reference (usually the post ID). Keys follow the
	 * oJobPub field names; see OJobPub\Feed\Normalizer::job().
	 *
	 * @param array $defaults Site defaults: language, country, jobType.
	 * @return iterable
	 */
	public function jobs( array $defaults );
}
