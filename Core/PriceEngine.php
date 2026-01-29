<?php
/**
 * PriceEngine Helper (DEPRECATED)
 * 
 * 🛑 WARNING: All financial logic has been moved to OrderStateManager.php
 * as part of "The Great Wall" system hardening. Use that instead.
 */

namespace Zerohold\Shipping\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * @deprecated Use OrderStateManager::calculate_share_and_cap
 */
class PriceEngine {
    public static function calculate_share_and_cap( $base_price, $type = 'vendor', $user_id = 0 ) {
        return OrderStateManager::calculate_share_and_cap( $base_price, $type, $user_id );
    }
}
