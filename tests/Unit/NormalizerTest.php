<?php
/**
 * Normalizer tests.
 *
 * @package OJobPub
 */

namespace OJobPub\Tests\Unit;

use OJobPub\Feed\Normalizer;
use PHPUnit\Framework\TestCase;

final class NormalizerTest extends TestCase {

	const TODAY = '2026-10-04';

	private function minimal( array $overrides = array() ) {
		return array_merge(
			array(
				'title'       => 'Carpenter',
				'language'    => 'en',
				'publishedAt' => '2026-09-01',
				'jobType'     => 'permanent',
				'locations'   => array( array( 'city' => 'Bern', 'country' => 'CH' ) ),
				'url'         => 'https://example.com/jobs/carpenter',
			),
			$overrides
		);
	}

	public function test_minimal_job_passes_unchanged() {
		$n   = new Normalizer();
		$job = $n->job( $this->minimal(), 1, self::TODAY );

		$this->assertSame( $this->minimal(), $job );
		$this->assertSame( array(), $n->issues() );
	}

	/**
	 * @dataProvider invalid_required_fields
	 */
	public function test_invalid_required_field_drops_job_and_reports( $field, $value ) {
		$n   = new Normalizer();
		$job = $n->job( $this->minimal( array( $field => $value ) ), 42, self::TODAY );

		$this->assertNull( $job );
		$this->assertArrayHasKey( '42', $n->issues() );
	}

	public function invalid_required_fields() {
		return array(
			'empty title'           => array( 'title', '  ' ),
			'locale instead of 639' => array( 'language', 'en-US' ),
			'no publishedAt'        => array( 'publishedAt', '' ),
			'invalid date'          => array( 'publishedAt', '2026-02-30' ),
			'unknown jobType'       => array( 'jobType', 'full-time' ),
			'empty locations'       => array( 'locations', array() ),
			'location without data' => array( 'locations', array( array( 'city' => '', 'country' => 'Schweiz' ) ) ),
			'relative url'          => array( 'url', '/jobs/carpenter' ),
			'javascript url'        => array( 'url', 'javascript:alert(1)' ),
		);
	}

	public function test_expired_job_is_dropped_silently() {
		$n = new Normalizer();

		$this->assertNull( $n->job( $this->minimal( array( 'applyBefore' => '2026-10-03' ) ), 1, self::TODAY ) );
		$this->assertSame( array(), $n->issues() );
		$this->assertSame( '2026-10-04', $n->job( $this->minimal( array( 'applyBefore' => '2026-10-04' ) ), 1, self::TODAY )['applyBefore'] );
	}

	public function test_description_is_plain_text_and_truncated() {
		$html = '<p>Hello&nbsp;<strong>world</strong></p><script>alert(1)</script>' . str_repeat( ' lorem ipsum', 200 );
		$job  = ( new Normalizer() )->job( $this->minimal( array( 'description' => $html ) ), 1, self::TODAY );

		$this->assertStringStartsWith( 'Hello world lorem', $job['description'] );
		$this->assertStringNotContainsString( '<', $job['description'] );
		$this->assertStringNotContainsString( 'alert', $job['description'] );
		$this->assertLessThanOrEqual( Normalizer::MAX_DESCRIPTION, mb_strlen( $job['description'], 'UTF-8' ) );
		$this->assertStringEndsWith( '…', $job['description'] );
	}

	public function test_optional_fields_are_normalized() {
		$job = ( new Normalizer() )->job(
			$this->minimal(
				array(
					'jobType'         => 'Contract',
					'language'        => 'DE',
					'workType'        => 'Hybrid',
					'experienceLevel' => 'boss',
					'startDate'       => '1 November 2026',
					'workLoad'        => array(
						'minPercentage' => '100',
						'maxPercentage' => 80,
					),
					'salary'          => array(
						'min'      => "95'000",
						'max'      => '80.000',
						'currency' => 'chf',
						'interval' => 'YEARLY',
					),
					'locations'       => array(
						array( 'city' => 'Bern', 'country' => 'ch' ),
						array( 'city' => 'Bern', 'country' => 'CH' ),
						array( 'country' => 'DE' ),
					),
				)
			),
			1,
			self::TODAY
		);

		$this->assertSame( 'contract', $job['jobType'] );
		$this->assertSame( 'de', $job['language'] );
		$this->assertSame( 'hybrid', $job['workType'] );
		$this->assertArrayNotHasKey( 'experienceLevel', $job );
		$this->assertSame( '2026-11-01', $job['startDate'] );
		$this->assertSame( array( 'minPercentage' => 80, 'maxPercentage' => 100 ), $job['workLoad'] );
		$this->assertSame( array( 'min' => 80000, 'max' => 95000, 'currency' => 'CHF', 'interval' => 'yearly' ), $job['salary'] );
		$this->assertSame( array( array( 'city' => 'Bern', 'country' => 'CH' ), array( 'country' => 'DE' ) ), $job['locations'] );
	}

	public function test_salary_without_amount_is_omitted() {
		$job = ( new Normalizer() )->job( $this->minimal( array( 'salary' => array( 'currency' => 'CHF' ) ) ), 1, self::TODAY );

		$this->assertArrayNotHasKey( 'salary', $job );
	}

	/**
	 * @dataProvider amounts
	 */
	public function test_amount( $input, $expected ) {
		$this->assertSame( $expected, Normalizer::amount( $input ) );
	}

	public function amounts() {
		return array(
			array( '90000', 90000 ),
			array( "90'000", 90000 ),
			array( '90.000', 90000 ),
			array( '90,000', 90000 ),
			array( '1.234,50', 1234.5 ),
			array( '1,234.50', 1234.5 ),
			array( '12,5', 12.5 ),
			array( '85k', 85000 ),
			array( 42, 42 ),
			array( -1, null ),
			array( 'negotiable', null ),
		);
	}

	public function test_salary_range() {
		$this->assertSame( array( 'min' => 80000, 'max' => 95000 ), Normalizer::salary_range( "CHF 80'000 – 95'000" ) );
		$this->assertSame( array( 'min' => 60000, 'max' => 60000 ), Normalizer::salary_range( '60k' ) );
	}

	public function test_tags_are_unique_short_and_limited() {
		$raw  = array_merge( array( 'PHP', 'php', '<b>WordPress</b>', str_repeat( 'x', 29 ), '' ), array_map( 'strval', range( 1, 30 ) ) );
		$tags = Normalizer::tags( $raw );

		$this->assertSame( array( 'PHP', 'WordPress' ), array_slice( $tags, 0, 2 ) );
		$this->assertCount( Normalizer::MAX_TAGS, $tags );
	}

	public function test_employer_requires_name_and_keeps_location_an_object() {
		$n = new Normalizer();

		$this->assertNull( $n->employer( array( 'name' => '' ) ) );
		$this->assertArrayHasKey( 'employer', $n->issues() );

		$employer = $n->employer( array( 'name' => 'Example Ltd', 'url' => 'not a url' ) );
		$this->assertInstanceOf( \stdClass::class, $employer['location'] );
		$this->assertArrayNotHasKey( 'url', $employer );
	}
}
