<?php
/**
 * Resolves free-text country names to ISO 3166-1 alpha-2 codes.
 *
 * @package OJobPub
 */

namespace OJobPub\Feed;

defined( 'OJOBPUB_TESTING' ) || defined( 'ABSPATH' ) || exit;

/**
 * Job plugins often store "Switzerland" or "Schweiz" instead of "CH".
 *
 * Uses the intl extension's region names (all ISO codes in several languages) when
 * available, and a small built-in list otherwise. Unknown names resolve to null, so
 * the caller can leave the country out instead of guessing.
 */
final class Country {

	/**
	 * Languages whose region names are accepted.
	 */
	const LANGUAGES = array( 'en', 'de', 'fr', 'it', 'es', 'nl', 'pt', 'pl', 'sv', 'da' );

	/**
	 * Fallback when intl is missing: DACH region and neighbours, English and local names.
	 */
	const FALLBACK = array(
		'switzerland'    => 'CH',
		'schweiz'        => 'CH',
		'suisse'         => 'CH',
		'svizzera'       => 'CH',
		'germany'        => 'DE',
		'deutschland'    => 'DE',
		'austria'        => 'AT',
		'österreich'     => 'AT',
		'france'         => 'FR',
		'frankreich'     => 'FR',
		'italy'          => 'IT',
		'italien'        => 'IT',
		'italia'         => 'IT',
		'liechtenstein'  => 'LI',
		'netherlands'    => 'NL',
		'niederlande'    => 'NL',
		'belgium'        => 'BE',
		'belgien'        => 'BE',
		'spain'          => 'ES',
		'spanien'        => 'ES',
		'united kingdom' => 'GB',
		'uk'             => 'GB',
		'united states'  => 'US',
		'usa'            => 'US',
	);

	/**
	 * Lazily built name => code map.
	 *
	 * @var array<string, string>|null
	 */
	private static $map = null;

	/**
	 * Resolves a code or name.
	 *
	 * @param mixed $value Code or name, e.g. "CH", "Switzerland", "Schweiz".
	 * @return string|null
	 */
	public static function code( $value ) {
		if ( ! is_scalar( $value ) ) {
			return null;
		}
		$value = trim( (string) $value );
		$key   = self::key( $value );
		if ( preg_match( '/^[A-Za-z]{2}$/', $value ) ) {
			// Two letters are a code, except common abbreviations like "UK" (ISO: GB).
			return isset( self::FALLBACK[ $key ] ) ? self::FALLBACK[ $key ] : strtoupper( $value );
		}
		if ( '' === $key ) {
			return null;
		}
		$map = self::map();
		return isset( $map[ $key ] ) ? $map[ $key ] : null;
	}

	/**
	 * Normalized lookup key.
	 *
	 * @param string $name Name.
	 * @return string
	 */
	private static function key( $name ) {
		$name = mb_strtolower( trim( $name ), 'UTF-8' );
		return (string) preg_replace( '/\s+/u', ' ', $name );
	}

	/**
	 * Builds the map once.
	 *
	 * @return array<string, string>
	 */
	private static function map() {
		if ( null !== self::$map ) {
			return self::$map;
		}
		self::$map = self::FALLBACK;
		if ( ! class_exists( '\ResourceBundle' ) ) {
			return self::$map;
		}
		foreach ( self::LANGUAGES as $lang ) {
			$bundle = \ResourceBundle::create( $lang, 'ICUDATA-region' );
			if ( null === $bundle || null === $bundle['Countries'] ) {
				continue;
			}
			foreach ( $bundle['Countries'] as $code => $name ) {
				if ( preg_match( '/^[A-Z]{2}$/', (string) $code ) && 'ZZ' !== $code ) {
					$key = self::key( (string) $name );
					if ( ! isset( self::$map[ $key ] ) ) {
						self::$map[ $key ] = (string) $code;
					}
				}
			}
		}
		return self::$map;
	}
}
