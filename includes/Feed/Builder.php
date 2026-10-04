<?php
/**
 * Assembles an oJobPub 1.0 document.
 *
 * @package OJobPub
 */

namespace OJobPub\Feed;

defined( 'OJOBPUB_TESTING' ) || defined( 'ABSPATH' ) || exit;

/**
 * Builds the feed array from normalized parts. No WordPress dependency.
 */
final class Builder {

	const VERSION = '1.0';

	/**
	 * Normalizer.
	 *
	 * @var Normalizer
	 */
	private $normalizer;

	/**
	 * Constructor.
	 *
	 * @param Normalizer $normalizer Normalizer.
	 */
	public function __construct( Normalizer $normalizer ) {
		$this->normalizer = $normalizer;
	}

	/**
	 * Normalizes employer and jobs. Jobs that fail validation are skipped and reported
	 * through Normalizer::issues().
	 *
	 * @param array    $employer Raw employer.
	 * @param iterable $jobs     Raw jobs, keyed by reference (e.g. post ID).
	 * @param string   $today    Y-m-d.
	 * @return array|null array( 'employer' => ..., 'jobs' => ... ) or null if the employer is invalid.
	 */
	public function content( array $employer, $jobs, $today ) {
		$this->normalizer->reset_issues();

		$emp = $this->normalizer->employer( $employer );
		if ( null === $emp ) {
			return null;
		}

		$out = array();
		foreach ( $jobs as $reference => $raw ) {
			$job = $this->normalizer->job( (array) $raw, $reference, $today );
			if ( null !== $job ) {
				$out[] = $job;
			}
		}

		// Stable order independent of the query: newest first, then title.
		usort(
			$out,
			static function ( $a, $b ) {
				$cmp = strcmp( $b['publishedAt'], $a['publishedAt'] );
				return 0 !== $cmp ? $cmp : strcmp( $a['title'], $b['title'] );
			}
		);

		return array(
			'employer' => $emp,
			'jobs'     => $out,
		);
	}

	/**
	 * Hash of the content, used to detect real changes for lastUpdated and ETag.
	 *
	 * @param array $content Output of content().
	 * @return string
	 */
	public static function hash( array $content ) {
		return hash( 'sha256', (string) self::encode( $content ) );
	}

	/**
	 * Wraps content into the final document.
	 *
	 * @param array $content      Output of content().
	 * @param int   $last_updated Unix timestamp of the last content change.
	 * @return array
	 */
	public static function document( array $content, $last_updated ) {
		return array(
			'version'     => self::VERSION,
			'lastUpdated' => gmdate( 'Y-m-d\TH:i:s\Z', (int) $last_updated ),
			'employer'    => $content['employer'],
			'jobs'        => $content['jobs'],
		);
	}

	/**
	 * JSON encoding used for output. Empty `jobs` must stay an array.
	 *
	 * @param array $data Data.
	 * @return string|false
	 */
	public static function encode( array $data ) {
		return json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- must run without WordPress.
	}
}
