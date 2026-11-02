<?php
defined( 'ABSPATH' ) || exit;

/**
 * Delivery areas by postcode (PLZ) and place (Ort).
 * Setting "nc_zones", one line per row:   PLZ;Ort;Mindestbestellwert;Liefergebühr;Gratis ab
 *   24576;Hitzhusen;10,00;2,50;30      <- one row per place with this PLZ
 *   22761;;15,00;2,50;30               <- empty place = the whole PLZ
 *   227*;;20,00;3,50;0                 <- wildcard: all PLZ starting with 227
 * Old lines with 4 fields (PLZ;Min;Gebühr;Gratis) are still understood.
 */
class NC_Delivery {

	public static function init() {
		// Settings UI lives in NC_Admin.
	}

	private static function num( $v ) {
		return (float) str_replace( ',', '.', (string) $v );
	}

	/** All rows: [ plz, city, min, fee, free_from ]. */
	public static function rows() {
		$out = [];
		foreach ( preg_split( '/\R/', (string) get_option( 'nc_zones', '' ) ) as $line ) {
			$line = trim( $line );
			if ( $line === '' || $line[0] === '#' ) {
				continue;
			}
			$p = array_map( 'trim', explode( ';', $line ) );
			if ( ! preg_match( '/^\d{1,5}\*?$/', $p[0] ) ) {
				continue;
			}
			if ( count( $p ) >= 5 ) {
				list( , $city, $min, $fee, $free ) = $p;
			} else {
				$city = '';
				$min  = $p[1] ?? 0;
				$fee  = $p[2] ?? 0;
				$free = $p[3] ?? 0;
			}
			$out[] = [ 'plz' => $p[0], 'city' => $city, 'min' => self::num( $min ), 'fee' => self::num( $fee ), 'free_from' => self::num( $free ) ];
		}
		return $out;
	}

	public static function zones() {
		return self::rows();
	}

	/**
	 * Finds the delivery row for a PLZ (and place).
	 * Returns [ zone => row|null, cities => [..], city => string, need_city => bool, known => bool ]
	 *  - known:     we deliver to this PLZ at all
	 *  - cities:    named places for this PLZ (for the dropdown)
	 *  - need_city: several places and none chosen yet
	 *  - city:      the place (chosen, or filled in automatically if there is only one)
	 */
	public static function resolve( $plz, $city = '' ) {
		$plz  = preg_replace( '/\D/', '', (string) $plz );
		$city = trim( (string) $city );
		$rows = self::rows();

		$group = [];
		foreach ( $rows as $r ) {
			if ( $r['plz'] === $plz ) {
				$group[] = $r;
			}
		}
		if ( ! $group ) { // wildcard: the longest matching prefix wins
			$best = '';
			foreach ( $rows as $r ) {
				if ( substr( $r['plz'], -1 ) !== '*' ) {
					continue;
				}
				$prefix = substr( $r['plz'], 0, -1 );
				if ( $prefix !== '' && strpos( $plz, $prefix ) === 0 && strlen( $prefix ) > strlen( $best ) ) {
					$best = $prefix;
				}
			}
			if ( $best !== '' ) {
				foreach ( $rows as $r ) {
					if ( $r['plz'] === $best . '*' ) {
						$group[] = $r;
					}
				}
			}
		}

		$out = [ 'zone' => null, 'cities' => [], 'city' => '', 'need_city' => false, 'known' => (bool) $group ];
		if ( ! $group ) {
			return $out;
		}

		$generic = null;
		$named   = [];
		foreach ( $group as $r ) {
			if ( $r['city'] === '' ) {
				$generic = $generic ?: $r;
			} else {
				$named[] = $r;
			}
		}
		$out['cities'] = array_values( array_unique( wp_list_pluck( $named, 'city' ) ) );

		if ( $city !== '' ) {
			foreach ( $named as $r ) {
				if ( strcasecmp( $r['city'], $city ) === 0 ) {
					$out['zone'] = $r;
					$out['city'] = $r['city'];
					return $out;
				}
			}
		}
		if ( $generic && ! $named ) {          // whole PLZ, the customer types the place
			$out['zone'] = $generic;
			return $out;
		}
		if ( count( $named ) === 1 && ! $generic ) { // only one place: fill it in automatically
			$out['zone'] = $named[0];
			$out['city'] = $named[0]['city'];
			return $out;
		}
		if ( $generic ) {                      // places + "other places": fall back to the general row
			$out['zone'] = $generic;
			return $out;
		}
		$out['need_city'] = true;              // several places, none chosen
		return $out;
	}

	public static function get_zone( $plz, $city = '' ) {
		return self::resolve( $plz, $city )['zone'];
	}

	public static function fee( $zone, $subtotal ) {
		return ( $zone['free_from'] > 0 && $subtotal >= $zone['free_from'] ) ? 0.0 : (float) $zone['fee'];
	}
}
