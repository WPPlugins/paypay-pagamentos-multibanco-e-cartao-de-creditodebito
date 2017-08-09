<?php
/*
* Plugin Name: PayPay - Pagamentos Multibanco, Cartão de Crédito/Débito e MB WAY
* Plugin URI: https://www.paypay.pt
* Description: Comece já a emitir pagamentos por Multibanco, Cartão de Crédito/Débito ou MB WAY na sua loja com o módulo de pagamentos da Paypay para WooCommerce.
* Version: 1.3.0
* Author: PayPay, PAYPAYUE
* Author URI: https://www.paypay.pt
*/
add_option("paypay_db_version", "1.0");
$paypay_db_version = '1.2.2';

add_action('plugins_loaded', 'paypay_init', 0);
function paypay_init() {
	if (!class_exists('WC_Payment_Gateway')) return;

	include_once( 'class-wc-paypay.php' );

	load_plugin_textdomain('paypay', false, dirname( plugin_basename(__FILE__) ) . '/languages/');

	add_filter( 'woocommerce_payment_gateways', 'add_paypay_gateway' );
	function add_paypay_gateway( $methods ) {
		$methods[] = 'Paypay';
		return $methods;
	}

    global $paypay_db_version;
    if (get_site_option( 'paypay_db_version' ) != $paypay_db_version) {
        paypay_db_install();
    }
}

add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'paypay_action_links' );
function paypay_action_links( $links ) {
	$plugin_links = array(
		'<a href="' . admin_url( 'admin.php?page=wc-settings&tab=checkout&section=paypay' ) . '">' . __( 'Settings', 'paypay' ) . '</a>',
	);

	return array_merge( $plugin_links, $links );
}

register_activation_hook(__FILE__, 'pp_install');
function pp_install()
{
	paypay_db_install();
	require_once('class-wc-paypay.php');

	$woocommerce_paypay_settings = get_option('woocommerce_paypay_settings');
	if ($woocommerce_paypay_settings &&
		$woocommerce_paypay_settings['enabled'] == 'yes') {
		try {
			$paypay = new Paypay();
			$paypay->subscribeToWebhook();
		} catch (Exception $e) {
		}
	}
}

function paypay_db_install() {
	global $wpdb;
	global $paypay_db_version;

	$charset_collate = $wpdb->get_charset_collate();

	$sql1 = "CREATE TABLE `paypay_payment_type` (
	          id_order int(11) UNSIGNED,
			  payment_type tinyint(2)
			) $charset_collate;";
	$sql2 = "CREATE TABLE `paypay_reference` (
			  id_order int(11) UNSIGNED,
			  refMB varchar(9),
			  entity varchar(5),
			  amount decimal(15, 2),
			  id_transaction int(11) UNSIGNED,
			  paid tinyint(1) DEFAULT '0' NOT NULL,
			  comment_id int(11)
			) $charset_collate;";
	$sql3 = "CREATE TABLE `paypay_payment` (
	 		  id_order int(11) UNSIGNED,
			  id_transaction int(11) UNSIGNED,
			  redunicre_token varchar(40),
			  url varchar(300),
			  history_id int(11),
			  paid tinyint(1) DEFAULT '0' NOT NULL,
			  comment_id int(11)
			) $charset_collate;";
	$sql4 = "CREATE TABLE `paypay_config` (
			  hooked tinyint(2),
			  action varchar(100),
			  url varchar(500),
			  nif varchar(9)
			) $charset_collate;";

	require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
	dbDelta($sql1);
	dbDelta($sql2);
	dbDelta($sql3);
	dbDelta($sql4);

	update_option( "paypay_db_version", $paypay_db_version );
}

add_filter( 'woocommerce_available_payment_gateways', 'paypay_custom_available_payment_gateways', 2000 );
function paypay_custom_available_payment_gateways($gateways) {
    if (is_checkout() && isset($gateways['paypay'])) {
    	try {
    		$gateways['paypay']->addPaymentGatewaysTo($gateways);
    	} catch (Exception $e) {
    	}
    }
    unset($gateways['paypay']);

    return $gateways;
}


add_action( 'woocommerce_api_paypay_webhook', function() {
   	if ( $_SERVER['REQUEST_METHOD'] === 'POST' && array_key_exists('hookHash', $_POST) ) {
	   	$paypay = new Paypay();
	   	$paypay->webhookCallback($_POST);
   	}
});

add_action( 'woocommerce_api_paypay_cancel', function() {
   	if (array_key_exists('order_id', $_GET)) {
		$paypay = new Paypay();
	   	$paypay->failureCallback($_GET['order_id']);
   	}
});


// add the action
add_action('woocommerce_order_status_cancelled', 'woocommerce_paypay_order_status_cancelled');

// define the woocommerce_paypay_order_status_cancelled callback
function woocommerce_paypay_order_status_cancelled($order_id)
{
	$paypay = new Paypay();
	$paypay->addThankYouNote($order_id, 0);
};

add_action( 'woocommerce_email_before_order_table', 'add_paypay_order_email_instructions', 10, 2 );

function add_paypay_order_email_instructions($order, $sent_to_admin)
{
	if ($sent_to_admin) {
		return;
	}
	$paypay = new Paypay();
	if ($order->is_paid() || $order->has_status('cancelled')) {
		echo $paypay->getThankYouLayout(1);
		return;
	}

	if ( method_exists( $order, 'get_id' ) ) {
	    $orderId = $order->get_id();
	} else {
	    $orderId = $order->id;
	}

	$paypayNote = $paypay->getPayPayOrderNote($orderId);

	if ($paypayNote === false) {
		return;
	}

    echo $paypayNote->comment_content;

}

add_action( 'woocommerce_order_details_after_order_table', 'add_paypay_order_instructions', 10, 1 );

function add_paypay_order_instructions($order)
{
	$paypay = new Paypay();

	if ($order->is_paid() || $order->has_status('cancelled')) {
		return;
	}

	if ( method_exists( $order, 'get_id' ) ) {
	    $orderId = $order->get_id();
	} else {
	    $orderId = $order->id;
	}

	$paypayNote = $paypay->getPayPayOrderNote($orderId);

	if ($paypayNote === false) {
		return;
	}
	echo "<h2>".__( 'Payment details', 'paypay' )."</h2>";
    echo $paypayNote->comment_content;
    echo "<br><br>";
}
