<?php
/**
 * Plugin Name: LeanCart Store Credit
 * Plugin URI:  https://watertreatmentpro.com/
 * Description: The admin assigns a balance to a customer from their user profile. The customer can use the balance at checkout; the amount is deducted when the order is created and restored if it is cancelled or refunded.
 * Version:     1.0.0
 * Author:      LeanCart
 * Author URI:  https://leancart.dev
 * License:     GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: store-credit
 * Requires Plugins: woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'LEANCART_STORE_CREDIT_DEMO_SITE' ) ) {
	define( 'LEANCART_STORE_CREDIT_DEMO_SITE', 'https://watertreatmentpro.com' );
}

require_once __DIR__ . '/includes/class-leancart-framework.php';

final class LeanCart_Store_Credit {

	const SLUG = 'store-credit';

	public static function init() {
		LeanCart_Framework::boot( array(
			'slug'    => self::SLUG,
			'name'    => 'LeanCart Store Credit',
			'version' => '1.0.0',
			'file'    => __FILE__,
			'site'    => LEANCART_STORE_CREDIT_DEMO_SITE,
		) );

		// WooCommerce may load after these plugins; defer the check to runtime.
		add_action( 'admin_notices', array( __CLASS__, 'wc_notice' ) );

		load_plugin_textdomain( 'store-credit', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

		add_action( 'leancart_' . self::SLUG . '_settings_fields', array( __CLASS__, 'fields' ) );

		// WooCommerce may load after this plugin; hooks are registered once WC is ready.
		if ( LeanCart_Framework::is_wc() ) {
			self::hooks();
		} else {
			add_action( 'woocommerce_loaded', array( __CLASS__, 'hooks' ) );
		}
	}

	public static function wc_notice() {
		if ( LeanCart_Framework::is_wc() ) { return; }
		echo '<div class="notice notice-warning"><p><strong>' . esc_html( 'LeanCart Store Credit' ) . '</strong>: ';
		esc_html_e( 'This plugin requires WooCommerce to be active.', 'store-credit' );
		echo '</p></div>';
	}

	public static function fields() {
		LeanCart_Framework::field_text( 'label', __('Checkout line name', 'store-credit'), __('E.g.: Store credit', 'store-credit'), 'text' );
	}


	public static function hooks() {
		add_action( 'show_user_profile', array( __CLASS__, 'profile' ) );
		add_action( 'edit_user_profile', array( __CLASS__, 'profile' ) );
		add_action( 'personal_options_update', array( __CLASS__, 'save_profile' ) );
		add_action( 'edit_user_profile_update', array( __CLASS__, 'save_profile' ) );
		add_action( 'woocommerce_review_order_before_payment', array( __CLASS__, 'checkout_field' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'script' ) );
		add_action( 'woocommerce_cart_calculate_fees', array( __CLASS__, 'apply' ) );
		add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'order_meta' ), 10, 2 );
		add_action( 'woocommerce_checkout_order_created', array( __CLASS__, 'deduct' ) );
		add_action( 'woocommerce_order_status_cancelled', array( __CLASS__, 'restore' ) );
		add_action( 'woocommerce_order_status_refunded', array( __CLASS__, 'restore' ) );
		add_action( 'woocommerce_order_fully_refunded', array( __CLASS__, 'restore' ) );
		add_action( 'woocommerce_account_dashboard', array( __CLASS__, 'account' ) );
		add_filter( 'query_vars', array( __CLASS__, 'query_var' ) );
	}

	private static function credit( $uid ) { return (float) get_user_meta( $uid, '_lc_credit', true ); }
	private static function set_credit( $uid, $v ) { update_user_meta( $uid, '_lc_credit', max( 0, round( $v, 2 ) ) ); }

	public static function profile( $user ) {
		if ( ! current_user_can( 'manage_woocommerce' ) ) { return; }
		wp_nonce_field( 'lc_store_credit_save', '_lc_credit_nonce' );
		echo '<h3>' . esc_html__( 'Store credit', 'store-credit' ) . '</h3>';
		echo '<table class="form-table"><tr><th><label for="lc_credit">' . esc_html__( 'Balance', 'store-credit' ) . '</label></th>';
		echo '<td><input type="number" step="0.01" id="lc_credit" name="lc_credit" value="' . esc_attr( self::credit( $user->ID ) ) . '" /> <span class="description">' . wp_kses_post( LeanCart_Framework::money( self::credit( $user->ID ) ) ) . '</span></td></tr></table>';
	}

	public static function save_profile( $uid ) {
		if ( ! current_user_can( 'manage_woocommerce' ) ) { return; }
		if ( empty( $_POST['_lc_credit_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_lc_credit_nonce'] ) ), 'lc_store_credit_save' ) ) { return; }
		if ( ! isset( $_POST['lc_credit'] ) ) { return; }
		$raw = wp_unslash( $_POST['lc_credit'] );
		self::set_credit( $uid, is_numeric( $raw ) ? (float) $raw : 0 );
	}

	public static function checkout_field() {
		if ( ! is_user_logged_in() || ! function_exists( 'WC' ) || ! WC()->session ) { return; }
		$credit = self::credit( get_current_user_id() );
		if ( $credit <= 0 ) { return; }
		echo '<div class="lc-store-credit" style="padding:12px;margin-bottom:16px;border:1px solid #dcdcde">';
		echo '<label><input type="checkbox" name="lc_use_credit_toggle" id="lc_use_credit_toggle" value="1" /> ' . esc_html__( 'Use store credit', 'store-credit' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'Available balance:', 'store-credit' ) . ' <strong>' . wp_kses_post( LeanCart_Framework::money( $credit ) ) . '</strong></p>';
		echo '<p class="form-row form-row-wide" id="lc_credit_amount_row" style="display:none"><input type="number" step="0.01" min="0" max="' . esc_attr( round( $credit, 2 ) ) . '" name="lc_use_credit" id="lc_use_credit" value="" data-credit="' . esc_attr( round( $credit, 2 ) ) . '" /></p>';
		echo '</div>';
	}

	public static function script() {
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) { return; }
		LeanCart_Framework::enqueue_js( "
			jQuery(function(\$){
				var lcOpted = false, lcAmount = '';
				var lcSync = function(on){
					\$('#lc_credit_amount_row').toggle(on);
					var \$in = \$('#lc_use_credit');
					if (on) { if (!\$in.val()) { \$in.val(\$in.data('credit')); } lcAmount = \$in.val(); }
					else { \$in.val(''); lcAmount = ''; }
					lcOpted = on;
				};
				\$(document).on('click', '#lc_use_credit_toggle', function(){
					lcSync(\$(this).is(':checked'));
				});
				\$(document).on('change', '#lc_use_credit_toggle', function(){
					lcSync(\$(this).is(':checked'));
					\$('body').trigger('update_checkout');
				});
				\$(document).on('change blur', '#lc_use_credit', function(){
					lcAmount = \$(this).val();
					\$('body').trigger('update_checkout');
				});
				\$(document).on('updated_checkout', function(){
					if (!\$('#lc_use_credit_toggle').length) { return; }
					\$('#lc_use_credit_toggle').prop('checked', lcOpted);
					\$('#lc_credit_amount_row').toggle(lcOpted);
					\$('#lc_use_credit').val(lcOpted ? (lcAmount || \$('#lc_use_credit').data('credit')) : '');
				});
			});
		" );
	}

	private static function requested() {
		$data = array();
		if ( isset( $_POST['post_data'] ) ) {
			parse_str( wp_unslash( $_POST['post_data'] ), $data );
		}
		foreach ( array( 'lc_use_credit_toggle', 'lc_use_credit' ) as $key ) {
			if ( isset( $_POST[ $key ] ) ) { $data[ $key ] = wp_unslash( $_POST[ $key ] ); }
		}
		if ( ! isset( $data['lc_use_credit_toggle'] ) || '1' !== (string) $data['lc_use_credit_toggle'] ) { return false; }
		$raw = isset( $data['lc_use_credit'] ) ? $data['lc_use_credit'] : '';
		return is_numeric( $raw ) ? max( 0, (float) $raw ) : false;
	}

	public static function apply( $cart ) {
		if ( ! function_exists( 'WC' ) || ! WC()->cart || ! WC()->session ) { return; }
		if ( is_admin() && ! defined( 'DOING_AJAX' ) ) { return; }
		if ( ! WC()->session->has_session() && ! headers_sent() ) { WC()->session->set_customer_session_cookie( true ); }
		if ( ! is_user_logged_in() ) { WC()->session->set( 'lc_credit_used', 0 ); return; }
		$credit = self::credit( get_current_user_id() );
		$requested = self::requested();
		$use = $requested > 0 ? min( $requested, $credit, (float) $cart->get_subtotal() ) : 0;
		$use = max( 0, $use );
		if ( $use > 0 ) {
			$cart->add_fee( LeanCart_Framework::setting( 'label', __( 'Store credit', 'store-credit' ) ), -$use, true );
		}
		WC()->session->set( 'lc_credit_used', $use );
	}

	public static function order_meta( $order, $data ) {
		$used = function_exists( 'WC' ) && WC()->session ? (float) WC()->session->get( 'lc_credit_used' ) : 0;
		if ( $used > 0 ) { $order->update_meta_data( '_lc_credit_used', $used ); }
	}

	public static function deduct( $order ) {
		if ( is_numeric( $order ) ) { $order = wc_get_order( $order ); }
		if ( ! $order || $order->get_meta( '_lc_credit_deducted' ) ) { return; }
		$uid = (int) ( $order->get_customer_id() ? $order->get_customer_id() : get_current_user_id() );
		$used = (float) $order->get_meta( '_lc_credit_used' );
		$order->update_meta_data( '_lc_credit_deducted', 'yes' );
		if ( $used > 0 && $uid ) { self::set_credit( $uid, self::credit( $uid ) - $used ); }
		$order->save();
		if ( function_exists( 'WC' ) && WC()->session ) { WC()->session->set( 'lc_credit_used', 0 ); }
	}

	public static function restore( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order || ! $order->get_meta( '_lc_credit_deducted' ) || $order->get_meta( '_lc_credit_restored' ) ) { return; }
		$uid = (int) $order->get_customer_id();
		$used = (float) $order->get_meta( '_lc_credit_used' );
		if ( ! $uid || $used <= 0 ) { return; }
		self::set_credit( $uid, self::credit( $uid ) + $used );
		$order->update_meta_data( '_lc_credit_restored', 'yes' );
		$order->save();
	}

	public static function account() {
		if ( ! is_user_logged_in() ) { return; }
		$credit = self::credit( get_current_user_id() );
		echo '<p>' . esc_html__( 'Your store balance:', 'store-credit' ) . ' <strong>' . wp_kses_post( LeanCart_Framework::money( $credit ) ) . '</strong></p>';
	}

	public static function query_var( $vars ) { $vars[] = 'lc_use_credit'; return $vars; }


}

LeanCart_Store_Credit::init();

