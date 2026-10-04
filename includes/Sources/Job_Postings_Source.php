<?php
/**
 * Job Postings (BlueGlass) adapter.
 *
 * @package OJobPub
 */

namespace OJobPub\Sources;

use OJobPub\Feed\Country;
use OJobPub\Feed\Normalizer;

defined( 'ABSPATH' ) || exit;

/**
 * Reads published offers from the "Job Postings" plugin (slug job-postings, CPT `jobs`).
 *
 * Field mapping (meta keys as of Job Postings 2.8):
 * - title: `position_title`, else the post title
 * - description: `position_description` (HTML; the post editor is disabled in that plugin)
 * - jobType: `position_employment_type`, schema.org values, may be a list (checkboxes)
 * - locations: `position_job_location_addressLocality` / `_addressCountry`, else the
 *   free-text `position_job_location`; countries are stored as names and resolved to ISO codes
 * - workType: `position_job_location_remote` = "on" → remote
 * - applyBefore: `position_valid_through_date` (Y-m-d), written alongside the free-text
 *   `position_valid_through`; only trusted while the latter is set, see valid_through()
 * - publishedAt: `position_date_posted`, else the post date
 * - salary: `position_base_salary`, `position_base_salary_upto`, `position_base_salary_unittext`;
 *   the currency is a per-language symbol option (`jobs_currency_symbol_<lang>`)
 * - category: taxonomy `jobs_category`
 * - tags: `position_skills` if it is a short comma or line separated list
 *
 * The plugin prints its own schema.org JobPosting, so no JSON-LD is added here.
 */
final class Job_Postings_Source implements Source {

	const POST_TYPE = 'jobs';
	const TAXONOMY  = 'jobs_category';

	/**
	 * {@inheritDoc}
	 */
	public function id() {
		return 'job-postings';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label() {
		return __( 'Job Postings', 'ojobpub' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * The post type name "jobs" is too generic to rely on, so the plugin class is required.
	 */
	public function is_available() {
		return class_exists( 'Job_Postings' ) && post_type_exists( self::POST_TYPE );
	}

	/**
	 * {@inheritDoc}
	 */
	public function post_types() {
		return array( self::POST_TYPE );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array $defaults Site defaults.
	 */
	public function jobs( array $defaults ) {
		$ids = get_posts(
			array(
				'post_type'        => self::POST_TYPE,
				'post_status'      => 'publish',
				'posts_per_page'   => (int) apply_filters( 'ojobpub_max_jobs', 500 ),
				'fields'           => 'ids',
				'no_found_rows'    => true,
				'suppress_filters' => false,
			)
		);
		if ( empty( $ids ) ) {
			return array();
		}
		update_meta_cache( 'post', $ids );
		update_object_term_cache( $ids, self::POST_TYPE );

		$currency = $this->currency();
		$jobs     = array();
		foreach ( $ids as $id ) {
			$jobs[ $id ] = $this->raw_job( get_post( $id ), $defaults, $currency );
		}
		return $jobs;
	}

	/**
	 * Maps one offer.
	 *
	 * @param \WP_Post $post     Post.
	 * @param array    $defaults Site defaults.
	 * @param string   $currency ISO currency or ''.
	 * @return array
	 */
	private function raw_job( \WP_Post $post, array $defaults, $currency ) {
		$m = static function ( $key ) use ( $post ) {
			return get_post_meta( $post->ID, $key, true );
		};

		$title = trim( (string) $m( 'position_title' ) );
		$date  = Normalizer::date( $m( 'position_date_posted' ) );

		$job = array(
			'title'       => '' !== $title ? $title : get_the_title( $post ),
			'language'    => $defaults['language'],
			'publishedAt' => null !== $date ? $date : get_post_time( 'Y-m-d', true, $post ),
			'jobType'     => $this->job_type( $m( 'position_employment_type' ), $defaults['jobType'] ),
			'locations'   => $this->locations( $post, $defaults['country'] ),
			'url'         => get_permalink( $post ),
			'description' => (string) $m( 'position_description' ),
			'referenceId' => (string) $post->ID,
			'applyBefore' => $this->valid_through( $m( 'position_valid_through' ), $m( 'position_valid_through_date' ) ),
			'workType'    => 'on' === $m( 'position_job_location_remote' ) ? 'remote' : '',
			'tags'        => $this->tags( $m( 'position_skills' ) ),
		);

		$categories = taxonomy_exists( self::TAXONOMY ) ? get_the_terms( $post, self::TAXONOMY ) : false;
		if ( is_array( $categories ) && ! empty( $categories ) ) {
			$job['category'] = $categories[0]->name;
		}

		$base = trim( (string) $m( 'position_base_salary' ) );
		if ( '' !== $base ) {
			$range = Normalizer::salary_range( $base );
			$upto  = Normalizer::amount( trim( (string) $m( 'position_base_salary_upto' ) ) );
			if ( null !== $upto ) {
				$range['max'] = $upto;
			}
			$units         = array(
				'HOUR'  => 'hourly',
				'DAY'   => 'daily',
				'WEEK'  => 'weekly',
				'MONTH' => 'monthly',
				'YEAR'  => 'yearly',
			);
			$unit          = strtoupper( trim( (string) $m( 'position_base_salary_unittext' ) ) );
			$job['salary'] = array_merge(
				$range,
				array(
					'currency' => $currency,
					'interval' => isset( $units[ $unit ] ) ? $units[ $unit ] : '',
				)
			);
		}

		/**
		 * Filters the raw job mapped from a Job Postings offer.
		 *
		 * @param array    $job  Raw oJobPub job.
		 * @param \WP_Post $post Offer.
		 */
		return apply_filters( 'ojobpub_job_postings_job', $job, $post );
	}

	/**
	 * Maps schema.org employment types (string or list) to an oJobPub job type.
	 *
	 * @param mixed  $value    Stored value.
	 * @param string $fallback Site default.
	 * @return string
	 */
	private function job_type( $value, $fallback ) {
		$map = array(
			'FULL_TIME'  => 'permanent',
			'PART_TIME'  => 'permanent',
			'CONTRACTOR' => 'contract',
			'TEMPORARY'  => 'temporary',
			'INTERN'     => 'internship',
			'VOLUNTEER'  => 'volunteer',
			'PER_DIEM'   => 'temporary',
		);
		foreach ( (array) $value as $type ) {
			$type = strtoupper( trim( (string) $type ) );
			if ( isset( $map[ $type ] ) ) {
				return $map[ $type ];
			}
		}
		return $fallback;
	}

	/**
	 * One location from the address fields or the free-text field.
	 *
	 * @param \WP_Post $post            Post.
	 * @param string   $default_country Site default.
	 * @return array
	 */
	private function locations( \WP_Post $post, $default_country ) {
		$city         = trim( (string) get_post_meta( $post->ID, 'position_job_location_addressLocality', true ) );
		$country_text = trim( (string) get_post_meta( $post->ID, 'position_job_location_addressCountry', true ) );
		$country      = Country::code( $country_text );

		if ( '' === $city ) {
			$text = trim( wp_strip_all_tags( (string) get_post_meta( $post->ID, 'position_job_location', true ) ) );
			if ( '' !== $text ) {
				$parts = array_map( 'trim', explode( ',', $text ) );
				$last  = count( $parts ) > 1 ? Country::code( end( $parts ) ) : null;
				if ( null !== $last ) {
					array_pop( $parts );
					$country = null !== $country ? $country : $last;
				}
				$city = $parts[0];
			}
		}

		// Assume the site's country only when no country was entered at all.
		if ( null === $country && '' === $country_text ) {
			$country = $default_country;
		}

		$locations = array(
			array(
				'city'    => $city,
				'country' => (string) $country,
			),
		);

		// Remote offers may list the countries applicants must live in.
		$remote = get_post_meta( $post->ID, 'job_remote_data', true );
		foreach ( is_array( $remote ) ? $remote : array() as $entry ) {
			$name = is_array( $entry ) && isset( $entry['name'] ) ? $entry['name'] : $entry;
			// Entries of type "state" read like "Texas, USA"; the country is the last part.
			$parts = explode( ',', is_scalar( $name ) ? (string) $name : '' );
			$code  = Country::code( end( $parts ) );
			if ( null !== $code ) {
				$locations[] = array( 'country' => $code );
			}
		}
		return $locations;
	}

	/**
	 * Application deadline. Job Postings stores the date as typed (dd.mm.yy picker) and a
	 * normalized Y-m-d copy, but writes "1970-01-01" to the copy when the field is empty,
	 * which would expire every job.
	 *
	 * @param mixed $text       `position_valid_through`.
	 * @param mixed $normalized `position_valid_through_date`.
	 * @return string
	 */
	private function valid_through( $text, $normalized ) {
		if ( '' === trim( (string) $text ) ) {
			return '';
		}
		$date = Normalizer::date( $normalized );
		return null !== $date && '1970-01-01' !== $date ? $date : (string) $text;
	}

	/**
	 * Splits a skills field into tags if it is a plain short list.
	 *
	 * @param mixed $value Stored value.
	 * @return string[]
	 */
	private function tags( $value ) {
		$html = is_scalar( $value ) ? (string) $value : '';
		// List items, paragraphs and line breaks separate entries; stripping them alone would glue words together.
		$html = preg_replace( '#<\s*(/?(li|p|div|ul|ol|h[1-6])\b[^>]*|br\s*/?)>#i', "\n", $html );
		$text = trim( wp_strip_all_tags( (string) $html ) );
		if ( '' === $text ) {
			return array();
		}
		// Anything else is prose; Normalizer drops overlong items anyway.
		return array_map( 'trim', preg_split( '/[,;\r\n]+/', $text ) );
	}

	/**
	 * ISO 4217 code from the plugin's currency symbol option.
	 *
	 * @return string
	 */
	private function currency() {
		$lang = is_callable( array( 'Job_Postings', 'getLang' ) ) ? (string) call_user_func( array( 'Job_Postings', 'getLang' ) ) : '';
		$sym  = trim( (string) get_option( 'jobs_currency_symbol_' . $lang, '€' ) );
		$map  = array(
			'€'    => 'EUR',
			'$'    => 'USD',
			'US$'  => 'USD',
			'£'    => 'GBP',
			'Fr.'  => 'CHF',
			'Fr'   => 'CHF',
			'SFr.' => 'CHF',
			'zł'   => 'PLN',
			'¥'    => 'JPY',
		);
		if ( isset( $map[ $sym ] ) ) {
			return $map[ $sym ];
		}
		return preg_match( '/^[A-Za-z]{3}$/', $sym ) ? strtoupper( $sym ) : '';
	}
}
