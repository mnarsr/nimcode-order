<?php
defined( 'ABSPATH' ) || exit;

/**
 * Opening hours + time slots.
 * Setting "nc_hours", one line per day(s):  Mo-Fr: 11:00-14:30, 17:00-22:00
 */
class NC_Hours {

	public static function hours() {
		$days = [ 'Mo' => 1, 'Di' => 2, 'Mi' => 3, 'Do' => 4, 'Fr' => 5, 'Sa' => 6, 'So' => 7 ];
		$out  = [];
		foreach ( preg_split( '/\R/', (string) get_option( 'nc_hours', '' ) ) as $line ) {
			$line = trim( $line );
			if ( $line === '' || $line[0] === '#' || ! preg_match( '/^([^:]+):\s*(.+)$/u', $line, $m ) ) {
				continue;
			}
			$set = [];
			foreach ( explode( ',', $m[1] ) as $tok ) {
				$tok = trim( $tok );
				if ( preg_match( '/^([A-Za-z]{2})\s*-\s*([A-Za-z]{2})$/', $tok, $r ) && isset( $days[ $r[1] ], $days[ $r[2] ] ) ) {
					$d = $days[ $r[1] ];
					$b = $days[ $r[2] ];
					while ( true ) {
						$set[] = $d;
						if ( $d === $b ) {
							break;
						}
						$d = $d % 7 + 1;
					}
				} elseif ( isset( $days[ $tok ] ) ) {
					$set[] = $days[ $tok ];
				}
			}
			$ranges = [];
			foreach ( explode( ',', $m[2] ) as $t ) {
				if ( preg_match( '/^\s*(\d{1,2}):(\d{2})\s*-\s*(\d{1,2}):(\d{2})\s*$/', $t, $x ) ) {
					$ranges[] = [ $x[1] * 60 + $x[2], $x[3] * 60 + $x[4] ];
				}
			}
			foreach ( $set as $d ) {
				$out[ $d ] = array_merge( $out[ $d ] ?? [], $ranges );
			}
		}
		return $out;
	}

	const DEFAULT_PAUSE_MSG = 'Aktuell nehmen wir keine Online-Bestellungen an. Bitte versuche es später noch einmal oder ruf uns an.';

	public static function paused() {
		return (bool) get_option( 'nc_paused', 0 );
	}

	public static function paused_msg() {
		$m = trim( (string) get_option( 'nc_paused_msg', '' ) );
		return $m !== '' ? $m : self::DEFAULT_PAUSE_MSG;
	}

	public static function prep( $type ) {
		return $type === 'delivery' ? (int) get_option( 'nc_prep_delivery', 45 ) : (int) get_option( 'nc_prep_pickup', 20 );
	}

	/** Today's available times for "delivery" or "pickup". */
	public static function slots( $type ) {
		$now  = new DateTime( 'now', wp_timezone() );
		$dow  = (int) $now->format( 'N' );
		$nowm = (int) $now->format( 'G' ) * 60 + (int) $now->format( 'i' );
		$prep = self::prep( $type );
		if ( get_option( 'nc_paused' ) ) {
			return [ 'open_now' => false, 'slots' => [], 'prep' => $prep ];
		}
		$step = max( 5, (int) get_option( 'nc_slot', 15 ) );
		$h    = self::hours();

		$open_now = false;
		$slots    = [];
		foreach ( $h[ $dow ] ?? [] as $r ) {
			list( $s, $e ) = $r;
			if ( $nowm >= $s && $nowm + $prep <= $e ) {
				$open_now = true;
			}
			$first = max( $s, (int) ( ceil( ( $nowm + $prep ) / $step ) * $step ) );
			for ( $t = $first; $t <= $e && $t < 1440; $t += $step ) {
				$slots[] = sprintf( '%02d:%02d', intdiv( $t, 60 ), $t % 60 );
			}
		}
		$slots = array_values( array_unique( $slots ) );
		sort( $slots );
		return [ 'open_now' => $open_now, 'slots' => $slots, 'prep' => $prep ];
	}

	public static function timestamp( $hhmm ) {
		$d = new DateTime( 'today ' . $hhmm, wp_timezone() );
		return $d->getTimestamp();
	}
}
