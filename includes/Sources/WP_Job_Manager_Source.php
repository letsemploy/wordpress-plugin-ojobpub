<?php
/**
 * WP Job Manager (Automattic) adapter.
 *
 * @package OJobPub
 */

namespace OJobPub\Sources;

defined( 'ABSPATH' ) || exit;

/**
 * Reads published, unfilled listings from WP Job Manager's `job_listing` post type.
 *
 * Mapping notes:
 * - jobType comes from the schema.org employment type WPJM stores per job type term
 *   (term meta `employment_type`), falling back to the term slug, then to the site default.
 * - `_job_expires` is when the listing disappears, not an application deadline, so it is
 *   not mapped to applyBefore. Expired listings are not `publish`, so they drop out anyway.
 * - Location: geocoded city/country if available, otherwise the first comma-separated part
 *   of the free-text location, with a trailing country name ("Berlin, Germany") resolved
 *   to its ISO code. The site's default country is only assumed when the free text
 *   contains no comma (i.e. does not name a country itself).
 * - WPJM already prints schema.org JobPosting, so this plugin adds no JSON-LD for it.
 */
final class WP_Job_Manager_Source implements Source {

	const POST_TYPE = 'job_listing';

	/**
	 * {@inheritDoc}
	 */
	public function id() {
		return 'wp-job-manager';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label() {
		return __( 'WP Job Manager', 'ojobpub' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_available() {
		return class_exists( 'WP_Job_Manager' ) || post_type_exists( self::POST_TYPE );
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
				// Filled positions are not open any more, whatever WPJM's display setting says.
				'meta_query'       => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- bounded by posts_per_page.
					'relation' => 'OR',
					array(
						'key'     => '_filled',
						'compare' => 'NOT EXISTS',
					),
					array(
						'key'   => '_filled',
						'value' => '0',
					),
					array(
						'key'   => '_filled',
						'value' => '',
					),
				),
			)
		);
		if ( empty( $ids ) ) {
			return array();
		}
		update_meta_cache( 'post', $ids );
		update_object_term_cache( $ids, self::POST_TYPE );

		$jobs = array();
		foreach ( $ids as $id ) {
			$jobs[ $id ] = $this->raw_job( get_post( $id ), $defaults );
		}
		return $jobs;
	}

	/**
	 * Maps one listing.
	 *
	 * @param \WP_Post $post     Post.
	 * @param array    $defaults Site defaults.
	 * @return array
	 */
	private function raw_job( \WP_Post $post, array $defaults ) {
		$m = static function ( $key ) use ( $post ) {
			return get_post_meta( $post->ID, $key, true );
		};

		$remote = (bool) $m( '_remote_position' );

		$job = array(
			'title'       => get_the_title( $post ),
			'language'    => $defaults['language'],
			'publishedAt' => get_post_time( 'Y-m-d', true, $post ),
			'jobType'     => $this->job_type( $post, $defaults['jobType'] ),
			'locations'   => array( $this->location( $post, $defaults['country'] ) ),
			'url'         => get_permalink( $post ),
			'description' => strip_shortcodes( $post->post_content ),
			'referenceId' => (string) $post->ID,
			'workType'    => $remote ? 'remote' : '',
		);

		$categories = taxonomy_exists( 'job_listing_category' ) ? get_the_terms( $post, 'job_listing_category' ) : false;
		if ( is_array( $categories ) && ! empty( $categories ) ) {
			$job['category'] = $categories[0]->name;
		}

		$salary_text = (string) $m( '_job_salary' );
		if ( '' !== trim( $salary_text ) ) {
			$range    = \OJobPub\Feed\Normalizer::salary_range( $salary_text );
			$currency = function_exists( 'get_the_job_salary_currency' ) ? get_the_job_salary_currency( $post ) : $m( '_job_salary_currency' );
			$unit     = function_exists( 'get_the_job_salary_unit' ) ? get_the_job_salary_unit( $post ) : $m( '_job_salary_unit' );
			$map      = array(
				'HOUR'  => 'hourly',
				'DAY'   => 'daily',
				'WEEK'  => 'weekly',
				'MONTH' => 'monthly',
				'YEAR'  => 'yearly',
			);
			$unit     = strtoupper( (string) $unit );

			$job['salary'] = array_merge(
				$range,
				array(
					'currency' => (string) $currency,
					'interval' => isset( $map[ $unit ] ) ? $map[ $unit ] : '',
				)
			);
		}

		/**
		 * Filters the raw job mapped from a WP Job Manager listing.
		 *
		 * @param array    $job  Raw oJobPub job.
		 * @param \WP_Post $post Listing.
		 */
		return apply_filters( 'ojobpub_wp_job_manager_job', $job, $post );
	}

	/**
	 * Resolves the oJobPub job type.
	 *
	 * @param \WP_Post $post     Post.
	 * @param string   $fallback Site default.
	 * @return string
	 */
	private function job_type( \WP_Post $post, $fallback ) {
		if ( ! taxonomy_exists( 'job_listing_type' ) ) {
			return $fallback;
		}
		$terms = get_the_terms( $post, 'job_listing_type' );
		if ( ! is_array( $terms ) || empty( $terms ) ) {
			return $fallback;
		}

		$by_schema = array(
			'FULL_TIME'  => 'permanent',
			'PART_TIME'  => 'permanent',
			'CONTRACTOR' => 'contract',
			'TEMPORARY'  => 'temporary',
			'INTERN'     => 'internship',
			'VOLUNTEER'  => 'volunteer',
			'PER_DIEM'   => 'temporary',
		);
		$by_slug   = array(
			'full-time'      => 'permanent',
			'part-time'      => 'permanent',
			'freelance'      => 'freelance',
			'internship'     => 'internship',
			'temporary'      => 'temporary',
			'contract'       => 'contract',
			'apprenticeship' => 'apprenticeship',
			'volunteer'      => 'volunteer',
		);

		/**
		 * Filters the WP Job Manager job type slug → oJobPub jobType map.
		 *
		 * @param array $by_slug Map.
		 */
		$by_slug = apply_filters( 'ojobpub_wp_job_manager_job_type_map', $by_slug );

		foreach ( $terms as $term ) {
			// Slug first: "freelance" is more precise than its schema.org type CONTRACTOR.
			if ( isset( $by_slug[ $term->slug ] ) ) {
				return $by_slug[ $term->slug ];
			}
			$schema = strtoupper( (string) get_term_meta( $term->term_id, 'employment_type', true ) );
			if ( isset( $by_schema[ $schema ] ) ) {
				return $by_schema[ $schema ];
			}
		}
		return $fallback;
	}

	/**
	 * Resolves one location.
	 *
	 * @param \WP_Post $post            Post.
	 * @param string   $default_country Site default country.
	 * @return array
	 */
	private function location( \WP_Post $post, $default_country ) {
		$city    = (string) get_post_meta( $post->ID, 'geolocation_city', true );
		$country = (string) get_post_meta( $post->ID, 'geolocation_country_short', true );
		if ( '' !== $city || '' !== $country ) {
			return array(
				'city'    => $city,
				'country' => $country,
			);
		}

		$text = trim( (string) get_post_meta( $post->ID, '_job_location', true ) );
		if ( '' === $text ) {
			return array( 'country' => $default_country );
		}
		if ( false === strpos( $text, ',' ) ) {
			return array(
				'city'    => $text,
				'country' => $default_country,
			);
		}
		$parts   = array_map( 'trim', explode( ',', $text ) );
		$country = \OJobPub\Feed\Country::code( end( $parts ) );
		return array(
			'city'    => $parts[0],
			'country' => (string) $country,
		);
	}
}
