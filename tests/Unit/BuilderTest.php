<?php
/**
 * Builder tests, including validation of the generated document against the oJobPub schema.
 *
 * @package OJobPub
 */

namespace OJobPub\Tests\Unit;

use OJobPub\Feed\Builder;
use OJobPub\Feed\Normalizer;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\TestCase;

final class BuilderTest extends TestCase {

	const TODAY = '2026-10-04';

	private function employer() {
		return array(
			'name'     => 'Example Ltd',
			'city'     => 'Bern',
			'country'  => 'CH',
			'industry' => 'Construction',
			'url'      => 'https://example.com/',
		);
	}

	private function jobs() {
		return array(
			10 => array(
				'title'       => 'Carpenter',
				'language'    => 'de',
				'publishedAt' => '2026-09-01',
				'jobType'     => 'permanent',
				'locations'   => array( array( 'city' => 'Bern', 'country' => 'CH' ) ),
				'url'         => 'https://example.com/jobs/carpenter',
			),
			11 => array(
				'title'           => 'Site Manager',
				'language'        => 'en',
				'publishedAt'     => '2026-09-15',
				'jobType'         => 'contract',
				'locations'       => array( array( 'city' => 'Zürich', 'country' => 'CH' ), array( 'country' => 'DE' ) ),
				'url'             => 'https://example.com/jobs/site-manager',
				'description'     => '<p>Lead our <em>sites</em>.</p>',
				'category'        => 'Construction',
				'referenceId'     => '11',
				'applyBefore'     => '2026-12-31',
				'startDate'       => '2027-01-01',
				'workType'        => 'hybrid',
				'experienceLevel' => 'senior',
				'workLoad'        => array( 'minPercentage' => 80, 'maxPercentage' => 100 ),
				'salary'          => array( 'min' => "90'000", 'max' => "110'000", 'currency' => 'CHF', 'interval' => 'yearly' ),
				'tags'            => array( 'Leadership', 'BIM' ),
			),
			12 => array( 'title' => 'Broken job' ),
			13 => array(
				'title'       => 'Expired',
				'language'    => 'en',
				'publishedAt' => '2026-01-01',
				'jobType'     => 'permanent',
				'locations'   => array( array( 'country' => 'CH' ) ),
				'url'         => 'https://example.com/jobs/expired',
				'applyBefore' => '2026-02-01',
			),
		);
	}

	public function test_content_drops_invalid_jobs_and_sorts_newest_first() {
		$normalizer = new Normalizer();
		$content    = ( new Builder( $normalizer ) )->content( $this->employer(), $this->jobs(), self::TODAY );

		$this->assertSame( array( 'Site Manager', 'Carpenter' ), array_column( $content['jobs'], 'title' ) );
		$this->assertSame( array( '12' ), array_map( 'strval', array_keys( $normalizer->issues() ) ) );
	}

	public function test_content_is_null_without_employer_name() {
		$this->assertNull( ( new Builder( new Normalizer() ) )->content( array(), array(), self::TODAY ) );
	}

	public function test_empty_feed_encodes_jobs_as_list_and_version_as_string() {
		$content = ( new Builder( new Normalizer() ) )->content( array( 'name' => 'Example Ltd' ), array(), self::TODAY );
		$json    = Builder::encode( Builder::document( $content, 1790000000 ) );

		$this->assertStringContainsString( '"version":"1.0"', $json );
		$this->assertStringContainsString( '"jobs":[]', $json );
		$this->assertStringContainsString( '"location":{}', $json );
		$this->assertStringContainsString( '"lastUpdated":"2026-09-21T', $json );
	}

	public function test_hash_is_independent_of_input_order() {
		$builder  = new Builder( new Normalizer() );
		$forward  = $builder->content( $this->employer(), $this->jobs(), self::TODAY );
		$reversed = $builder->content( $this->employer(), array_reverse( $this->jobs(), true ), self::TODAY );

		$this->assertSame( Builder::hash( $forward ), Builder::hash( $reversed ) );
	}

	/**
	 * Validates against schema/v1/ojobpub.json. The Makefile mounts it and sets OJOBPUB_SCHEMA.
	 *
	 * @dataProvider documents
	 */
	public function test_document_is_valid_against_schema( array $employer, array $jobs ) {
		$schema = getenv( 'OJOBPUB_SCHEMA' );
		if ( ! $schema || ! is_readable( $schema ) ) {
			$this->markTestSkipped( 'OJOBPUB_SCHEMA not set; run `make test`.' );
		}

		$content  = ( new Builder( new Normalizer() ) )->content( $employer, $jobs, self::TODAY );
		$document = json_decode( Builder::encode( Builder::document( $content, time() ) ) );

		$validator = new Validator();
		$result    = $validator->validate( $document, file_get_contents( $schema ) );

		$this->assertTrue(
			$result->isValid(),
			$result->isValid() ? '' : print_r( ( new ErrorFormatter() )->format( $result->error() ), true )
		);
	}

	public function documents() {
		return array(
			'full'  => array( $this->employer(), $this->jobs() ),
			'empty' => array( array( 'name' => 'Example Ltd' ), array() ),
		);
	}
}
