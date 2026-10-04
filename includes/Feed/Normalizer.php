<?php
/**
 * Normalizes raw job and employer data into schema-conformant oJobPub structures.
 *
 * This class has no WordPress dependency so it can be unit-tested in isolation.
 *
 * @package OJobPub
 */

namespace OJobPub\Feed;

defined( 'OJOBPUB_TESTING' ) || defined( 'ABSPATH' ) || exit;

/**
 * Pure normalization rules derived from the oJobPub 1.0 JSON Schema.
 */
final class Normalizer {

	const JOB_TYPES = array( 'permanent', 'contract', 'internship', 'apprenticeship', 'temporary', 'volunteer', 'freelance' );

	const EXPERIENCE_LEVELS = array( 'junior', 'mid', 'senior', 'lead', 'manager', 'director', 'executive' );

	const WORK_TYPES = array( 'remote', 'on-site', 'hybrid' );

	const SALARY_INTERVALS = array( 'hourly', 'daily', 'weekly', 'monthly', 'yearly' );

	const MAX_TITLE       = 255;
	const MAX_DESCRIPTION = 1000;
	const MAX_SHORT_TEXT  = 255;
	const MAX_TAGS        = 16;
	const MAX_TAG_LENGTH  = 28;

	/**
	 * Reasons why jobs were dropped during the last normalization run, keyed by reference.
	 *
	 * @var array<string, string[]>
	 */
	private $issues = array();

	/**
	 * Returns issues collected since the last reset.
	 *
	 * @return array<string, string[]>
	 */
	public function issues() {
		return $this->issues;
	}

	/**
	 * Clears collected issues.
	 */
	public function reset_issues() {
		$this->issues = array();
	}

	/**
	 * Normalizes the employer object. Returns null if required fields are missing.
	 *
	 * @param array $raw Keys: name, city, country, industry, url.
	 * @return array|null
	 */
	public function employer( array $raw ) {
		$name = self::text( isset( $raw['name'] ) ? $raw['name'] : '', self::MAX_SHORT_TEXT );
		if ( '' === $name ) {
			$this->issue( 'employer', 'name is missing' );
			return null;
		}

		$location = self::location(
			isset( $raw['city'] ) ? $raw['city'] : '',
			isset( $raw['country'] ) ? $raw['country'] : ''
		);

		$employer = array(
			'name'     => $name,
			// Required object; an empty PHP array would be encoded as a JSON list.
			'location' => empty( $location ) ? new \stdClass() : $location,
		);

		$industry = self::text( isset( $raw['industry'] ) ? $raw['industry'] : '', self::MAX_SHORT_TEXT );
		if ( '' !== $industry ) {
			$employer['industry'] = $industry;
		}

		$url = self::url( isset( $raw['url'] ) ? $raw['url'] : '' );
		if ( null !== $url ) {
			$employer['url'] = $url;
		}

		return $employer;
	}

	/**
	 * Normalizes one job. Returns null (and records an issue) if a required field is missing
	 * or invalid, so a single broken job never invalidates the whole feed.
	 *
	 * Raw keys mirror the oJobPub field names. `locations` is a list of
	 * array( 'city' => ..., 'country' => ... ); `tags` is a list of strings;
	 * `salary` and `workLoad` are arrays with the schema's keys.
	 *
	 * @param array  $raw          Raw job data.
	 * @param string $reference    Identifier used in issue messages (e.g. post ID).
	 * @param string $today        Current date as Y-m-d, used to drop expired jobs.
	 * @return array|null
	 */
	public function job( array $raw, $reference, $today ) {
		$apply_before = self::date( self::get( $raw, 'applyBefore' ) );
		if ( null !== $apply_before && $apply_before < $today ) {
			// Expired: excluded without reporting, that is the expected behaviour.
			return null;
		}

		$errors = array();
		$job    = array();

		$title = self::text( self::get( $raw, 'title' ), self::MAX_TITLE );
		if ( '' === $title ) {
			$errors[] = 'title is missing';
		}
		$job['title'] = $title;

		$language = strtolower( trim( (string) self::get( $raw, 'language' ) ) );
		if ( ! preg_match( '/^[a-z]{2}$/', $language ) ) {
			$errors[] = 'language must be an ISO 639-1 code';
		}
		$job['language'] = $language;

		$published = self::date( self::get( $raw, 'publishedAt' ) );
		if ( null === $published ) {
			$errors[] = 'publishedAt is missing or invalid';
		}
		$job['publishedAt'] = $published;

		$job_type = self::enum( self::get( $raw, 'jobType' ), self::JOB_TYPES );
		if ( null === $job_type ) {
			$errors[] = 'jobType is missing or not one of ' . implode( ', ', self::JOB_TYPES );
		}
		$job['jobType'] = $job_type;

		$locations = array();
		foreach ( (array) self::get( $raw, 'locations', array() ) as $loc ) {
			$loc = (array) $loc;
			$l   = self::location( self::get( $loc, 'city' ), self::get( $loc, 'country' ) );
			if ( ! empty( $l ) && ! in_array( $l, $locations, true ) ) {
				$locations[] = $l;
			}
		}
		if ( empty( $locations ) ) {
			$errors[] = 'at least one location with city or country is required';
		}
		$job['locations'] = $locations;

		$url = self::url( self::get( $raw, 'url' ) );
		if ( null === $url ) {
			$errors[] = 'url is missing or not an absolute http(s) URL';
		}
		$job['url'] = $url;

		if ( ! empty( $errors ) ) {
			$this->issues[ (string) $reference ] = $errors;
			return null;
		}

		$description = self::text( self::get( $raw, 'description' ), self::MAX_DESCRIPTION );
		if ( '' !== $description ) {
			$job['description'] = $description;
		}

		foreach ( array( 'category', 'referenceId' ) as $key ) {
			$value = self::text( self::get( $raw, $key ), self::MAX_SHORT_TEXT );
			if ( '' !== $value ) {
				$job[ $key ] = $value;
			}
		}

		if ( null !== $apply_before ) {
			$job['applyBefore'] = $apply_before;
		}
		foreach ( array( 'startDate', 'endDate' ) as $key ) {
			$value = self::date( self::get( $raw, $key ) );
			if ( null !== $value ) {
				$job[ $key ] = $value;
			}
		}

		$work_type = self::enum( self::get( $raw, 'workType' ), self::WORK_TYPES );
		if ( null !== $work_type ) {
			$job['workType'] = $work_type;
		}

		$level = self::enum( self::get( $raw, 'experienceLevel' ), self::EXPERIENCE_LEVELS );
		if ( null !== $level ) {
			$job['experienceLevel'] = $level;
		}

		$workload = self::work_load( (array) self::get( $raw, 'workLoad', array() ) );
		if ( null !== $workload ) {
			$job['workLoad'] = $workload;
		}

		$salary = self::salary( (array) self::get( $raw, 'salary', array() ) );
		if ( null !== $salary ) {
			$job['salary'] = $salary;
		}

		$tags = self::tags( (array) self::get( $raw, 'tags', array() ) );
		if ( ! empty( $tags ) ) {
			$job['tags'] = $tags;
		}

		return $job;
	}

	/**
	 * Plain text: strips tags, decodes entities, collapses whitespace, truncates on a
	 * word boundary where possible.
	 *
	 * @param mixed $value Input.
	 * @param int   $max   Maximum length in characters.
	 * @return string
	 */
	public static function text( $value, $max ) {
		if ( ! is_scalar( $value ) ) {
			return '';
		}
		$value = (string) $value;
		// Remove script/style contents entirely, then all remaining tags.
		$value = preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', $value );
		$value = strip_tags( $value ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- must run without WordPress.
		$value = html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$value = preg_replace( '/\s+/u', ' ', $value );
		$value = trim( (string) $value );

		if ( mb_strlen( $value, 'UTF-8' ) <= $max ) {
			return $value;
		}

		$cut   = mb_substr( $value, 0, $max - 1, 'UTF-8' );
		$space = mb_strrpos( $cut, ' ', 0, 'UTF-8' );
		if ( false !== $space && $space > $max * 0.8 ) {
			$cut = mb_substr( $cut, 0, $space, 'UTF-8' );
		}
		return rtrim( $cut, " \t\n\r\0\x0B.,;:-" ) . '…';
	}

	/**
	 * Accepts Y-m-d or anything strtotime understands; returns Y-m-d or null.
	 *
	 * @param mixed $value Input.
	 * @return string|null
	 */
	public static function date( $value ) {
		if ( ! is_scalar( $value ) || '' === trim( (string) $value ) ) {
			return null;
		}
		$value = trim( (string) $value );
		if ( preg_match( '/^(\d{4})-(\d{2})-(\d{2})/', $value, $m ) ) {
			return checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ? "{$m[1]}-{$m[2]}-{$m[3]}" : null;
		}
		$ts = strtotime( $value );
		return false === $ts ? null : gmdate( 'Y-m-d', $ts );
	}

	/**
	 * Absolute http(s) URL or null.
	 *
	 * @param mixed $value Input.
	 * @return string|null
	 */
	public static function url( $value ) {
		if ( ! is_scalar( $value ) ) {
			return null;
		}
		$value = trim( (string) $value );
		if ( '' === $value || false === filter_var( $value, FILTER_VALIDATE_URL ) ) {
			return null;
		}
		$scheme = strtolower( (string) parse_url( $value, PHP_URL_SCHEME ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- must run without WordPress.
		return in_array( $scheme, array( 'http', 'https' ), true ) ? $value : null;
	}

	/**
	 * Location object; empty array if neither part is usable.
	 *
	 * @param mixed $city    City.
	 * @param mixed $country ISO 3166-1 alpha-2 code.
	 * @return array
	 */
	public static function location( $city, $country ) {
		$loc  = array();
		$city = self::text( $city, self::MAX_SHORT_TEXT );
		if ( '' !== $city ) {
			$loc['city'] = $city;
		}
		$country = strtoupper( trim( (string) ( is_scalar( $country ) ? $country : '' ) ) );
		if ( preg_match( '/^[A-Z]{2}$/', $country ) ) {
			$loc['country'] = $country;
		}
		return $loc;
	}

	/**
	 * Case-insensitive enum match.
	 *
	 * @param mixed    $value   Input.
	 * @param string[] $allowed Allowed values.
	 * @return string|null
	 */
	public static function enum( $value, array $allowed ) {
		if ( ! is_scalar( $value ) ) {
			return null;
		}
		$value = strtolower( trim( (string) $value ) );
		return in_array( $value, $allowed, true ) ? $value : null;
	}

	/**
	 * Parses a human-entered amount: "90000", "90'000", "90.000", "90,000", "1.234,50", "85k".
	 *
	 * @param mixed $value Input.
	 * @return float|int|null
	 */
	public static function amount( $value ) {
		if ( is_int( $value ) || is_float( $value ) ) {
			return $value >= 0 ? $value : null;
		}
		if ( ! is_string( $value ) ) {
			return null;
		}
		$v = strtolower( trim( $value ) );
		$v = str_replace( array( ' ', "\u{00A0}", "\u{202F}", "'", '’' ), '', $v );

		$multiplier = 1;
		if ( preg_match( '/^([\d.,]+)k$/', $v, $m ) ) {
			$v          = $m[1];
			$multiplier = 1000;
		}
		if ( ! preg_match( '/^\d[\d.,]*$/', $v ) ) {
			return null;
		}

		$last_dot   = strrpos( $v, '.' );
		$last_comma = strrpos( $v, ',' );
		if ( false !== $last_dot && false !== $last_comma ) {
			// Both present: the rightmost one is the decimal separator.
			$decimal   = $last_dot > $last_comma ? '.' : ',';
			$thousands = '.' === $decimal ? ',' : '.';
			$v         = str_replace( $thousands, '', $v );
			$v         = str_replace( $decimal, '.', $v );
		} elseif ( false !== $last_dot || false !== $last_comma ) {
			$sep   = false !== $last_dot ? '.' : ',';
			$parts = explode( $sep, $v );
			$tail  = end( $parts );
			// "90.000" / "90,000" / "1,234,567" are thousands groups; "12.5" / "12,50" are decimals.
			if ( count( $parts ) > 2 || 3 === strlen( $tail ) ) {
				$v = str_replace( $sep, '', $v );
			} else {
				$v = str_replace( $sep, '.', $v );
			}
		}

		if ( ! is_numeric( $v ) ) {
			return null;
		}
		$n = (float) $v * $multiplier;
		return floor( $n ) === $n ? (int) $n : round( $n, 2 );
	}

	/**
	 * Parses a free-text salary like "80'000 - 95'000" into min/max.
	 *
	 * @param string $text Input.
	 * @return array Keys min and/or max.
	 */
	public static function salary_range( $text ) {
		$text  = str_replace( array( '–', '—', ' to ', ' bis ' ), '-', strtolower( (string) $text ) );
		$text  = preg_replace( '/[^\d.,\'’k\s-]/u', '', $text );
		$parts = array_values( array_filter( array_map( 'trim', explode( '-', (string) $text ) ), 'strlen' ) );
		$out   = array();
		if ( 1 === count( $parts ) ) {
			$n = self::amount( $parts[0] );
			if ( null !== $n ) {
				$out['min'] = $n;
				$out['max'] = $n;
			}
		} elseif ( 2 === count( $parts ) ) {
			$a = self::amount( $parts[0] );
			$b = self::amount( $parts[1] );
			if ( null !== $a ) {
				$out['min'] = $a;
			}
			if ( null !== $b ) {
				$out['max'] = $b;
			}
		}
		return $out;
	}

	/**
	 * Salary object or null.
	 *
	 * @param array $raw Keys min, max, currency, interval.
	 * @return array|null
	 */
	public static function salary( array $raw ) {
		$out = array();
		foreach ( array( 'min', 'max' ) as $key ) {
			$n = self::amount( self::get( $raw, $key ) );
			if ( null !== $n ) {
				$out[ $key ] = $n;
			}
		}
		if ( empty( $out ) ) {
			// Currency or interval alone carry no information.
			return null;
		}
		if ( isset( $out['min'], $out['max'] ) && $out['min'] > $out['max'] ) {
			list( $out['min'], $out['max'] ) = array( $out['max'], $out['min'] );
		}
		$currency = strtoupper( trim( (string) self::get( $raw, 'currency' ) ) );
		if ( preg_match( '/^[A-Z]{3}$/', $currency ) ) {
			$out['currency'] = $currency;
		}
		$interval = self::enum( self::get( $raw, 'interval' ), self::SALARY_INTERVALS );
		if ( null !== $interval ) {
			$out['interval'] = $interval;
		}
		return $out;
	}

	/**
	 * WorkLoad object or null.
	 *
	 * @param array $raw Keys minPercentage, maxPercentage.
	 * @return array|null
	 */
	public static function work_load( array $raw ) {
		$out = array();
		foreach ( array( 'minPercentage', 'maxPercentage' ) as $key ) {
			$n = self::amount( self::get( $raw, $key ) );
			if ( null !== $n && $n <= 100 ) {
				$out[ $key ] = $n;
			}
		}
		if ( isset( $out['minPercentage'], $out['maxPercentage'] ) && $out['minPercentage'] > $out['maxPercentage'] ) {
			list( $out['minPercentage'], $out['maxPercentage'] ) = array( $out['maxPercentage'], $out['minPercentage'] );
		}
		return empty( $out ) ? null : $out;
	}

	/**
	 * Unique tags, each at most 28 characters, at most 16 of them.
	 *
	 * @param array $raw List of strings.
	 * @return string[]
	 */
	public static function tags( array $raw ) {
		$out  = array();
		$seen = array();
		foreach ( $raw as $tag ) {
			$tag = self::text( $tag, PHP_INT_MAX );
			if ( '' === $tag || mb_strlen( $tag, 'UTF-8' ) > self::MAX_TAG_LENGTH ) {
				// Truncated tags are meaningless, drop them instead.
				continue;
			}
			$key = mb_strtolower( $tag, 'UTF-8' );
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$out[]        = $tag;
			if ( count( $out ) >= self::MAX_TAGS ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * Array access with default.
	 *
	 * @param array  $arr     Array.
	 * @param string $key     Key.
	 * @param mixed  $fallback Default.
	 * @return mixed
	 */
	private static function get( array $arr, $key, $fallback = null ) {
		return array_key_exists( $key, $arr ) ? $arr[ $key ] : $fallback;
	}

	/**
	 * Records an issue.
	 *
	 * @param string $reference Reference.
	 * @param string $message   Message.
	 */
	private function issue( $reference, $message ) {
		$this->issues[ $reference ][] = $message;
	}
}
