<?php
namespace Zerohold\Shipping\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class DebugListener
 * 
 * A safe, removable debugging add-on.
 * Listens for 'zh_debug_log' actions and writes them to debug.log.
 * 
 * USAGE:
 * do_action( 'zh_debug_log', 'My Title', $some_array );
 * 
 * SAFETY:
 * To disable debugging, simply delete this file or remove its instantiation.
 * The 'do_action' calls in your main code will seamlessly do nothing.
 */
class DebugListener {

	public function __construct() {
		// Hook into the 'safe' log action
		add_action( 'zh_debug_log', array( $this, 'handle_log' ), 10, 2 );
	}

	/**
	 * Writes data to the standard debug.log
	 * 
	 * @param string $title  Short title/context for the log
	 * @param mixed  $data   (Optional) Array or Object to inspect
	 */
	public function handle_log( $title, $data = null ) {
		// Optional: Check if WP_DEBUG is enabled
		// if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) return;

		$output = "[ZSS-SAFE] " . $title;
		
		if ( $data !== null ) {
			if ( is_array( $data ) || is_object( $data ) ) {
				$output .= ": " . print_r( $data, true );
			} else {
				$output .= ": " . $data;
			}
		}

		error_log( $output );
	}
}
