<?php
defined( 'ABSPATH' ) || exit;

/**
 * REST API for the Windows cashier app (Nimcode-KDS).
 * Auth: header  X-Nimcode-Token: <token>   (token is created under Einstellungen > Windows-App)
 *
 *  GET  /nimcode/v1/ping
 *  GET  /nimcode/v1/status            { paused, message }
 *  POST /nimcode/v1/status            { paused: true|false }  pause / reopen online orders
 *  GET  /nimcode/v1/orders?limit=50&hours=72&status=processing   latest orders (default: status processing, 72 hours)
 *       optional: unacked=1 (only orders not confirmed yet), since_id=123
 *  GET  /nimcode/v1/orders?since_id=123     orders with a higher ID
 *  GET  /nimcode/v1/orders/{id}             one order (e.g. for reprint)
 *  POST /nimcode/v1/orders/{id}/ack         app confirms it received the order (status is NOT changed)
 */
class NC_Api {

	const NS = 'nimcode/v1';

	public static function init() {
		add_action( 'rest_api_init', [ __CLASS__, 'routes' ] );
		add_action( 'admin_post_nc_token', [ __CLASS__, 'new_token' ] );
	}

	public static function routes() {
		$auth = [ __CLASS__, 'auth' ];
		register_rest_route( self::NS, '/ping', [ 'methods' => 'GET', 'callback' => [ __CLASS__, 'ping' ], 'permission_callback' => $auth ] );
		register_rest_route( self::NS, '/status', [
			[ 'methods' => 'GET', 'callback' => [ __CLASS__, 'status' ], 'permission_callback' => $auth ],
			[ 'methods' => 'POST', 'callback' => [ __CLASS__, 'set_status' ], 'permission_callback' => $auth ],
		] );
		register_rest_route( self::NS, '/orders', [ 'methods' => 'GET', 'callback' => [ __CLASS__, 'orders' ], 'permission_callback' => $auth ] );
		register_rest_route( self::NS, '/orders/(?P<id>\d+)', [ 'methods' => 'GET', 'callback' => [ __CLASS__, 'one' ], 'permission_callback' => $auth ] );
		register_rest_route( self::NS, '/orders/(?P<id>\d+)/ack', [ 'methods' => 'POST', 'callback' => [ __CLASS__, 'ack' ], 'permission_callback' => $auth ] );
	}

	/* ---------- Token ---------- */

	public static function auth( WP_REST_Request $r ) {
		$hash  = (string) get_option( 'nc_api_token_hash', '' );
		$token = (string) $r->get_header( 'X-Nimcode-Token' );
		if ( $hash === '' || $token === '' || ! hash_equals( $hash, hash( 'sha256', $token ) ) ) {
			return new WP_Error( 'nc_auth', 'Ungültiger Token.', [ 'status' => 401 ] );
		}
		if ( time() - (int) get_option( 'nc_api_last_seen', 0 ) >= 10 ) {
			update_option( 'nc_api_last_seen', time(), false ); // shown in the admin as "connected"
		}
		return true;
	}

	/** Creates a new token. Only its hash is stored; the plain token is shown once. */
	public static function new_token() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'Nicht erlaubt.' );
		}
		check_admin_referer( 'nc_token' );
		$token = wp_generate_password( 40, false, false );
		update_option( 'nc_api_token_hash', hash( 'sha256', $token ), false );
		set_transient( 'nc_token_show', $token, 10 * MINUTE_IN_SECONDS );
		wp_safe_redirect( NC_Admin::url( 'app' ) );
		exit;
	}

	/* ---------- Endpoints ---------- */

	public static function status() {
		nocache_headers();
		return rest_ensure_response( [ 'paused' => NC_Hours::paused(), 'message' => NC_Hours::paused_msg() ] );
	}

	public static function set_status( WP_REST_Request $r ) {
		$p = (array) $r->get_json_params();
		update_option( 'nc_paused', empty( $p['paused'] ) ? 0 : 1 );
		return self::status();
	}

	public static function ping() {
		nocache_headers();
		return rest_ensure_response( [ 'ok' => true, 'time' => time(), 'version' => NC_VERSION, 'paused' => NC_Hours::paused() ] );
	}

	public static function orders( WP_REST_Request $r ) {
		nocache_headers();
		$limit   = min( 100, max( 1, absint( $r->get_param( 'limit' ) ?: 20 ) ) );
		$hours   = absint( $r->get_param( 'hours' ) );
		$hours   = $hours ? min( $hours, 720 ) : 72;
		$status  = array_values( array_intersect( array_map( 'trim', explode( ',', (string) ( $r->get_param( 'status' ) ?: 'processing' ) ) ), [ 'processing', 'on-hold', 'completed' ] ) ) ?: [ 'processing' ];
		$since   = absint( $r->get_param( 'since_id' ) );
		$unacked = in_array( (string) $r->get_param( 'unacked' ), [ '1', 'true', 'yes' ], true );

		$list = wc_get_orders( [
			'limit'        => 300,
			'orderby'      => 'date',
			'order'        => 'DESC',
			'status'       => $status,
			'date_created' => '>' . ( time() - $hours * HOUR_IN_SECONDS ),
			'meta_query'   => [ [ 'key' => '_nc_type', 'compare' => 'EXISTS' ] ],
		] );

		$out = [];
		foreach ( $list as $o ) {
			if ( $since && $o->get_id() <= $since ) {
				continue;
			}
			if ( $unacked && (string) $o->get_meta( '_nc_ack_at' ) !== '' ) {
				continue;
			}
			$out[ $o->get_id() ] = $o;
		}
		ksort( $out ); // oldest first
		$out = array_slice( $out, -$limit, null, true );

		return rest_ensure_response( [
			'orders'        => array_values( array_map( [ __CLASS__, 'format' ], $out ) ),
			'paused'        => NC_Hours::paused(),
			'server_time'   => time(),
			'next_since_id' => $out ? max( array_keys( $out ) ) : $since,
		] );
	}

	private static function load( WP_REST_Request $r ) {
		$o = wc_get_order( absint( $r['id'] ) );
		if ( ! $o instanceof WC_Order || (string) $o->get_meta( '_nc_type' ) === '' ) {
			return new WP_Error( 'nc_missing', 'Bestellung nicht gefunden.', [ 'status' => 404 ] );
		}
		return $o;
	}

	public static function one( WP_REST_Request $r ) {
		$o = self::load( $r );
		return is_wp_error( $o ) ? $o : rest_ensure_response( self::format( $o ) );
	}

	public static function ack( WP_REST_Request $r ) {
		$o = self::load( $r );
		if ( is_wp_error( $o ) ) {
			return $o;
		}
		if ( (string) $o->get_meta( '_nc_ack_at' ) === '' ) {
			$o->update_meta_data( '_nc_ack_at', time() );
			$o->save();
			$o->add_order_note( 'An Kasse übermittelt.' );
		}
		return rest_ensure_response( [ 'ok' => true, 'id' => $o->get_id() ] );
	}

	/* ---------- Order format (ready to print) ---------- */

	private static function format( $o ) {
		$type = (string) $o->get_meta( '_nc_type' );
		$del  = $type === 'delivery';

		$items = [];
		foreach ( $o->get_items() as $it ) {
			$qty   = max( 1, (int) $it->get_quantity() );
			$gross = round( (float) $it->get_total() + (float) $it->get_total_tax(), 2 );
			$opts  = [];
			foreach ( (array) $it->get_meta( '_nc_options', true ) as $op ) {
				if ( is_array( $op ) ) {
					$opts[] = [ 'group' => (string) ( $op['g'] ?? '' ), 'name' => (string) ( $op['n'] ?? '' ), 'price' => (float) ( $op['p'] ?? 0 ) ];
				}
			}
			$items[] = [
				'qty'     => $qty,
				'name'    => $it->get_name(),
				'unit'    => round( $gross / $qty, 2 ),
				'total'   => $gross,
				'options' => $opts,
				'note'    => (string) $it->get_meta( '_nc_note', true ),
			];
		}

		$fees = [];
		foreach ( $o->get_fees() as $f ) {
			$fees[] = [ 'name' => $f->get_name(), 'total' => round( (float) $f->get_total() + (float) $f->get_total_tax(), 2 ) ];
		}

		$tax = [];
		foreach ( $o->get_items( 'tax' ) as $t ) {
			$tax[] = [
				'rate'   => (float) WC_Tax::get_rate_percent_value( $t->get_rate_id() ),
				'label'  => $t->get_label(),
				'amount' => round( (float) $t->get_tax_total() + (float) $t->get_shipping_tax_total(), 2 ),
			];
		}

		$street = $o->get_shipping_address_1() ?: $o->get_billing_address_1();
		$zip    = $o->get_shipping_postcode() ?: $o->get_billing_postcode();
		$city   = $o->get_shipping_city() ?: $o->get_billing_city();
		$text   = $del ? trim( $street . ', ' . trim( $zip . ' ' . $city ), ' ,' ) : '';
		$dt     = $o->get_date_created();
		$ack    = (string) $o->get_meta( '_nc_ack_at' );

		return [
			'id'           => $o->get_id(),
			'number'       => (string) $o->get_order_number(),
			'status'       => $o->get_status(),
			'created'      => $dt ? $dt->date( 'c' ) : '',
			'created_ts'   => $dt ? $dt->getTimestamp() : 0,
			'type'         => $type,
			'when'         => (string) $o->get_meta( '_nc_time' ),
			'when_ts'      => (int) $o->get_meta( '_nc_time_ts' ),
			'payment'      => (string) $o->get_payment_method_title(),
			'payment_code' => (string) $o->get_meta( '_nc_pay' ),
			'customer'     => [
				'name'  => trim( $o->get_billing_first_name() . ' ' . $o->get_billing_last_name() ),
				'phone' => (string) $o->get_billing_phone(),
				'email' => (string) $o->get_billing_email(),
			],
			'address'      => [
				'street' => $del ? (string) $street : '',
				'zip'    => $del ? (string) $zip : '',
				'city'   => $del ? (string) $city : '',
				'text'   => $text,
				'maps'   => $text ? 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode( $text ) : '',
			],
			'note'         => (string) $o->get_customer_note(),
			'items'        => $items,
			'fees'         => $fees,
			'tax'          => $tax,
			'total'        => round( (float) $o->get_total(), 2 ),
			'total_tax'    => round( (float) $o->get_total_tax(), 2 ),
			'currency'     => $o->get_currency(),
			'acked'        => $ack !== '',
		];
	}
}
