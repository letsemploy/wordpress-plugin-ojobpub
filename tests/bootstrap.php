<?php
/**
 * Unit test bootstrap. Loads only the classes that run without WordPress.
 *
 * @package OJobPub
 */

define( 'OJOBPUB_TESTING', true );

require dirname( __DIR__ ) . '/vendor/autoload.php';
require dirname( __DIR__ ) . '/includes/Feed/Country.php';
require dirname( __DIR__ ) . '/includes/Feed/Normalizer.php';
require dirname( __DIR__ ) . '/includes/Feed/Builder.php';
