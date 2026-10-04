<?php
/**
 * Schema.org JobPosting output for native job pages.
 *
 * @package OJobPub
 */

namespace OJobPub;

use OJobPub\Feed\Normalizer;
use OJobPub\Sources\Native_Source;

defined( 'ABSPATH' ) || exit;

/**
 * Prints JSON-LD on single job pages so the same data reaches Google for Jobs.
 * Only for the native post type: WP Job Manager already prints its own.
 */
final class Json_Ld {

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'wp_head', array( __CLASS__, 'print_script' ) );
	}

	/**
	 * Prints the script tag.
	 */
	public static function print_script() {
		if ( ! Settings::get( 'json_ld' ) || ! is_singular( PostType::POST_TYPE ) ) {
			return;
		}
		$data = self::data( get_queried_object() );
		if ( null === $data ) {
			return;
		}
		printf(
			"<script type=\"application/ld+json\">%s</script>\n",
			wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP )
		);
	}

	/**
	 * Builds the JobPosting object from the same normalized data as the feed.
	 *
	 * @param mixed $post Post.
	 * @return array|null
	 */
	public static function data( $post ) {
		if ( ! $post instanceof \WP_Post ) {
			return null;
		}
		$normalizer = new Normalizer();
		$employer   = $normalizer->employer( Settings::employer() );
		$job        = $normalizer->job( Native_Source::raw_job( $post, Settings::defaults_for_jobs() ), $post->ID, current_datetime()->format( 'Y-m-d' ) );
		if ( null === $employer || null === $job ) {
			return null;
		}

		$data = array(
			'@context'           => 'https://schema.org/',
			'@type'              => 'JobPosting',
			'title'              => $job['title'],
			// Google expects the full description; HTML is allowed.
			'description'        => wp_kses_post( apply_filters( 'the_content', $post->post_content ) ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core filter.
			'datePosted'         => $job['publishedAt'],
			'employmentType'     => self::employment_type( $job ),
			'hiringOrganization' => array_filter(
				array(
					'@type'  => 'Organization',
					'name'   => $employer['name'],
					'sameAs' => isset( $employer['url'] ) ? $employer['url'] : null,
				)
			),
			'identifier'         => array(
				'@type' => 'PropertyValue',
				'name'  => $employer['name'],
				'value' => isset( $job['referenceId'] ) ? $job['referenceId'] : (string) $post->ID,
			),
			'url'                => $job['url'],
		);

		if ( isset( $job['applyBefore'] ) ) {
			$data['validThrough'] = $job['applyBefore'] . 'T23:59:59';
		}

		$places = array();
		foreach ( $job['locations'] as $loc ) {
			$places[] = array(
				'@type'   => 'Place',
				'address' => array_filter(
					array(
						'@type'           => 'PostalAddress',
						'addressLocality' => isset( $loc['city'] ) ? $loc['city'] : null,
						'addressCountry'  => isset( $loc['country'] ) ? $loc['country'] : null,
					)
				),
			);
		}

		if ( isset( $job['workType'] ) && 'remote' === $job['workType'] ) {
			$data['jobLocationType'] = 'TELECOMMUTE';
			$countries               = array_values( array_unique( array_filter( wp_list_pluck( $job['locations'], 'country' ) ) ) );
			if ( ! empty( $countries ) ) {
				$data['applicantLocationRequirements'] = array_map(
					static function ( $c ) {
						return array(
							'@type' => 'Country',
							'name'  => $c,
						);
					},
					$countries
				);
			}
		} else {
			$data['jobLocation'] = 1 === count( $places ) ? $places[0] : $places;
		}

		if ( isset( $job['salary'] ) && isset( $job['salary']['currency'] ) ) {
			$units              = array(
				'hourly'  => 'HOUR',
				'daily'   => 'DAY',
				'weekly'  => 'WEEK',
				'monthly' => 'MONTH',
				'yearly'  => 'YEAR',
			);
			$data['baseSalary'] = array(
				'@type'    => 'MonetaryAmount',
				'currency' => $job['salary']['currency'],
				'value'    => array_filter(
					array(
						'@type'    => 'QuantitativeValue',
						'minValue' => isset( $job['salary']['min'] ) ? $job['salary']['min'] : null,
						'maxValue' => isset( $job['salary']['max'] ) ? $job['salary']['max'] : null,
						'unitText' => isset( $job['salary']['interval'] ) ? $units[ $job['salary']['interval'] ] : null,
					),
					static function ( $v ) {
						return null !== $v;
					}
				),
			);
		}

		/**
		 * Filters the schema.org JobPosting data.
		 *
		 * @param array    $data JobPosting.
		 * @param \WP_Post $post Job.
		 * @param array    $job  Normalized oJobPub job.
		 */
		return apply_filters( 'ojobpub_json_ld', $data, $post, $job );
	}

	/**
	 * Maps oJobPub jobType (+ workload) to schema.org employmentType.
	 *
	 * @param array $job Normalized job.
	 * @return string
	 */
	private static function employment_type( array $job ) {
		switch ( $job['jobType'] ) {
			case 'permanent':
				$max = isset( $job['workLoad']['maxPercentage'] ) ? $job['workLoad']['maxPercentage'] : 100;
				return $max < 100 ? 'PART_TIME' : 'FULL_TIME';
			case 'contract':
			case 'freelance':
				return 'CONTRACTOR';
			case 'temporary':
				return 'TEMPORARY';
			case 'internship':
				return 'INTERN';
			case 'volunteer':
				return 'VOLUNTEER';
			default:
				return 'OTHER';
		}
	}
}
