<?php

namespace Zerohold\Shipping\Core;

/**
 * UITextFixer handles surgical text replacements in the UI.
 * 
 * Specifically requested: Swap "Debit" and "Credit" labels in the Dokan Statement UI.
 * Uses a dual-layer approach (PHP + JS) to handle both server-rendered and React components.
 */
class UITextFixer {

    public function __construct() {
        // LAYER 1: PHP gettext filters (for server-side rendering)
        add_filter( 'gettext', [ $this, 'swap_labels_php' ], 20, 3 );
        add_filter( 'gettext_with_context', [ $this, 'swap_labels_php' ], 20, 3 );

        // LAYER 2: JavaScript hook (for React-based analytics/statements)
        add_action( 'wp_footer', [ $this, 'inject_js_labels_fix' ], 99 );
        add_action( 'admin_footer', [ $this, 'inject_js_labels_fix' ], 99 );
    }

    /**
     * Swap "Debit" and "Credit" labels in PHP.
     */
    public function swap_labels_php( $translated, $text, $domain ) {
        // ONLY target 'dokan' domain
        if ( $domain !== 'dokan' && $domain !== 'dokan-pro' ) {
            return $translated;
        }

        // ONLY on Dokan Dashboard
        if ( ! function_exists( 'dokan_is_seller_dashboard' ) || ! dokan_is_seller_dashboard() ) {
            return $translated;
        }

        return $this->perform_swap( $translated, $text );
    }

    /**
     * Inject JS to hook into wp.i18n for React-based UI.
     */
    public function inject_js_labels_fix() {
        if ( ! function_exists( 'dokan_is_seller_dashboard' ) || ! dokan_is_seller_dashboard() ) {
            return;
        }

        // Only inject on Statement Report page to prevent performance impact
        $is_statement_page = ( isset( $_GET['path'] ) && strpos( $_GET['path'], 'statement' ) !== false ) || 
                             ( isset( $_GET['chart'] ) && $_GET['chart'] === 'sales_statement' );

        if ( ! $is_statement_page ) {
            return;
        }

        ?>
        <script type="text/javascript">
            (function() {
                const swap = (text) => {
                    if (!text) return text;
                    const map = {
                        'Total Debit': 'Total Credit',
                        'Total Credit': 'Total Debit',
                        'Debit': 'Credit',
                        'Credit': 'Debit',
                        'debit': 'credit',
                        'credit': 'debit'
                    };
                    return map[text] || text;
                };

                // Hook into WordPress JS Translation system
                if (window.wp && wp.hooks && wp.hooks.addFilter) {
                    wp.hooks.addFilter('i18n.gettext', 'zss/swap-statement-labels', function(translated, text, domain) {
                        if (domain === 'dokan' || domain === 'dokan-pro') {
                            return swap(text);
                        }
                        return translated;
                    });
                }

                // Fallback for elements already rendered or bypassing i18n
                const observer = new MutationObserver(() => {
                    const cards = document.querySelectorAll('h3, th, span, p');
                    cards.forEach(el => {
                        const content = el.innerText.trim();
                        if (content === 'Total Debit') el.innerText = 'Total Credit';
                        else if (content === 'Total Credit') el.innerText = 'Total Debit';
                        else if (content === 'Debit') el.innerText = 'Credit';
                        else if (content === 'Credit') el.innerText = 'Debit';
                    });
                });

                observer.observe(document.body, { childList: true, subtree: true });
            })();
        </script>
        <?php
    }

    private function perform_swap( $translated, $text ) {
        switch ( $text ) {
            case 'Total Debit': return 'Total Credit';
            case 'Total Credit': return 'Total Debit';
            case 'Debit': return 'Credit';
            case 'Credit': return 'Debit';
            case 'debit': return 'credit';
            case 'credit': return 'debit';
        }
        return $translated;
    }
}
