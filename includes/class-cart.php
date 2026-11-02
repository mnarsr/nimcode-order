<?php
defined( 'ABSPATH' ) || exit;

/**
 * Lightweight cart API on top of the native WooCommerce cart.
 *  GET  /nimcode/v1/cart
 *  POST /nimcode/v1/cart/add    {product_id, variation_id, qty, opts:{groupId:[idx]}, note}
 *  POST /nimcode/v1/cart/qty    {key, qty}   (0 = remove)
 *  POST /nimcode/v1/cart/note   {key, note}
 *  POST /nimcode/v1/cart/mode   {type:'delivery'|'pickup', plz}
 * Prices, delivery fee and minimum order are ALWAYS calculated on the server.
 */
class NC_Cart {

	public static function init() {
		add_action( 'rest_api_init', [ __CLASS__, 'routes' ] );
		add_action( 'woocommerce_before_calculate_totals', [ __CLASS__, 'apply_prices' ], 20 );
		add_action( 'woocommerce_cart_calculate_fees', [ __CLASS__, 'add_fee' ] );
		add_filter( 'woocommerce_get_item_data', [ __CLASS__, 'item_data' ], 10, 2 );
		add_action( 'woocommerce_checkout_create_order_line_item', [ __CLASS__, 'order_item_meta' ], 10, 4 );
		add_action( 'woocommerce_checkout_create_order', [ __CLASS__, 'order_meta' ] );
		add_action( 'woocommerce_store_api_checkout_update_order_meta', [ __CLASS__, 'order_meta' ] );
		add_action( 'woocommerce_checkout_process', [ __CLASS__, 'validate_classic' ] );
		add_action( 'woocommerce_store_api_cart_errors', [ __CLASS__, 'validate_blocks' ] );
	}

	public static function routes() {
		$ns = 'nimcode/v1';
		register_rest_route( $ns, '/cart', [ 'methods' => 'GET', 'callback' => [ __CLASS__, 'get' ], 'permission_callback' => '__return_true' ] );
		foreach ( [ 'add', 'qty', 'note', 'mode' ] as $r ) {
			register_rest_route( $ns, '/cart/' . $r, [ 'methods' => 'POST', 'callback' => [ __CLASS__, $r ], 'permission_callback' => '__return_true' ] );
		}
	}

	public static function boot() {
		if ( function_exists( 'wc_load_cart' ) ) {
			wc_load_cart();
		}
		if ( WC()->session && ! WC()->session->has_session() ) {
			WC()->session->set_customer_session_cookie( true );
		}
		// IMPORTANT: load the saved cart items BEFORE changing anything,
		// otherwise every "add" starts from an empty cart and replaces the old one.
		WC()->cart->get_cart();
	}

	/* ---------- Endpoints ---------- */

	public static function get() {
		self::boot();
		return self::state();
	}

	public static function add( WP_REST_Request $r ) {
		self::boot();
		$p   = (array) $r->get_json_params();
		$pid = absint( $p['product_id'] ?? 0 );
		$vid = absint( $p['variation_id'] ?? 0 );
		$qty = min( 20, max( 1, absint( $p['qty'] ?? 1 ) ) );

		if ( NC_Hours::paused() ) {
			return new WP_Error( 'nc_paused', NC_Hours::paused_msg(), [ 'status' => 400 ] );
		}
		$product = wc_get_product( $pid );
		if ( ! $product || ! $product->is_purchasable() || ! $product->is_in_stock() ) {
			return new WP_Error( 'nc_invalid', 'Produkt nicht verfügbar.', [ 'status' => 400 ] );
		}

		if ( ! NC_Avail::is_now( $pid ) ) {
			return new WP_Error( 'nc_invalid', $product->get_name() . ': ' . NC_Avail::text( $pid ), [ 'status' => 400 ] );
		}

		$attrs = [];
		if ( $product->is_type( 'variable' ) ) {
			$variation = $vid ? wc_get_product( $vid ) : null;
			if ( ! $variation || ! $variation->is_type( 'variation' ) || (int) $variation->get_parent_id() !== $pid
				|| ! $variation->is_purchasable() || ! $variation->is_in_stock() ) {
				return new WP_Error( 'nc_invalid', 'Bitte eine Variante wählen.', [ 'status' => 400 ] );
			}
			$attrs = $variation->get_variation_attributes();
			$base  = self::price_for( $variation ) ?: (float) $variation->get_price();
		} elseif ( $product->is_type( 'simple' ) ) {
			$vid  = 0;
			$base = self::price_for( $product ) ?: (float) $product->get_price();
		} else {
			return new WP_Error( 'nc_invalid', 'Produkttyp wird nicht unterstützt.', [ 'status' => 400 ] );
		}

		$opts = self::build_opts( $pid, (array) ( $p['opts'] ?? [] ), $vid );
		if ( is_wp_error( $opts ) ) {
			return $opts;
		}

		$key = WC()->cart->add_to_cart( $pid, $qty, $vid, $attrs, [
			'nc_base' => $base,
			'nc_opts' => $opts,
			'nc_note' => self::clean_note( $p['note'] ?? '' ),
		] );
		if ( ! $key ) {
			return new WP_Error( 'nc_cart', 'Artikel konnte nicht hinzugefügt werden.', [ 'status' => 400 ] );
		}
		return self::state();
	}

	public static function qty( WP_REST_Request $r ) {
		self::boot();
		$p   = (array) $r->get_json_params();
		$key = sanitize_text_field( (string) ( $p['key'] ?? '' ) );
		$qty = absint( $p['qty'] ?? 0 );

		if ( ! WC()->cart->get_cart_item( $key ) ) {
			return new WP_Error( 'nc_missing', 'Artikel nicht im Warenkorb.', [ 'status' => 404 ] );
		}
		if ( $qty < 1 ) {
			WC()->cart->remove_cart_item( $key );
		} else {
			WC()->cart->set_quantity( $key, min( 20, $qty ) );
		}
		return self::state();
	}

	public static function note( WP_REST_Request $r ) {
		self::boot();
		$p    = (array) $r->get_json_params();
		$key  = sanitize_text_field( (string) ( $p['key'] ?? '' ) );
		$cart = WC()->cart;
		if ( ! $cart->get_cart_item( $key ) ) {
			return new WP_Error( 'nc_missing', 'Artikel nicht im Warenkorb.', [ 'status' => 404 ] );
		}
		$contents                    = $cart->get_cart_contents();
		$contents[ $key ]['nc_note'] = self::clean_note( $p['note'] ?? '' );
		$cart->set_cart_contents( $contents );
		$cart->set_session();
		return self::state();
	}

	public static function mode( WP_REST_Request $r ) {
		self::boot();
		$p    = (array) $r->get_json_params();
		$type = ( $p['type'] ?? '' ) === 'delivery' ? 'delivery' : 'pickup';
		$plz  = substr( preg_replace( '/\D/', '', (string) ( $p['plz'] ?? '' ) ), 0, 5 );
		WC()->session->set( 'nc_type', $type );
		WC()->session->set( 'nc_plz', $type === 'delivery' ? $plz : '' );
		WC()->session->set( 'nc_city', $type === 'delivery' ? sanitize_text_field( (string) ( $p['city'] ?? '' ) ) : '' );
		return self::state();
	}

	/* ---------- Helpers ---------- */

	private static function clean_note( $note ) {
		return mb_substr( sanitize_textarea_field( (string) $note ), 0, 120 );
	}

	private static function money( $n ) {
		return html_entity_decode( wp_strip_all_tags( wc_price( $n ) ), ENT_QUOTES, 'UTF-8' );
	}

	private static function opt_label( $o ) {
		return $o['n'] . ( $o['p'] > 0 ? ' (+' . self::money( $o['p'] ) . ')' : '' );
	}

	/** Gross sum of the items (what the customer sees), before delivery fee. */
	private static function items_gross( $cart ) {
		$sum = 0.0;
		foreach ( $cart->get_cart() as $it ) {
			$sum += (float) wc_get_price_to_display( $it['data'], [ 'qty' => $it['quantity'] ] );
		}
		return round( $sum, 2 );
	}

	/** Delivery / pickup status for the current session. */
	private static function ship_info( $subtotal ) {
		$s    = WC()->session;
		$type = $s ? (string) $s->get( 'nc_type', '' ) : '';
		$plz  = $s ? (string) $s->get( 'nc_plz', '' ) : '';
		$city = $s ? (string) $s->get( 'nc_city', '' ) : '';
		$info = [ 'type' => $type, 'plz' => $plz, 'city' => '', 'cities' => [], 'zone' => false, 'fee' => 0.0, 'min' => 0.0, 'short' => 0.0, 'ok' => false, 'msg' => '' ];

		if ( $type === 'pickup' ) {
			$info['ok'] = true;
		} elseif ( $type === 'delivery' ) {
			if ( strlen( $plz ) !== 5 ) {
				$info['msg'] = 'Bitte Postleitzahl eingeben.';
			} else {
				$res            = NC_Delivery::resolve( $plz, $city );
				$info['cities'] = $res['cities'];
				$info['city']   = $res['city'];
				if ( ! $res['known'] ) {
					$info['msg'] = 'Wir liefern leider nicht in diese PLZ.';
				} elseif ( $res['need_city'] ) {
					$info['msg'] = 'Bitte Ort wählen.';
				} else {
					$zone          = $res['zone'];
					$info['zone']  = true;
					$info['fee']   = NC_Delivery::fee( $zone, $subtotal );
					$info['min']   = (float) $zone['min'];
					$info['short'] = max( 0, round( $info['min'] - $subtotal, 2 ) );
					$info['ok']    = $info['short'] <= 0;
				}
			}
		}
		return $info;
	}

	public static function checkout_error() {
		if ( ! WC()->cart || ! WC()->session ) {
			return '';
		}
		foreach ( WC()->cart->get_cart() as $item ) {
			$pid = (int) $item['product_id'];
			if ( ! NC_Avail::is_now( $pid ) ) {
				return get_the_title( $pid ) . ': ' . NC_Avail::text( $pid ) . ' Bitte aus dem Warenkorb entfernen.';
			}
		}
		$info = self::ship_info( self::items_gross( WC()->cart ) );
		if ( $info['type'] === '' ) {
			return 'Bitte Lieferung oder Abholung wählen.';
		}
		if ( $info['ok'] ) {
			return '';
		}
		return $info['msg'] ?: sprintf( 'Mindestbestellwert nicht erreicht (noch %s).', self::money( $info['short'] ) );
	}

	/* ---------- Validation: never trust the browser ---------- */

	private static function build_opts( $product_id, $sel, $variation_id = 0 ) {
		$out = [];
		foreach ( NC_Options::get_for_product( $product_id, $variation_id ) as $g ) {
			$picked = array_unique( array_map( 'absint', (array) ( $sel[ $g['id'] ] ?? [] ) ) );
			$picked = array_values( array_filter( $picked, function ( $i ) use ( $g ) {
				return isset( $g['options'][ $i ] );
			} ) );
			$n = count( $picked );

			if ( $g['required'] && $n < 1 ) {
				return new WP_Error( 'nc_invalid', 'Bitte wählen: ' . $g['title'], [ 'status' => 400 ] );
			}
			if ( ( $g['type'] === 'single' && $n > 1 ) || ( $g['max'] > 0 && $n > $g['max'] ) ) {
				return new WP_Error( 'nc_invalid', 'Zu viele Optionen: ' . $g['title'], [ 'status' => 400 ] );
			}
			foreach ( $picked as $i ) {
				$out[] = [ 'g' => $g['title'], 'n' => $g['options'][ $i ]['name'], 'p' => (float) $g['options'][ $i ]['price'] ];
			}
		}
		return $out;
	}

	public static function validate_classic() {
		if ( $e = self::checkout_error() ) {
			wc_add_notice( $e, 'error' );
		}
	}

	public static function validate_blocks( $errors ) {
		if ( ( $e = self::checkout_error() ) && $errors instanceof WP_Error ) {
			$errors->add( 'nc_checkout', $e );
		}
	}

	/**
	 * Raw product price for "delivery" or "pickup".
	 * Setting nc_price_mode = "split": delivery = regular price, pickup = sale price (if the product has one).
	 * Setting "wc": the WooCommerce price (sale price if active) for everybody.
	 * Never uses get_price(), because the cart changes that value (extras).
	 */
	public static function price_for( $product, $type = '' ) {
		$regular = (float) $product->get_regular_price();
		$sale    = $product->is_on_sale() ? (float) $product->get_sale_price() : 0.0;
		if ( get_option( 'nc_price_mode', 'wc' ) === 'split' ) {
			return ( $type === 'pickup' && $sale > 0 ) ? $sale : $regular;
		}
		return $sale > 0 ? $sale : $regular;
	}

	/* ---------- Price, fee, display ---------- */

	public static function apply_prices( $cart ) {
		if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
			return;
		}
		foreach ( $cart->get_cart() as $item ) {
			if ( ! isset( $item['nc_base'] ) ) {
				continue;
			}
			$extra = array_sum( wp_list_pluck( (array) ( $item['nc_opts'] ?? [] ), 'p' ) );
			$type  = WC()->session ? (string) WC()->session->get( 'nc_type', '' ) : '';
			$price = self::price_for( $item['data'], $type ) ?: (float) $item['nc_base'];
			$item['data']->set_price( $price + (float) $extra );
		}
	}

	/** Delivery fee as a WooCommerce cart fee (works in classic and block checkout). Not taxed. */
	public static function add_fee( $cart ) {
		if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
			return;
		}
		$s = WC()->session;
		if ( ! $s || $s->get( 'nc_type' ) !== 'delivery' ) {
			return;
		}
		$res  = NC_Delivery::resolve( (string) $s->get( 'nc_plz', '' ), (string) $s->get( 'nc_city', '' ) );
		$zone = $res['zone'];
		if ( ! $zone ) {
			return;
		}
		$fee = NC_Delivery::fee( $zone, self::items_gross( $cart ) );
		if ( $fee > 0 ) {
			$cart->add_fee( 'Liefergebühr', $fee, false );
		}
	}

	public static function item_data( $data, $item ) {
		if ( ! empty( $item['nc_opts'] ) ) {
			$data[] = [ 'key' => 'Optionen', 'value' => implode( ', ', array_map( [ __CLASS__, 'opt_label' ], $item['nc_opts'] ) ) ];
		}
		if ( ! empty( $item['nc_note'] ) ) {
			$data[] = [ 'key' => 'Anmerkung', 'value' => $item['nc_note'] ];
		}
		return $data;
	}

	public static function order_item_meta( $item, $cart_item_key, $values, $order ) {
		if ( ! empty( $values['nc_opts'] ) ) {
			$item->add_meta_data( '_nc_options', $values['nc_opts'] );
			$item->add_meta_data( 'Optionen', implode( ', ', array_map( [ __CLASS__, 'opt_label' ], $values['nc_opts'] ) ) );
		}
		if ( ! empty( $values['nc_note'] ) ) {
			$item->add_meta_data( '_nc_note', $values['nc_note'] );
			$item->add_meta_data( 'Anmerkung', $values['nc_note'] );
		}
	}

	/** Order type for the Windows app: _nc_type = delivery | pickup (+ _nc_plz). */
	public static function order_meta( $order ) {
		$s = WC()->session;
		if ( ! $s || ! $s->get( 'nc_type' ) ) {
			return;
		}
		$order->update_meta_data( '_nc_type', $s->get( 'nc_type' ) );
		if ( $s->get( 'nc_type' ) === 'delivery' ) {
			$order->update_meta_data( '_nc_plz', $s->get( 'nc_plz' ) );
		}
	}

	/* ---------- Response ---------- */

	private static function state() {
		nocache_headers();
		return rest_ensure_response( self::state_data() );
	}

	public static function state_data() {
		$cart = WC()->cart;
		$cart->calculate_totals();

		$items = [];
		$sum   = 0.0;
		foreach ( $cart->get_cart() as $key => $it ) {
			$total   = round( (float) $it['line_total'] + (float) $it['line_tax'], 2 ); // gross
			$sum    += $total;
			$items[] = [
				'key'   => $key,
				'name'  => $it['data']->get_name(),
				'qty'   => (int) $it['quantity'],
				'opts'  => array_map( function ( $o ) {
					return [ 'n' => $o['n'], 'p' => (float) $o['p'] ];
				}, (array) ( $it['nc_opts'] ?? [] ) ),
				'note'  => (string) ( $it['nc_note'] ?? '' ),
				'total' => $total,
			];
		}
		return [
			'items' => $items,
			'count' => (int) $cart->get_cart_contents_count(),
			'total' => round( $sum, 2 ),
			'ship'  => self::ship_info( self::items_gross( $cart ) ),
		];
	}
}
