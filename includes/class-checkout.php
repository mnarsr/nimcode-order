<?php
defined( 'ABSPATH' ) || exit;

/**
 * Own one-page checkout: shortcode [nimcode_checkout menu="/speisekarte/"]
 *  GET  /nimcode/v1/checkout/info   cart + times + payment options
 *  POST /nimcode/v1/checkout        creates the WooCommerce order (status: processing)
 */
class NC_Checkout {

	public static function init() {
		add_shortcode( 'nimcode_checkout', [ __CLASS__, 'render' ] );
		add_action( 'wp_enqueue_scripts', [ __CLASS__, 'assets' ] );
		add_action( 'rest_api_init', [ __CLASS__, 'routes' ] );
		add_action( 'save_post_page', function () {
			delete_transient( 'nc_checkout_url' );
			delete_transient( 'nc_menu_url' );
		} );
	}

	/** URL of the page that contains [nimcode_checkout] (cached). */
	public static function page_url() {
		$url = get_transient( 'nc_checkout_url' );
		if ( $url !== false ) {
			return $url;
		}
		$url = '';
		foreach ( get_posts( [ 'post_type' => 'page', 'post_status' => 'publish', 's' => 'nimcode_checkout', 'numberposts' => 5 ] ) as $p ) {
			if ( has_shortcode( $p->post_content, 'nimcode_checkout' ) ) {
				$url = get_permalink( $p );
				break;
			}
		}
		set_transient( 'nc_checkout_url', $url, 12 * HOUR_IN_SECONDS );
		return $url;
	}

	/** URL of the page that contains [nimcode_menu] (cached). */
	public static function menu_page_url() {
		$url = get_transient( 'nc_menu_url' );
		if ( $url !== false ) {
			return $url;
		}
		$url = '';
		foreach ( get_posts( [ 'post_type' => 'page', 'post_status' => 'publish', 's' => 'nimcode_menu', 'numberposts' => 5 ] ) as $p ) {
			if ( has_shortcode( $p->post_content, 'nimcode_menu' ) ) {
				$url = get_permalink( $p );
				break;
			}
		}
		set_transient( 'nc_menu_url', $url, 12 * HOUR_IN_SECONDS );
		return $url;
	}

	public static function assets() {
		$post = get_post();
		if ( ! $post || ! has_shortcode( $post->post_content, 'nimcode_checkout' ) ) {
			return;
		}
		wp_enqueue_style( 'nc-app', NC_URL . 'assets/app.css', [], NC_VERSION );
		wp_add_inline_style( 'nc-app', ':root{--nc-accent:' . NC_Admin::accent() . '}' );
		wp_enqueue_script( 'nc-co', NC_URL . 'assets/checkout.js', [], NC_VERSION, [ 'in_footer' => true, 'strategy' => 'defer' ] );
	}

	public static function render( $atts ) {
		$atts = shortcode_atts( [ 'menu' => '' ], $atts, 'nimcode_checkout' );
		$cfg  = [
			'rest' => esc_url_raw( rest_url( 'nimcode/v1/' ) ),
			'nonce' => is_user_logged_in() ? wp_create_nonce( 'wp_rest' ) : '',
			'cur'  => get_woocommerce_currency(),
			'menu' => $atts['menu'] ? esc_url_raw( $atts['menu'] ) : ( self::menu_page_url() ?: home_url( '/' ) ),
		];
		return '<div class="nc-co" id="nc-co"><script type="application/json" id="nc-co-data">'
			. wp_json_encode( $cfg, JSON_HEX_TAG | JSON_HEX_AMP ) . '</script></div>';
	}

	public static function routes() {
		register_rest_route( 'nimcode/v1', '/checkout/info', [ 'methods' => 'GET', 'callback' => [ __CLASS__, 'info' ], 'permission_callback' => '__return_true' ] );
		register_rest_route( 'nimcode/v1', '/checkout', [ 'methods' => 'POST', 'callback' => [ __CLASS__, 'submit' ], 'permission_callback' => '__return_true' ] );
	}

	public static function info() {
		NC_Cart::boot();
		nocache_headers();
		$cart = NC_Cart::state_data();
		$t    = NC_Hours::slots( $cart['ship']['type'] ?: 'pickup' );
		return rest_ensure_response( [
			'cart'     => $cart,
			'open_now' => $t['open_now'],
			'paused'   => NC_Hours::paused(),
			'paused_msg' => NC_Hours::paused_msg(),
			'slots'    => $t['slots'],
			'prep'     => $t['prep'],
			'hours'    => array_values( array_filter( array_map( 'trim', preg_split( '/\R/', (string) get_option( 'nc_hours', '' ) ) ) ) ),
			'card'     => (bool) get_option( 'nc_card', 1 ),
			'city'     => (string) ( get_option( 'nc_city', '' ) ?: get_option( 'nc_rest_city', '' ) ),
			'restaurant' => self::restaurant(),
		] );
	}

	public static function restaurant() {
		$street = (string) get_option( 'nc_rest_street', '' );
		$zip    = (string) get_option( 'nc_rest_zip', '' );
		$city   = (string) get_option( 'nc_rest_city', '' );
		$addr   = trim( $street . ', ' . trim( $zip . ' ' . $city ), ' ,' );
		return [
			'name'   => (string) ( get_option( 'nc_rest_name', '' ) ?: get_bloginfo( 'name' ) ),
			'street' => $street,
			'zip'    => $zip,
			'city'   => $city,
			'phone'  => (string) get_option( 'nc_rest_phone', '' ),
			'maps'   => $addr ? 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode( $addr ) : '',
		];
	}

	private static function err( $msg ) {
		return new WP_Error( 'nc_invalid', $msg, [ 'status' => 400 ] );
	}

	public static function submit( WP_REST_Request $r ) {
		NC_Cart::boot();
		$p = (array) $r->get_json_params();

		if ( ! empty( $p['website'] ) ) { // honeypot field
			return self::err( 'Fehler.' );
		}
		$cart = WC()->cart;
		if ( $cart->is_empty() ) {
			return self::err( 'Der Warenkorb ist leer.' );
		}
		if ( $e = NC_Cart::checkout_error() ) {
			return self::err( $e );
		}

		$s    = WC()->session;
		$type = (string) $s->get( 'nc_type' );
		$plz  = (string) $s->get( 'nc_plz', '' );
		$del  = $type === 'delivery';

		$name   = sanitize_text_field( (string) ( $p['name'] ?? '' ) );
		$phone  = sanitize_text_field( (string) ( $p['phone'] ?? '' ) );
		$email  = trim( (string) ( $p['email'] ?? '' ) );
		$street = sanitize_text_field( (string) ( $p['street'] ?? '' ) );
		$city   = sanitize_text_field( (string) ( $p['city'] ?? '' ) );
		$note   = mb_substr( sanitize_textarea_field( (string) ( $p['note'] ?? '' ) ), 0, 300 );
		if ( $del ) {
			$res = NC_Delivery::resolve( $plz, (string) $s->get( 'nc_city', '' ) );
			if ( $res['city'] !== '' ) {
				$city = $res['city']; // known place for this PLZ: do not trust the browser
			}
		}

		if ( mb_strlen( $name ) < 2 ) {
			return self::err( 'Bitte Namen angeben.' );
		}
		if ( strlen( preg_replace( '/\D/', '', $phone ) ) < 6 ) {
			return self::err( 'Bitte eine gültige Telefonnummer angeben.' );
		}
		if ( $email !== '' && ! is_email( $email ) ) {
			return self::err( 'Bitte eine gültige E-Mail-Adresse angeben.' );
		}
		if ( $del && ( $street === '' || $city === '' ) ) {
			return self::err( 'Bitte die Lieferadresse angeben.' );
		}

		// Time: validated on the server against the opening hours.
		$t    = NC_Hours::slots( $type );
		$when = (string) ( $p['time'] ?? 'asap' );
		if ( $when === 'asap' ) {
			if ( ! $t['open_now'] ) {
				return self::err( 'Wir haben aktuell geschlossen. Bitte eine Uhrzeit wählen.' );
			}
			$ts    = time() + $t['prep'] * 60;
			$label = 'Sofort';
		} else {
			if ( ! in_array( $when, $t['slots'], true ) ) {
				return self::err( 'Bitte eine gültige Uhrzeit wählen.' );
			}
			$ts    = NC_Hours::timestamp( $when );
			$label = $when;
		}

		$pay     = ( ( $p['pay'] ?? '' ) === 'card' && get_option( 'nc_card', 1 ) ) ? 'card' : 'cash';
		$parts   = preg_split( '/\s+/', $name, 2 );
		$country = WC()->countries->get_base_country();

		$data = [
			'billing_first_name' => $parts[0],
			'billing_last_name'  => $parts[1] ?? '',
			'billing_phone'      => $phone,
			'billing_email'      => $email,
			'billing_address_1'  => $del ? $street : '',
			'billing_city'       => $del ? $city : '',
			'billing_postcode'   => $del ? $plz : '',
			'billing_country'    => $country,
			'payment_method'     => 'cod',
			'order_comments'     => $note,
		];
		if ( $del ) {
			$data += [
				'shipping_first_name' => $parts[0],
				'shipping_last_name'  => $parts[1] ?? '',
				'shipping_phone'      => $phone,
				'shipping_address_1'  => $street,
				'shipping_city'       => $city,
				'shipping_postcode'   => $plz,
				'shipping_country'    => $country,
			];
		}

		try {
			$cart->calculate_totals();
			$order_id = WC()->checkout()->create_order( $data ); // uses cart items, fees (delivery fee), taxes and our item/order meta hooks
			if ( is_wp_error( $order_id ) ) {
				return self::err( $order_id->get_error_message() );
			}
			$order = wc_get_order( $order_id );
			$order->set_payment_method_title( ( $pay === 'card' ? 'Karte' : 'Bar' ) . ( $del ? ' bei Lieferung' : ' bei Abholung' ) );
			$order->update_meta_data( '_nc_time', $label );
			$order->update_meta_data( '_nc_time_ts', $ts );
			$order->update_meta_data( '_nc_pay', $pay );
			$order->update_status( 'processing', 'Bestellung über Nimcode Order.' );
			$order->save();
		} catch ( Exception $ex ) {
			return self::err( 'Die Bestellung konnte nicht gespeichert werden. Bitte erneut versuchen.' );
		}

		$cart->empty_cart();
		return rest_ensure_response( [
			'ok'     => true,
			'number' => $order->get_order_number(),
			'time'   => $label,
			'total'  => (float) $order->get_total(),
			'type'   => $type,
		] );
	}
}
