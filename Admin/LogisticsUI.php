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

		// 4. Show Sync Notices
		add_action( 'admin_notices', [ $this, 'display_sync_notices' ] );
	}

	public function add_logistics_meta_box() {
		$screens = [ 'shop_order', 'woocommerce_page_wc-orders' ];
		foreach ( $screens as $screen ) {
			add_meta_box(
				'zh_logistics_info',
				__( 'Logistics & Tracking', 'zerohold-shipping' ),
				[ $this, 'render_meta_box' ],
				$screen,
				'side',
				'high'
			);
		}
	}

	public function render_meta_box( $post_or_order ) {
		$order_id = ( $post_or_order instanceof \WP_Post ) ? $post_or_order->ID : $post_or_order->get_id();
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

			<?php 
			$vendor_refund = get_post_meta( $order_id, '_zh_shipping_refund_amount', true );
			$buyer_refund  = get_post_meta( $order_id, '_zh_rto_buyer_refund_amount', true );
			$buyer_penalty = get_post_meta( $order_id, '_zh_rto_buyer_penalty_amount', true );

			if ( $vendor_refund || $buyer_refund ) : ?>
				<div class="zh-rto-settlement" style="background: #f8fafc; border: 1px solid #e2e8f0; padding: 10px; border-radius: 4px; margin-top: 10px; font-size: 12px;">
					<h4 style="margin: 0 0 5px 0; font-size: 13px; border-bottom: 1px solid #e2e8f0; padding-bottom: 3px;"><?php _e( 'RTO Settlement', 'zerohold-shipping' ); ?></h4>
					<?php if ( $vendor_refund ) : ?>
						<p style="margin: 3px 0;"><strong><?php _e( 'Vendor Refund:', 'zerohold-shipping' ); ?></strong> ₹<?php echo number_format( $vendor_refund, 2 ); ?></p>
					<?php endif; ?>
					<?php if ( $buyer_refund || $buyer_penalty ) : ?>
						<p style="margin: 3px 0;"><strong><?php _e( 'Buyer Refund:', 'zerohold-shipping' ); ?></strong> ₹<?php echo number_format( $buyer_refund, 2 ); ?></p>
						<p style="margin: 3px 0; color: #dc2626; font-size: 11px;">(<?php printf( __( 'Penalty: ₹%s', 'zerohold-shipping' ), number_format( $buyer_penalty, 2 ) ); ?>)</p>
					<?php endif; ?>
				</div>
			<?php endif; ?>

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
			wp_die( __( 'Security check failed.', 'zerohold-shipping' ) );
		}

		$sync = new \Zerohold\Shipping\Core\LogisticsSynchronizer();
		$result = $sync->sync_order( $order_id );

		$order = wc_get_order( $order_id );
		$redirect_url = $order ? $order->get_edit_order_url() : admin_url( 'admin.php?page=wc-orders' );

		if ( $result['success'] ) {
			$redirect_url = add_query_arg( [
				'zh_tracking_synced' => 1,
				'zh_sync_status'      => urlencode( $result['status'] )
			], $redirect_url );
		} else {
			$redirect_url = add_query_arg( [
				'zh_tracking_failed' => 1,
				'zh_sync_error'      => urlencode( $result['message'] )
			], $redirect_url );
		}

		wp_safe_redirect( $redirect_url );
		exit;
	}

	public function display_sync_notices() {
		if ( isset( $_GET['zh_tracking_synced'] ) ) {
			$status = isset( $_GET['zh_sync_status'] ) ? sanitize_text_field( $_GET['zh_sync_status'] ) : '';
			?>
			<div class="notice notice-success is-dismissible">
				<p><?php printf( __( 'Tracking synchronized successfully. Status: %s', 'zerohold-shipping' ), '<strong>' . esc_html( $status ) . '</strong>' ); ?></p>
			</div>
			<?php
		}

		if ( isset( $_GET['zh_tracking_failed'] ) ) {
			$error = isset( $_GET['zh_sync_error'] ) ? sanitize_text_field( $_GET['zh_sync_error'] ) : 'Unknown error';
			?>
			<div class="notice notice-error is-dismissible">
				<p><?php printf( __( 'Tracking sync failed: %s', 'zerohold-shipping' ), esc_html( $error ) ); ?></p>
			</div>
			<?php
		}
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
