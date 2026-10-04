<?php
/**
 * Translatable labels for oJobPub enum values.
 *
 * @package OJobPub
 */

namespace OJobPub;

defined( 'ABSPATH' ) || exit;

/**
 * Enum value => label maps for the admin UI.
 */
final class Labels {

	/**
	 * Job types.
	 *
	 * @return array
	 */
	public static function job_types() {
		return array(
			'permanent'      => __( 'Permanent', 'ojobpub' ),
			'contract'       => __( 'Contract', 'ojobpub' ),
			'temporary'      => __( 'Temporary', 'ojobpub' ),
			'freelance'      => __( 'Freelance', 'ojobpub' ),
			'internship'     => __( 'Internship', 'ojobpub' ),
			'apprenticeship' => __( 'Apprenticeship', 'ojobpub' ),
			'volunteer'      => __( 'Volunteer', 'ojobpub' ),
		);
	}

	/**
	 * Work types.
	 *
	 * @return array
	 */
	public static function work_types() {
		return array(
			'on-site' => __( 'On-site', 'ojobpub' ),
			'hybrid'  => __( 'Hybrid', 'ojobpub' ),
			'remote'  => __( 'Remote', 'ojobpub' ),
		);
	}

	/**
	 * Experience levels.
	 *
	 * @return array
	 */
	public static function experience_levels() {
		return array(
			'junior'    => __( 'Junior', 'ojobpub' ),
			'mid'       => __( 'Mid-level', 'ojobpub' ),
			'senior'    => __( 'Senior', 'ojobpub' ),
			'lead'      => __( 'Lead', 'ojobpub' ),
			'manager'   => __( 'Manager', 'ojobpub' ),
			'director'  => __( 'Director', 'ojobpub' ),
			'executive' => __( 'Executive', 'ojobpub' ),
		);
	}

	/**
	 * Salary intervals.
	 *
	 * @return array
	 */
	public static function salary_intervals() {
		return array(
			'hourly'  => __( 'per hour', 'ojobpub' ),
			'daily'   => __( 'per day', 'ojobpub' ),
			'weekly'  => __( 'per week', 'ojobpub' ),
			'monthly' => __( 'per month', 'ojobpub' ),
			'yearly'  => __( 'per year', 'ojobpub' ),
		);
	}
}
