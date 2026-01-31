<?php

namespace Zerohold\Shipping\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * LogisticsUI Class
 * 
 * Handles Admin UI for tracking synchronization and logistics display.
 */
class LogisticsUI {

	public function __construct() {
		// 1. Meta Box for Logistics Info
		add_action( 'add_meta_boxes', [ $this, 'add_logistics_meta_box' ] );

		// 2. Sync Trigger (AJAX or GET)
		add_action( 'admin_init', [ $this, 'handle_manual_sync' ] );

		// 3. Auto-sync on Order View
		add_action( 'woocommerce_admin_order_data_after_order_details', [ $this, 'trigger_lazy_sync' ] );
	}

	public function add_logistics_meta_box() {
		add_meta_box(
			'zh_logistics_info',
			__( 'Logistics & Tracking', 'zerohold-shipping' ),
			[ $this, 'render_meta_box' ],
			'shop_order',
			'side',
			'high'
		);
	}

	public function render_meta_box( $post ) {
		$order_id = $post->ID;
		$status   = get_post_meta( $order_id, '_zh_logistics_status', true ) ?: 'Not Synced';
		$last_sync = get_post_meta( $order_id, '_zh_last_logistics_sync', true );
		$platform  = get_post_meta( $order_id, '_zh_shipping_platform', true );
		$rto_reason = get_post_meta( $order_id, '_zh_rto_reason', true );

		$sync_url = wp_nonce_url( add_query_arg( 'zh_sync_tracking', $order_id ), 'zh_sync_tracking_nonce' );

		?>
		<div class="zh-logistics-meta-box">
			<p><strong><?php _e( 'Current Status:', 'zerohold-shipping' ); ?></strong> <span class="status-badge"><?php echo esc_html( $status ); ?></span></p>
			
			<?php if ( $platform ) : ?>
				<p><strong><?php _e( 'Platform:', 'zerohold-shipping' ); ?></strong> <?php echo esc_html( ucfirst( $platform ) ); ?></p>
			<?php endif; ?>

			<?php if ( $rto_reason ) : ?>
				<p style="color: #9a3412; background: #fff7ed; padding: 5px; border: 1px solid #fdba74; border-radius: 4px;">
					<strong><?php _e( 'RTO Reason:', 'zerohold-shipping' ); ?></strong><br>
					<?php echo esc_html( $rto_reason ); ?>
				</p>
			<?php endif; ?>

			<p style="font-size: 11px; color: #666;">
				<?php if ( $last_sync ) : ?>
					<?php printf( __( 'Last Checked: %s', 'zerohold-shipping' ), human_time_diff( $last_sync ) . ' ago' ); ?>
				<?php else : ?>
					<?php _e( 'Never synced.', 'zerohold-shipping' ); ?>
				<?php endif; ?>
			</p>

			<hr>
			<a href="<?php echo esc_url( $sync_url ); ?>" class="button button-secondary" style="width: 100%; text-align: center;">
				<?php _e( '🔄 Sync Tracking', 'zerohold-shipping' ); ?>
			</a>
		</div>
		<style>
			.zh-logistics-meta-box .status-badge {
				background: #e2e8f0;
				padding: 2px 6px;
				border-radius: 3px;
				font-size: 11px;
				text-transform: uppercase;
				font-weight: 600;
			}
		</style>
		<?php
	}

	public function handle_manual_sync() {
		if ( ! isset( $_GET['zh_sync_tracking'] ) || ! isset( $_GET['_wpnonce'] ) ) {
			return;
		}

		$order_id = intval( $_GET['zh_sync_tracking'] );
		if ( ! wp_verify_nonce( $_GET['_wpnonce'], 'zh_sync_tracking_nonce' ) ) {
			return;
		}

		$sync = new \Zerohold\Shipping\Core\LogisticsSynchronizer();
		$result = $sync->sync_order( $order_id );

		if ( $result['success'] ) {
			wc_add_notice( sprintf( __( 'Tracking synced. Status: %s', 'zerohold-shipping' ), $result['status'] ), 'success' );
		} else {
			wc_add_notice( sprintf( __( 'Sync failed: %s', 'zerohold-shipping' ), $result['message'] ), 'error' );
		}

		wp_safe_redirect( admin_url( 'post.php?post=' . $order_id . '&action=edit' ) );
		exit;
	}

	/**
	 * Automatically trigger sync if viewing a relevant order and it's been a while.
	 */
	public function trigger_lazy_sync( $order ) {
		if ( ! is_admin() || ! isset( $_GET['action'] ) || $_GET['action'] !== 'edit' ) {
			return;
		}

		$order_id = $order->get_id();
		$last_sync = (int) get_post_meta( $order_id, '_zh_last_logistics_sync', true );

		// Only auto-sync if label exists AND order is processing/on-hold AND synced > 6 hours ago
		$label_status = get_post_meta( $order_id, '_zh_shiprocket_label_status', true );
		if ( $label_status == 1 && $order->has_status( [ 'processing', 'on-hold', 'rto-initiated' ] ) ) {
			if ( ( time() - $last_sync ) > ( 6 * HOUR_IN_SECONDS ) ) {
				$sync = new \Zerohold\Shipping\Core\LogisticsSynchronizer();
				$sync->sync_order( $order_id );
			}
		}
	}
}
