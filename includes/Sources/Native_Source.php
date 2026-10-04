<?php
/**
 * Native job post type as data source.
 *
 * @package OJobPub
 */

namespace OJobPub\Sources;

use OJobPub\PostType;

defined( 'ABSPATH' ) || exit;

/**
 * Reads jobs from the plugin's own `ojobpub_job` post type.
 */
final class Native_Source implements Source {

	/**
	 * {@inheritDoc}
	 */
	public function id() {
		return 'native';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label() {
		return __( 'Jobs managed by this plugin', 'ojobpub' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_available() {
		return true;
	}

	/**
	 * {@inheritDoc}
	 */
	public function post_types() {
		return array( PostType::POST_TYPE );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array $defaults Site defaults.
	 */
	public function jobs( array $defaults ) {
		$ids = get_posts(
			array(
				'post_type'        => PostType::POST_TYPE,
				'post_status'      => 'publish',
				'posts_per_page'   => (int) apply_filters( 'ojobpub_max_jobs', 500 ),
				'orderby'          => 'date',
				'order'            => 'DESC',
				'fields'           => 'ids',
				'no_found_rows'    => true,
				'suppress_filters' => false,
			)
		);
		if ( empty( $ids ) ) {
			return array();
		}
		update_meta_cache( 'post', $ids );
		update_object_term_cache( $ids, PostType::POST_TYPE );

		$jobs = array();
		foreach ( $ids as $id ) {
			$jobs[ $id ] = self::raw_job( get_post( $id ), $defaults );
		}
		return $jobs;
	}

	/**
	 * Maps one post to a raw oJobPub job. Also used for JSON-LD output.
	 *
	 * @param \WP_Post $post     Post.
	 * @param array    $defaults Site defaults.
	 * @return array
	 */
	public static function raw_job( \WP_Post $post, array $defaults ) {
		$m = static function ( $key ) use ( $post ) {
			return get_post_meta( $post->ID, PostType::META_PREFIX . $key, true );
		};

		$locations = $m( 'locations' );
		if ( ! is_array( $locations ) || empty( $locations ) ) {
			$locations = array( array( 'country' => $defaults['country'] ) );
		}

		$categories = get_the_terms( $post, PostType::TAX_CATEGORY );
		$tags       = get_the_terms( $post, PostType::TAX_TAG );

		$description = has_excerpt( $post ) ? $post->post_excerpt : strip_shortcodes( $post->post_content );

		return array(
			'title'           => get_the_title( $post ),
			'language'        => $m( 'language' ) ? $m( 'language' ) : $defaults['language'],
			'publishedAt'     => get_post_time( 'Y-m-d', true, $post ),
			'jobType'         => $m( 'job_type' ) ? $m( 'job_type' ) : $defaults['jobType'],
			'locations'       => $locations,
			'url'             => get_permalink( $post ),
			'description'     => $description,
			'category'        => is_array( $categories ) && ! empty( $categories ) ? $categories[0]->name : '',
			'referenceId'     => $m( 'reference_id' ) ? $m( 'reference_id' ) : (string) $post->ID,
			'applyBefore'     => $m( 'apply_before' ),
			'startDate'       => $m( 'start_date' ),
			'endDate'         => $m( 'end_date' ),
			'workType'        => $m( 'work_type' ),
			'experienceLevel' => $m( 'experience_level' ),
			'workLoad'        => array(
				'minPercentage' => $m( 'workload_min' ),
				'maxPercentage' => $m( 'workload_max' ),
			),
			'salary'          => array(
				'min'      => $m( 'salary_min' ),
				'max'      => $m( 'salary_max' ),
				'currency' => $m( 'salary_currency' ),
				'interval' => $m( 'salary_interval' ),
			),
			'tags'            => is_array( $tags ) ? wp_list_pluck( $tags, 'name' ) : array(),
		);
	}
}
