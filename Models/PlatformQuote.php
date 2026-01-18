<?php

namespace Zerohold\Shipping\Models;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PlatformQuote {
	public $platform;
	public $courier;
	public $price;
	public $etd;
	public $raw;
}
