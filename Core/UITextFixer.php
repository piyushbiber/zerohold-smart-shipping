<?php

namespace Zerohold\Shipping\Core;

/**
 * UITextFixer handles surgical text replacements in the UI.
 * 
 * Specifically requested: Swap "Debit" and "Credit" labels in the Dokan Statement UI.
 */
class UITextFixer {

    public function __construct() {
        // We use gettext filter to intercept and swap strings
        // Priority 20 to run after most other translation overrides
        add_filter( 'gettext', [ $this, 'swap_statement_labels' ], 20, 3 );
    }

    /**
     * Swap "Debit" and "Credit" labels in the Dokan Statement report.
     * 
     * @param string $translated The translated text.
     * @param string $text       The original text.
     * @param string $domain     The text domain.
     * 
     * @return string The modified text.
     */
    public function swap_statement_labels( $translated, $text, $domain ) {
        // GUARD 1: Only affect the 'dokan' text domain
        if ( $domain !== 'dokan' ) {
            return $translated;
        }

        // GUARD 2: Only affect the Vendor Dashboard
        if ( ! function_exists( 'dokan_is_seller_dashboard' ) || ! dokan_is_seller_dashboard() ) {
            return $translated;
        }

        // GUARD 3: Only affect the Statement Report page
        // The URL path for the new analytics report is usually /analytics/statement
        $is_statement_page = ( isset( $_GET['path'] ) && strpos( $_GET['path'], 'statement' ) !== false ) || 
                             ( isset( $_GET['chart'] ) && $_GET['chart'] === 'sales_statement' );

        if ( ! $is_statement_page ) {
            return $translated;
        }

        // PERFORM SWAP
        // Exact matches from Dokan JS and PHP templates
        switch ( $text ) {
            case 'Total Debit':
                return 'Total Credit';
            
            case 'Total Credit':
                return 'Total Debit';

            case 'Debit':
                return 'Credit';

            case 'Credit':
                return 'Debit';
            
            // Handle lowercase variants if they exist
            case 'debit':
                return 'credit';
            
            case 'credit':
                return 'debit';
        }

        return $translated;
    }
}
