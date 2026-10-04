<?php
/**
 * Country tests.
 *
 * @package OJobPub
 */

namespace OJobPub\Tests\Unit;

use OJobPub\Feed\Country;
use PHPUnit\Framework\TestCase;

final class CountryTest extends TestCase {

	/**
	 * @dataProvider names
	 */
	public function test_code( $input, $expected ) {
		$this->assertSame( $expected, Country::code( $input ) );
	}

	public function names() {
		return array(
			array( 'CH', 'CH' ),
			array( 'ch', 'CH' ),
			array( 'Switzerland', 'CH' ),
			array( ' schweiz ', 'CH' ),
			array( 'Suisse', 'CH' ),
			array( 'Österreich', 'AT' ),
			array( 'Deutschland', 'DE' ),
			array( 'UK', 'GB' ),
			array( 'United  States', 'US' ),
			array( 'Atlantis', null ),
			array( '', null ),
			array( array( 'CH' ), null ),
		);
	}
}
