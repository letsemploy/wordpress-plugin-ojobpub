<?php
/**
 * Creates sample jobs in all three sources for local testing.
 *
 * Usage: make seed (runs `wp eval-file` in the cli container). Idempotent: previous
 * sample posts (marked with the meta `_ojobpub_sample`) are deleted first.
 *
 * @package OJobPub
 */

// phpcs:disable WordPress.WP.AlternativeFunctions,WordPress.DB.SlowDBQuery

defined( 'WP_CLI' ) || exit;

$ojobpub_old = get_posts(
	array(
		'post_type'      => 'any',
		'post_status'    => 'any',
		'posts_per_page' => -1,
		'fields'         => 'ids',
		'meta_key'       => '_ojobpub_sample',
	)
);
foreach ( $ojobpub_old as $ojobpub_id ) {
	wp_delete_post( $ojobpub_id, true );
}

update_option(
	'ojobpub_settings',
	array_merge(
		OJobPub\Settings::all(),
		array(
			'employer_name'     => 'Example Ltd',
			'employer_city'     => 'Bern',
			'employer_country'  => 'CH',
			'employer_industry' => 'Construction',
			'default_language'  => 'de',
			'default_country'   => 'CH',
		)
	)
);

/**
 * Inserts a published sample post.
 *
 * @param string $type    Post type.
 * @param string $title   Title.
 * @param string $content Content.
 * @param array  $meta    Meta.
 * @return int
 */
function ojobpub_sample( $type, $title, $content, array $meta ) {
	$meta['_ojobpub_sample'] = 1;
	return (int) wp_insert_post(
		array(
			'post_type'    => $type,
			'post_status'  => 'publish',
			'post_title'   => $title,
			'post_content' => $content,
			'meta_input'   => $meta,
		),
		true
	);
}

$ojobpub_tomorrow  = gmdate( 'Y-m-d', time() + DAY_IN_SECONDS );
$ojobpub_yesterday = gmdate( 'Y-m-d', time() - DAY_IN_SECONDS );

// Built-in post type.
$ojobpub_id = ojobpub_sample(
	'ojobpub_job',
	'Zimmermann/Zimmerin EFZ',
	'<p>Wir suchen eine <strong>erfahrene</strong> Fachperson für Holzbau.</p>',
	array(
		'_ojobpub_job_type'        => 'permanent',
		'_ojobpub_locations'       => array( array( 'city' => 'Bern', 'country' => 'CH' ) ),
		'_ojobpub_work_type'       => 'on-site',
		'_ojobpub_workload_min'    => 80,
		'_ojobpub_workload_max'    => 100,
		'_ojobpub_salary_min'      => "75'000",
		'_ojobpub_salary_max'      => "90'000",
		'_ojobpub_salary_currency' => 'CHF',
		'_ojobpub_salary_interval' => 'yearly',
		'_ojobpub_apply_before'    => $ojobpub_tomorrow,
	)
);
wp_set_object_terms( $ojobpub_id, array( 'Holzbau', 'CNC' ), 'ojobpub_job_tag' );
ojobpub_sample( 'ojobpub_job', 'Abgelaufene Stelle', 'Should not appear.', array( '_ojobpub_apply_before' => $ojobpub_yesterday ) );

// WP Job Manager. Per-job currency and unit are only honoured when enabled in its settings.
if ( post_type_exists( 'job_listing' ) ) {
	update_option( 'job_manager_enable_salary', 1 );
	update_option( 'job_manager_enable_salary_currency', 1 );
	update_option( 'job_manager_enable_salary_unit', 1 );
	$ojobpub_id = ojobpub_sample(
		'job_listing',
		'Site Manager',
		'<p>Lead our construction sites in the Zurich area.</p>[some_shortcode]',
		array(
			'_job_location'        => 'Zürich, Schweiz',
			'_remote_position'     => 1,
			'_job_salary'          => "80'000 - 95'000",
			'_job_salary_currency' => 'CHF',
			'_job_salary_unit'     => 'YEAR',
			'_filled'              => 0,
		)
	);
	wp_set_object_terms( $ojobpub_id, 'Full Time', 'job_listing_type' );
	ojobpub_sample( 'job_listing', 'Filled position', 'Should not appear.', array( '_job_location' => 'Bern', '_filled' => 1 ) );
}

// Job Postings.
if ( post_type_exists( 'jobs' ) ) {
	ojobpub_sample(
		'jobs',
		'Projektleiter Holzbau',
		'',
		array(
			'position_title'                        => 'Projektleiter/in Holzbau',
			'position_description'                  => '<p>Sie leiten <b>Projekte</b> von der Offerte bis zur Abnahme.</p>',
			'position_employment_type'              => array( 'FULL_TIME' ),
			'position_job_location_addressLocality' => 'Thun',
			'position_job_location_addressCountry'  => 'Schweiz',
			'position_job_location_remote'          => 'on',
			'job_remote_data'                       => array(
				array(
					'type' => 'country',
					'name' => 'Germany',
				),
				array(
					'type' => 'state',
					'name' => 'Tirol, Österreich',
				),
			),
			'position_valid_through'                => gmdate( 'd.m.Y', time() + 30 * DAY_IN_SECONDS ),
			'position_valid_through_date'           => gmdate( 'Y-m-d', time() + 30 * DAY_IN_SECONDS ),
			'position_skills'                       => '<ul><li>Holzbau</li><li>Projektleitung</li><li>CAD</li></ul>',
			'position_base_salary'                  => '7000',
			'position_base_salary_upto'             => '8500',
			'position_base_salary_unittext'         => 'MONTH',
		)
	);
	// Saved with an empty deadline: Job Postings writes 1970-01-01 to the normalized copy.
	ojobpub_sample(
		'jobs',
		'Lernende Schreiner',
		'',
		array(
			'position_title'              => 'Lernende/r Schreiner/in',
			'position_employment_type'    => array( 'INTERN' ),
			'position_job_location'       => 'Basel, Switzerland',
			'position_valid_through'      => '',
			'position_valid_through_date' => '1970-01-01',
		)
	);
}

WP_CLI::success( 'Sample jobs created.' );
