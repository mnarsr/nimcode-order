<?php
/**
 * Plugin Name: Nimcode Order
 * Description: Schnelles Restaurant-Bestellsystem für WooCommerce (AJAX, Extras, Lieferung per PLZ, Anbindung an Nimcode-KDS).
 * Version: 0.13.0
 * Author: Nimcode
 * Text Domain: nimcode-order
 */
defined( 'ABSPATH' ) || exit;

define( 'NC_VERSION', '0.13.0' );
define( 'NC_PATH', plugin_dir_path( __FILE__ ) );
define( 'NC_URL', plugin_dir_url( __FILE__ ) );

require_once NC_PATH . 'includes/class-options.php';
require_once NC_PATH . 'includes/class-api.php';
require_once NC_PATH . 'includes/class-dashboard.php';
require_once NC_PATH . 'includes/class-admin.php';
require_once NC_PATH . 'includes/class-delivery.php';
require_once NC_PATH . 'includes/class-hours.php';
require_once NC_PATH . 'includes/class-avail.php';
require_once NC_PATH . 'includes/class-cart.php';
require_once NC_PATH . 'includes/class-menu.php';
require_once NC_PATH . 'includes/class-checkout.php';
require_once NC_PATH . 'includes/class-demo.php';
require_once NC_PATH . 'includes/class-legal.php';
require_once NC_PATH . 'includes/class-watch.php';

add_action( 'plugins_loaded', function () {
	if ( ! class_exists( 'WooCommerce' ) ) {
		return; // WooCommerce is required.
	}
	NC_Options::init();
	NC_Admin::init();
	NC_Delivery::init();
	NC_Cart::init();
	NC_Menu::init();
	NC_Checkout::init();
	NC_Avail::init();
	NC_Api::init();
	NC_Demo::init();
	NC_Legal::init();
	NC_Watch::init();
} );
