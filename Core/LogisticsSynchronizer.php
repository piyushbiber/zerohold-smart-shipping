<?php

namespace Zerohold\Shipping\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * LogisticsSynchronizer Class
 * 
 * Handles real-time synchronization of order tracking data and RTO detection.
 */
class LogisticsSynchronizer {

	public function __construct() {
		// Hook into WP Cron
		add_action( 'zh_logistics_sync_cron', [ $this, 'sync_all' ] );
	}

	/**
	 * Main Background Sync Logic
	 */
	public function sync_all() {
		// 1. Fetch Orders Needing Sync
		// Criteria: Status not terminal, Created last 30 days, has platform and AWB
		$args = [
			'status'     => [ 'processing', 'on-hold', 'rto-initiated' ],
			'limit'      => -1,
			'date_after' => date( 'Y-m-d', strtotime( '-30 days' ) ),
			'meta_query' => [
				[
					'key'     => '_zh_shipping_platform',
					'compare' => 'EXISTS',
				]
			],
		];
		
		$orders = wc_get_orders( $args );
		if ( empty( $orders ) ) {
			return;
		}

		// Group by platform
		$batched = [
			'shiprocket' => [],
			'bigship'    => []
		];

		foreach ( $orders as $order ) {
			$order_id = $order->get_id();
			$platform = $order->get_meta( '_zh_shipping_platform' );
			$awb      = $order->get_meta( '_zh_shiprocket_awb' ) ?: $order->get_meta( '_zh_awb' );

			if ( ! $awb ) {
				continue;
			}

			// Throttle: Only sync if last sync > 12 hours (wider for background)
			$last_sync = (int) $order->get_meta( '_zh_last_logistics_sync' );
			if ( ( time() - $last_sync ) < ( 12 * HOUR_IN_SECONDS ) ) {
				continue;
			}
			
			if ( isset( $batched[ $platform ] ) ) {
				$batched[ $platform ][ $order_id ] = $awb;
			}
		}

		// 2. Execute Shiprocket Sync (Bulk)
		if ( ! empty( $batched['shiprocket'] ) ) {
			$this->process_shiprocket_bulk( $batched['shiprocket'] );
		}

		// 3. Execute BigShip Sync (Spaced Individual)
		if ( ! empty( $batched['bigship'] ) ) {
			$this->process_bigship_spaced( $batched['bigship'] );
		}
	}

	private function process_shiprocket_bulk( $order_awb_map ) {
		$adapter = new \Zerohold\Shipping\Platforms\ShiprocketAdapter();
		
		// Split into chunks of 50
		$chunks = array_chunk( $order_awb_map, 50, true );

		foreach ( $chunks as $chunk ) {
			$awbs = array_values( $chunk );
			$response = $adapter->trackBulk( $awbs );

			if ( is_wp_error( $response ) ) continue;

			// Shiprocket bulk response usually returns data keyed by AWB or as a flat array
			// Based on user snippet, it's an array of objects.
			$tracking_data_list = $response['data'] ?? $response ?? [];
			
			foreach ( $tracking_data_list as $awb_key => $track_info ) {
				// Search for matching Order ID from our chunk
				$current_awb = $awb_key;
				if ( isset( $track_info['tracking_data']['shipment_track'][0]['awb_code'] ) ) {
					$current_awb = $track_info['tracking_data']['shipment_track'][0]['awb_code'];
				}

				$order_id = array_search( $current_awb, $chunk );
				if ( $order_id ) {
					$order = wc_get_order( $order_id );
					if ( $order ) {
						$this->process_tracking_data( $order, $track_info, 'shiprocket' );
					}
				}
			}
		}
	}

	private function process_bigship_spaced( $order_awb_map ) {
		$adapter = new \Zerohold\Shipping\Platforms\BigShipAdapter();
		
		// Limitation: Only sync 15 BigShip orders per run to avoid server lag/rate limits
		$limited_map = array_slice( $order_awb_map, 0, 15, true );

		foreach ( $limited_map as $order_id => $awb ) {
			$order = wc_get_order( $order_id );
			if ( ! $order ) continue;

			$tracking = $adapter->track( $awb );
			if ( ! is_wp_error( $tracking ) ) {
				$this->process_tracking_data( $order, $tracking, 'bigship' );
			}
			
			// Optional micro-sleep
			usleep( 200000 ); // 0.2s pause between calls
		}
	}

	/**
	 * Sync tracking data for a single order.
	 * 
	 * @param int $order_id
	 * @return array{success: bool, status?: string, message?: string}
	 */
	public function sync_order( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return [ 'success' => false, 'message' => 'Invalid order.' ];
		}

		$platform = get_post_meta( $order_id, '_zh_shipping_platform', true );
		if ( ! $platform ) {
			return [ 'success' => false, 'message' => 'No shipping platform associated with this order.' ];
		}

		$awb = get_post_meta( $order_id, '_zh_shiprocket_awb', true ) ?: get_post_meta( $order_id, '_zh_awb', true );
		if ( ! $awb ) {
			return [ 'success' => false, 'message' => 'No AWB found for this order.' ];
		}

		// Throttle syncs (6 hours)
		$last_sync = (int) get_post_meta( $order_id, '_zh_last_logistics_sync', true );
		if ( ( time() - $last_sync ) < 30 ) { // 30 seconds for manual refresh bypass during testing, normally 6h
			// return [ 'success' => true, 'message' => 'Recently synced.' ];
		}

		// Get Adapter
		$adapter = null;
		if ( $platform === 'shiprocket' ) {
			$adapter = new \Zerohold\Shipping\Platforms\ShiprocketAdapter();
		} elseif ( $platform === 'bigship' ) {
			$adapter = new \Zerohold\Shipping\Platforms\BigShipAdapter();
		}

		if ( ! $adapter ) {
			return [ 'success' => false, 'message' => "Adapter not found for platform: {$platform}" ];
		}

		// Fetch LIVE tracking
		$tracking = $adapter->track( $awb );
		if ( is_wp_error( $tracking ) ) {
			return [ 'success' => false, 'message' => $tracking->get_error_message() ];
		}

		// Process & Update
		return $this->process_tracking_data( $order, $tracking, $platform );
	}

	/**
	 * Process raw tracking data and update order status/metadata.
	 */
	private function process_tracking_data( $order, $tracking, $platform ) {
		$order_id = $order->get_id();
		$status_label = '';
		$target_rto_status = ''; // empty if not RTO
		$rto_reason = '';

		if ( $platform === 'shiprocket' ) {
			// Shiprocket Structure
			$data = $tracking['tracking_data'] ?? $tracking['data'] ?? [];
			$activities = $data['shipment_track_activities'] ?? [];
			$current_status = $data['shipment_track'][0]['current_status'] ?? '';
			
			$status_label = $current_status;

			// Logic: Scan for RTO status codes
			$shipment_status = (int) ( $data['shipment_status'] ?? 0 );

			if ( $shipment_status === 11 ) {
				// 11 = RTO Initiated. Check if moving.
				$target_rto_status = 'wc-rto-initiated';
				if ( stripos( $current_status, 'Transit' ) !== false ) {
					$target_rto_status = 'wc-rto-transit';
				}
			} elseif ( $shipment_status === 13 ) {
				// 13 = RTO Delivered
				$target_rto_status = 'wc-rto-delivered';
			} elseif ( $shipment_status === 14 ) {
				// 14 = Lost
				$target_rto_status = 'wc-rto-initiated';
				$rto_reason = __( 'CARRIER ALERT: Shipment marked as LOST.', 'zerohold-shipping' );
			} elseif ( stripos( $current_status, 'RTO' ) !== false || stripos( $current_status, 'Undelivered' ) !== false ) {
				$target_rto_status = 'wc-rto-initiated';
				if ( stripos( $current_status, 'Transit' ) !== false ) {
					$target_rto_status = 'wc-rto-transit';
				}
			}

			if ( $target_rto_status && empty( $rto_reason ) ) {
				$rto_reason = ! empty( $activities ) ? $activities[0]['activity'] : $current_status;
			}
		} elseif ( $platform === 'bigship' ) {
			// BigShip Structure
			$data = $tracking['data'] ?? [];
			$histories = $data['scan_histories'] ?? [];
			$order_detail = $data['order_detail'] ?? [];
			
			$status_label = $order_detail['current_tracking_status'] ?? '';
			
			if ( empty( $status_label ) && ! empty( $histories ) ) {
				$status_label = $histories[0]['scan_status'] ?? '';
			}

			// Map to granular statuses
			if ( $status_label === 'Undelivered' ) {
				$target_rto_status = 'wc-rto-initiated';
			} elseif ( $status_label === 'RTO In Transit' ) {
				$target_rto_status = 'wc-rto-transit';
			} elseif ( $status_label === 'RTO Delivered' ) {
				$target_rto_status = 'wc-rto-delivered';
			} elseif ( $status_label === 'Lost' ) {
				$target_rto_status = 'wc-rto-initiated';
				$rto_reason = __( 'CARRIER ALERT: Shipment marked as LOST.', 'zerohold-shipping' );
			}

			if ( $target_rto_status && empty( $rto_reason ) ) {
				$rto_reason = ! empty( $histories ) ? ( $histories[0]['scan_remarks'] ?? $histories[0]['scan_status'] ) : $status_label;
			}
		}

		// 1. Update Metadata
		update_post_meta( $order_id, '_zh_logistics_status', $status_label );
		update_post_meta( $order_id, '_zh_last_logistics_sync', time() );

		// 2. Handle Successful Delivery - Auto Complete Order
		if ( ! $target_rto_status ) {
			// Not an RTO/failed delivery, check if it's a successful delivery
			$delivered_keywords = [ 'delivered', 'delivery completed', 'shipment delivered' ];
			$is_delivered = false;
			
			foreach ( $delivered_keywords as $keyword ) {
				if ( stripos( $status_label, $keyword ) !== false ) {
					$is_delivered = true;
					break;
				}
			}
			
			// For Shiprocket, also check shipment_status code
			if ( ! $is_delivered && $platform === 'shiprocket' ) {
				$data = $tracking['tracking_data'] ?? $tracking['data'] ?? [];
				$shipment_status = (int) ( $data['shipment_status'] ?? 0 );
				// Shiprocket status code 7 = Delivered
				if ( $shipment_status === 7 ) {
					$is_delivered = true;
				}
			}
			
			if ( $is_delivered ) {
				$current_order_status = $order->get_status();
				
				// Only auto-complete if order is not already completed or cancelled
				if ( ! in_array( $current_order_status, [ 'completed', 'cancelled', 'refunded', 'failed' ], true ) ) {
					error_log( "ZSS: Auto-completing Order #{$order_id} - Delivery confirmed by {$platform}: {$status_label}" );
					$order->update_status( 'completed', sprintf( __( 'Order auto-completed by ZSS. Delivery confirmed by %s: %s', 'zerohold-shipping' ), $platform, $status_label ) );
				}
			}
		}

		// 3. Handle RTO Logic Transitions
		if ( $target_rto_status ) {
			$this->handle_rto_detection( $order, $status_label, $rto_reason, $target_rto_status );
		}

		return [
			'success' => true,
			'status'  => $status_label,
			'is_rto'  => ! empty( $target_rto_status ),
			'reason'  => $rto_reason
		];
	}

	/**
	 * Transition order through RTO stages (Initiated -> Transit -> Delivered)
	 */
	private function handle_rto_detection( $order, $status, $reason, $target_status ) {
		$order_id = $order->get_id();
		$current_status = $order->get_status();
		
		// Only transition if the new status is different and we haven't reached a later stage
		// Flow: any -> initiated -> transit -> delivered
		$stages = [ 'rto-initiated' => 1, 'rto-transit' => 2, 'rto-delivered' => 3 ];
		
		$current_stage = isset( $stages[ $current_status ] ) ? $stages[ $current_status ] : 0;
		$target_stage  = isset( $stages[ str_replace( 'wc-', '', $target_status ) ] ) ? $stages[ str_replace( 'wc-', '', $target_status ) ] : 0;

		if ( $target_stage > $current_stage ) {
			$order->update_status( $target_status, sprintf( __( 'RTO Stage Update by ZSS. Status: %s. Reason: %s', 'zerohold-shipping' ), $status, $reason ) );
			
			update_post_meta( $order_id, '_zh_rto_reason', $reason );
			update_post_meta( $order_id, '_zh_rto_date', current_time( 'mysql' ) );

			// 🚀 AUTOMATED REFUND TRIGGER (Phase 4)
			if ( $target_status === 'wc-rto-delivered' ) {
				\Zerohold\Shipping\Core\LogisticsRefundManager::process_rto_delivered_refund( $order_id );
			}
			
			error_log( "ZSS RTO ALERT: Order #{$order_id} moved to {$target_status}. Reason: {$reason}" );
		}
	}
}
