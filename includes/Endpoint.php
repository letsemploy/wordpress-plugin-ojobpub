<?php
/**
 * Serves /.well-known/ojobpub.json through WordPress.
 *
 * @package OJobPub
 */

namespace OJobPub;

defined( 'ABSPATH' ) || exit;

/**
 * Rewrite rule and HTTP delivery of the feed.
 */
final class Endpoint {

	const QUERY_VAR = 'ojobpub_feed';
	const PATH      = '.well-known/ojobpub.json';

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'add_rewrite_rule' ) );
		add_filter( 'query_vars', array( __CLASS__, 'query_vars' ) );
		add_filter( 'redirect_canonical', array( __CLASS__, 'no_canonical_redirect' ) );
		add_action( 'template_redirect', array( __CLASS__, 'serve' ), 0 );
	}

	/**
	 * Adds the rewrite rule. Also called on activation before flush_rewrite_rules().
	 */
	public static function add_rewrite_rule() {
		add_rewrite_rule( '^\.well-known/ojobpub\.json$', 'index.php?' . self::QUERY_VAR . '=1', 'top' );
	}

	/**
	 * Registers the query var.
	 *
	 * @param string[] $vars Vars.
	 * @return string[]
	 */
	public static function query_vars( $vars ) {
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	/**
	 * Prevents WordPress from appending a trailing slash to the JSON URL.
	 *
	 * @param string|false $redirect Redirect target.
	 * @return string|false
	 */
	public static function no_canonical_redirect( $redirect ) {
		return get_query_var( self::QUERY_VAR ) ? false : $redirect;
	}

	/**
	 * URL the feed is published at on this site's host.
	 *
	 * @return string
	 */
	public static function site_url() {
		return home_url( '/' . self::PATH );
	}

	/**
	 * URL consumers probe: the apex domain, which must answer (directly or via redirect).
	 *
	 * @return string
	 */
	public static function apex_url() {
		$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		if ( 0 === strpos( $host, 'www.' ) ) {
			$host = substr( $host, 4 );
		}
		/**
		 * Filters the public feed URL (used by the status screen and the self-test).
		 *
		 * @param string $url https://<apex>/.well-known/ojobpub.json
		 */
		return apply_filters( 'ojobpub_public_url', 'https://' . $host . '/' . self::PATH );
	}

	/**
	 * Whether WordPress lives in a sub-directory, in which case the rewrite rule
	 * cannot answer at the domain root.
	 *
	 * @return bool
	 */
	public static function home_has_path() {
		$path = (string) wp_parse_url( home_url(), PHP_URL_PATH );
		return '' !== trim( $path, '/' );
	}

	/**
	 * Outputs the feed.
	 */
	public static function serve() {
		if ( ! get_query_var( self::QUERY_VAR ) ) {
			return;
		}

		$feed = Feed_Service::get();

		nocache_headers();
		header_remove( 'Cache-Control' );
		header_remove( 'Expires' );
		header_remove( 'Pragma' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Access-Control-Allow-Origin: *' );

		if ( null === $feed ) {
			status_header( 404 );
			header( 'Content-Type: text/plain; charset=utf-8' );
			header( 'Cache-Control: no-store' );
			echo 'oJobPub feed not configured.';
			exit;
		}

		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Cache-Control: public, max-age=900' );
		header( 'ETag: ' . $feed['etag'] );
		header( 'Last-Modified: ' . gmdate( 'D, d M Y H:i:s', $feed['last_updated'] ) . ' GMT' );

		$if_none_match = isset( $_SERVER['HTTP_IF_NONE_MATCH'] ) ? trim( sanitize_text_field( wp_unslash( $_SERVER['HTTP_IF_NONE_MATCH'] ) ) ) : '';
		if ( '' !== $if_none_match && in_array( $feed['etag'], array_map( 'trim', explode( ',', $if_none_match ) ), true ) ) {
			status_header( 304 );
			exit;
		}

		status_header( 200 );
		if ( isset( $_SERVER['REQUEST_METHOD'] ) && 'HEAD' === $_SERVER['REQUEST_METHOD'] ) {
			exit;
		}
		echo $feed['json']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON document, not HTML.
		exit;
	}
}
