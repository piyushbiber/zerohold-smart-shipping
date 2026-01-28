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

        // LAYER 2: JavaScript hook (everywhere for now to ensure it works)
        add_action( 'wp_head', [ $this, 'inject_js_labels_fix' ], 1 );
        add_action( 'wp_footer', [ $this, 'inject_js_labels_fix' ], 999 );
        add_action( 'admin_footer', [ $this, 'inject_js_labels_fix' ], 999 );
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
        // Broadly inject during debug
        ?>
        <script type="text/javascript">
            (function() {
                console.log('ZSS: UITextFixer Active');

                const swapLabels = () => {
                    const elements = document.querySelectorAll('h3, th, span, p, .components-button');
                    elements.forEach(el => {
                        if (!el.childNodes || el.childNodes.length === 0) return;
                        
                        // Check text content case-insensitively
                        const text = el.innerText || '';
                        
                        // Total Debit -> Total Credit
                        if (/Total Debit/i.test(text)) {
                            el.innerHTML = el.innerHTML.replace(/Total Debit/gi, 'Total Credit');
                        } 
                        // Total Credit -> Total Debit
                        else if (/Total Credit/i.test(text)) {
                            el.innerHTML = el.innerHTML.replace(/Total Credit/gi, 'Total Debit');
                        }
                        // Debit (Exact) -> Credit
                        else if (/^Debit$/i.test(text.trim())) {
                            el.innerText = text.replace(/Debit/i, 'Credit');
                        }
                        // Credit (Exact) -> Debit
                        else if (/^Credit$/i.test(text.trim())) {
                            el.innerText = text.replace(/Credit/i, 'Debit');
                        }
                    });
                };

                // 1. Initial run
                setTimeout(swapLabels, 500);
                setTimeout(swapLabels, 2000); // Second run for React lag

                // 2. Mutation Observer for React dynamic changes
                const observer = new MutationObserver(swapLabels);
                observer.observe(document.body, { 
                    childList: true, 
                    subtree: true,
                    characterData: true 
                });

                // 3. i18n Hook
                if (window.wp && wp.hooks) {
                    wp.hooks.addFilter('i18n.gettext', 'zss/swap', (translated, text) => {
                        if (/Total Debit/i.test(text)) return translated.replace(/Total Debit/gi, 'Total Credit');
                        if (/Total Credit/i.test(text)) return translated.replace(/Total Credit/gi, 'Total Debit');
                        if (/^Debit$/i.test(text)) return 'Credit';
                        if (/^Credit$/i.test(text)) return 'Debit';
                        return translated;
                    });
                }
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
