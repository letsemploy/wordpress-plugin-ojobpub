<?php
/**
 * Plugin settings storage.
 *
 * @package OJobPub
 */

namespace OJobPub;

use OJobPub\Feed\Normalizer;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and sanitizes the `ojobpub_settings` option.
 */
final class Settings {

	const OPTION = 'ojobpub_settings';

	/**
	 * Default values, derived from the site where possible.
	 *
	 * @return array
	 */
	public static function defaults() {
		$locale = get_locale();
		$region = '';
		// en_US is WordPress' default and says nothing about where the employer is.
		if ( 'en_US' !== $locale && preg_match( '/^[a-z]{2,3}_([A-Z]{2})/', $locale, $m ) ) {
			$region = $m[1];
		}
		return array(
			'employer_name'     => get_bloginfo( 'name' ),
			'employer_city'     => '',
			'employer_country'  => $region,
			'employer_industry' => '',
			'employer_url'      => home_url( '/' ),
			'source'            => 'native',
			'default_language'  => strtolower( substr( $locale, 0, 2 ) ),
			'default_country'   => $region,
			'default_job_type'  => 'permanent',
			'json_ld'           => 1,
			'static_file'       => 0,
		);
	}

	/**
	 * All settings merged over defaults. Empty strings fall back to the default.
	 *
	 * @return array
	 */
	public static function all() {
		$stored   = get_option( self::OPTION, array() );
		$defaults = self::defaults();
		$out      = $defaults;
		foreach ( is_array( $stored ) ? $stored : array() as $key => $value ) {
			if ( array_key_exists( $key, $defaults ) && '' !== $value && null !== $value ) {
				$out[ $key ] = $value;
			}
		}
		return $out;
	}

	/**
	 * Single setting.
	 *
	 * @param string $key Key.
	 * @return mixed
	 */
	public static function get( $key ) {
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	/**
	 * Raw employer data for the feed.
	 *
	 * @return array
	 */
	public static function employer() {
		$s = self::all();
		return array(
			'name'     => $s['employer_name'],
			'city'     => $s['employer_city'],
			'country'  => $s['employer_country'],
			'industry' => $s['employer_industry'],
			'url'      => $s['employer_url'],
		);
	}

	/**
	 * Defaults applied to jobs that do not specify a value.
	 *
	 * @return array Keys language, country, jobType.
	 */
	public static function defaults_for_jobs() {
		$s = self::all();
		return array(
			'language' => $s['default_language'],
			'country'  => $s['default_country'],
			'jobType'  => $s['default_job_type'],
		);
	}

	/**
	 * Sanitize callback for register_setting().
	 *
	 * @param mixed $input Submitted values.
	 * @return array
	 */
	public static function sanitize( $input ) {
		$input = is_array( $input ) ? $input : array();
		$text  = static function ( $key, $max = 255 ) use ( $input ) {
			return isset( $input[ $key ] ) ? mb_substr( sanitize_text_field( (string) $input[ $key ] ), 0, $max ) : '';
		};

		$out = array(
			'employer_name'     => $text( 'employer_name' ),
			'employer_city'     => $text( 'employer_city' ),
			'employer_country'  => preg_match( '/^[A-Za-z]{2}$/', $text( 'employer_country' ) ) ? strtoupper( $text( 'employer_country' ) ) : '',
			'employer_industry' => $text( 'employer_industry' ),
			'employer_url'      => isset( $input['employer_url'] ) ? esc_url_raw( (string) $input['employer_url'], array( 'http', 'https' ) ) : '',
			'source'            => sanitize_key( $text( 'source' ) ),
			'default_language'  => preg_match( '/^[A-Za-z]{2}$/', $text( 'default_language' ) ) ? strtolower( $text( 'default_language' ) ) : '',
			'default_country'   => preg_match( '/^[A-Za-z]{2}$/', $text( 'default_country' ) ) ? strtoupper( $text( 'default_country' ) ) : '',
			'default_job_type'  => (string) Normalizer::enum( $text( 'default_job_type' ), Normalizer::JOB_TYPES ),
			'json_ld'           => empty( $input['json_ld'] ) ? 0 : 1,
			'static_file'       => empty( $input['static_file'] ) ? 0 : 1,
		);

		if ( ! isset( Plugin::sources()[ $out['source'] ] ) ) {
			$out['source'] = 'native';
		}

		// Booleans must be stored even when 0, otherwise the default (1) would win again.
		return $out;
	}
}
