<?php
/**
 * Checks the feed the way a consumer sees it.
 *
 * @package OJobPub
 */

namespace OJobPub;

defined( 'ABSPATH' ) || exit;

/**
 * Fetches the apex URL, follows up to 5 redirects like SourceTracker, and compares
 * the result with the feed WordPress currently generates.
 */
final class Self_Test {

	const MAX_REDIRECTS = 5;

	/**
	 * Runs the test.
	 *
	 * @return array List of array( 'level' => ok|warning|error, 'message' => string ).
	 */
	public static function run() {
		$results = array();
		$add     = static function ( $level, $message ) use ( &$results ) {
			$results[] = array(
				'level'   => $level,
				'message' => $message,
			);
		};

		$local = Feed_Service::build();
		if ( null === $local ) {
			$add( 'error', __( 'The employer name is empty, so no feed can be generated.', 'ojobpub' ) );
			return $results;
		}
		/* translators: %d: number of jobs */
		$add( 'ok', sprintf( _n( 'WordPress generates a valid feed with %d job.', 'WordPress generates a valid feed with %d jobs.', $local['count'], 'ojobpub' ), $local['count'] ) );

		foreach ( $local['issues'] as $reference => $problems ) {
			$add(
				'warning',
				/* translators: 1: job reference (post ID), 2: list of problems */
				sprintf( __( 'Job %1$s is not published in the feed: %2$s', 'ojobpub' ), $reference, implode( '; ', $problems ) )
			);
		}

		if ( Endpoint::home_has_path() && ! Settings::get( 'static_file' ) ) {
			$add( 'warning', __( 'WordPress is installed in a sub-directory. The feed must be answered at the domain root; configure a redirect in the web server or enable the static file option.', 'ojobpub' ) );
		}

		$url      = Endpoint::apex_url();
		$response = wp_remote_get(
			$url,
			array(
				'timeout'             => 10,
				'redirection'         => self::MAX_REDIRECTS,
				'limit_response_size' => 5 * MB_IN_BYTES,
				'headers'             => array( 'Accept' => 'application/json' ),
				'user-agent'          => 'oJobPub-WordPress-SelfTest/' . OJOBPUB_VERSION,
			)
		);

		if ( is_wp_error( $response ) ) {
			$add(
				'warning',
				/* translators: 1: URL, 2: error message */
				sprintf( __( 'The server could not fetch %1$s itself (%2$s). Many hosts block such loopback requests; please check the URL from outside, e.g. with curl.', 'ojobpub' ), $url, $response->get_error_message() )
			);
			return $results;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			/* translators: 1: URL, 2: HTTP status code */
			$add( 'error', sprintf( __( '%1$s answered with HTTP %2$d instead of 200.', 'ojobpub' ), $url, $code ) );
			return $results;
		}

		$final = $url;
		if ( isset( $response['http_response'] ) && method_exists( $response['http_response'], 'get_response_object' ) ) {
			$final = (string) $response['http_response']->get_response_object()->url;
		}
		if ( $final !== $url ) {
			/* translators: 1: URL, 2: final URL */
			$add( 'ok', sprintf( __( '%1$s redirects to %2$s.', 'ojobpub' ), $url, $final ) );
		}

		$type = (string) wp_remote_retrieve_header( $response, 'content-type' );
		if ( false === stripos( $type, 'json' ) ) {
			/* translators: %s: content type */
			$add( 'warning', sprintf( __( 'Content-Type is "%s"; application/json is recommended.', 'ojobpub' ), $type ) );
		}

		$doc = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $doc ) || ! isset( $doc['version'], $doc['lastUpdated'], $doc['jobs'] ) ) {
			$add( 'error', __( 'The response is not an oJobPub document. Is a redirect pointing to a page instead of the feed?', 'ojobpub' ) );
			return $results;
		}

		$expected = json_decode( $local['json'], true );
		if ( $doc === $expected ) {
			/* translators: %s: URL */
			$add( 'ok', sprintf( __( '%s delivers the current feed.', 'ojobpub' ), $url ) );
		} elseif ( $doc['lastUpdated'] !== $expected['lastUpdated'] ) {
			$add( 'warning', __( 'The public feed differs from the current one (older lastUpdated). A page cache, CDN or an outdated static file may be serving a stale copy.', 'ojobpub' ) );
		} else {
			$add( 'warning', __( 'The public feed differs from the one WordPress generates. Another file or rule may be answering this URL.', 'ojobpub' ) );
		}

		return $results;
	}
}
