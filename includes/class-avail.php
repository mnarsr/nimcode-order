<?php
defined( 'ABSPATH' ) || exit;

/**
 * Time-limited products (e.g. "Mittagsangebot", Mo-Fr 12:00-14:00).
 * Product meta "_nc_avail" = [ 'days' => [1..7], 'from' => 'HH:MM', 'to' => 'HH:MM' ].
 * No meta = always available. Enforced on the server when adding to cart and at checkout.
 */
class NC_Avail {

	const DAYS = [ 1 => 'Mo', 2 => 'Di', 3 => 'Mi', 4 => 'Do', 5 => 'Fr', 6 => 'Sa', 7 => 'So' ];

	public static function init() {
		add_action( 'woocommerce_product_options_general_product_data', [ __CLASS__, 'field' ] );
		add_action( 'woocommerce_process_product_meta', [ __CLASS__, 'save' ] );
	}

	public static function get( $pid ) {
		$a = get_post_meta( $pid, '_nc_avail', true );
		if ( ! is_array( $a ) || empty( $a['days'] ) || empty( $a['from'] ) || empty( $a['to'] ) ) {
			return null;
		}
		return $a;
	}

	public static function has( $pid ) {
		return (bool) self::get( $pid );
	}

	private static function mins( $hhmm ) {
		return preg_match( '/^(\d{1,2}):(\d{2})$/', (string) $hhmm, $m ) ? $m[1] * 60 + $m[2] : 0;
	}

	public static function is_now( $pid ) {
		$a = self::get( $pid );
		if ( ! $a ) {
			return true;
		}
		$now  = new DateTime( 'now', wp_timezone() );
		$dow  = (int) $now->format( 'N' );
		$nowm = (int) $now->format( 'G' ) * 60 + (int) $now->format( 'i' );
		return in_array( $dow, array_map( 'intval', $a['days'] ), true )
			&& $nowm >= self::mins( $a['from'] ) && $nowm < self::mins( $a['to'] );
	}

	/** e.g. "Mo-Fr 12:00-14:00 Uhr" */
	public static function text( $pid ) {
		$a = self::get( $pid );
		if ( ! $a ) {
			return '';
		}
		$d = array_values( array_unique( array_map( 'intval', $a['days'] ) ) );
		sort( $d );
		$parts = [];
		$i     = 0;
		while ( $i < count( $d ) ) {
			$j = $i;
			while ( $j + 1 < count( $d ) && $d[ $j + 1 ] === $d[ $j ] + 1 ) {
				$j++;
			}
			$parts[] = $j - $i >= 2 ? self::DAYS[ $d[ $i ] ] . '-' . self::DAYS[ $d[ $j ] ] : implode( ', ', array_map( function ( $x ) {
				return self::DAYS[ $x ];
			}, array_slice( $d, $i, $j - $i + 1 ) ) );
			$i = $j + 1;
		}
		return 'Nur ' . implode( ', ', $parts ) . ' ' . $a['from'] . '-' . $a['to'] . ' Uhr';
	}

	/* ---------- Product editor ---------- */

	public static function field() {
		global $post;
		$a    = self::get( $post->ID ) ?: [ 'days' => [], 'from' => '12:00', 'to' => '14:00' ];
		$days = array_map( 'intval', $a['days'] );
		echo '<div class="options_group show_if_simple show_if_variable">';
		echo '<p class="form-field"><label>Nur zu bestimmten Zeiten</label><span class="description" style="margin:0 0 6px;display:block">Z. B. Mittagsangebot: Mo-Fr, 12:00 bis 14:00. Keine Tage ankreuzen = immer bestellbar.</span></p>';
		echo '<p class="form-field"><label>Tage</label><span style="display:inline-flex;flex-wrap:wrap;gap:8px 18px;align-items:center">';
		foreach ( self::DAYS as $n => $l ) {
			printf( '<label style="float:none;width:auto;margin:0;padding:0;display:inline-flex;align-items:center;gap:6px"><input type="checkbox" name="nc_avail_days[]" value="%d"%s style="float:none;margin:0;width:auto"> %s</label>', $n, checked( in_array( $n, $days, true ), true, false ), esc_html( $l ) );
		}
		echo '</span></p>';
		printf( '<p class="form-field"><label for="nc_avail_from">Von / bis</label><input type="time" id="nc_avail_from" name="nc_avail_from" value="%s" style="width:110px"> &nbsp;–&nbsp; <input type="time" name="nc_avail_to" value="%s" style="width:110px"></p>', esc_attr( $a['from'] ), esc_attr( $a['to'] ) );
		echo '</div>';
	}

	public static function save( $post_id ) {
		$days = isset( $_POST['nc_avail_days'] ) ? array_values( array_filter( array_map( 'absint', (array) $_POST['nc_avail_days'] ), function ( $d ) {
			return $d >= 1 && $d <= 7;
		} ) ) : [];
		$from = sanitize_text_field( wp_unslash( $_POST['nc_avail_from'] ?? '' ) );
		$to   = sanitize_text_field( wp_unslash( $_POST['nc_avail_to'] ?? '' ) );
		if ( ! $days || ! preg_match( '/^\d{1,2}:\d{2}$/', $from ) || ! preg_match( '/^\d{1,2}:\d{2}$/', $to ) ) {
			delete_post_meta( $post_id, '_nc_avail' );
			return;
		}
		update_post_meta( $post_id, '_nc_avail', [ 'days' => $days, 'from' => $from, 'to' => $to ] );
	}
}
