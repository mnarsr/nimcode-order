<?php
defined( 'ABSPATH' ) || exit;

/**
 * Kassen-Überwachung: warnt den Inhaber per E-Mail, wenn die Kassen-App nicht verbunden ist
 * (nur zu den Öffnungszeiten) oder eine Bestellung nicht bei der Kasse angekommen ist.
 * Außerdem: Lieferart/Wunschzeit/Zahlung in den WooCommerce-Bestell-E-Mails.
 *
 * Läuft über WP-Cron (jede Minute, sobald die Website aufgerufen wird). Für Zuverlässigkeit
 * sollte auf dem Server ein echter Cron-Job wp-cron.php aufrufen (siehe Einstellungen).
 */
class NC_Watch {

	const HOOK = 'nc_watch';

	public static function init() {
		add_filter( 'cron_schedules', [ __CLASS__, 'schedules' ] );
		add_action( 'init', [ __CLASS__, 'schedule' ] );
		add_action( self::HOOK, [ __CLASS__, 'run' ] );
		add_action( 'admin_post_nc_watch_test', [ __CLASS__, 'test_mail' ] );
		add_action( 'woocommerce_email_order_meta', [ __CLASS__, 'email_meta' ], 10, 3 );
	}

	public static function schedules( $s ) {
		$s['nc_minute'] = [ 'interval' => 60, 'display' => 'Jede Minute' ];
		return $s;
	}

	public static function schedule() {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + 60, 'nc_minute', self::HOOK );
		}
	}

	/* ---------- Settings ---------- */

	public static function enabled() {
		return (bool) get_option( 'nc_watch_on', 1 );
	}

	public static function email() {
		$e = trim( (string) get_option( 'nc_watch_email', '' ) );
		return is_email( $e ) ? $e : get_option( 'admin_email' );
	}

	public static function minutes() {
		return max( 2, min( 60, (int) get_option( 'nc_watch_min', 5 ) ) );
	}

	/* ---------- State ---------- */

	private static function uses_app() {
		return (string) get_option( 'nc_api_token_hash', '' ) !== '';
	}

	/** Seconds since the app last contacted the site (null = never). */
	public static function ago() {
		$seen = (int) get_option( 'nc_api_last_seen', 0 );
		return $seen ? time() - $seen : null;
	}

	public static function offline() {
		$a = self::ago();
		return $a === null || $a > self::minutes() * 60;
	}

	/** True during opening hours (plus a grace period before opening, so the app gets started in time). */
	public static function in_hours( $grace = 15 ) {
		$now  = new DateTime( 'now', wp_timezone() );
		$dow  = (int) $now->format( 'N' );
		$nowm = (int) $now->format( 'G' ) * 60 + (int) $now->format( 'i' );
		$h    = NC_Hours::hours();
		foreach ( $h[ $dow ] ?? [] as $r ) {
			if ( $nowm >= $r[0] - $grace && $nowm <= $r[1] ) {
				return true;
			}
		}
		return false;
	}

	/* ---------- Cron job ---------- */

	public static function run() {
		if ( ! self::enabled() || ! self::uses_app() || ! function_exists( 'wc_get_orders' ) ) {
			return;
		}
		self::check_orders();
		self::check_connection();
	}

	/** Orders that the app has not confirmed within the time limit. */
	private static function check_orders() {
		$limit = self::minutes() * 60;
		$list  = wc_get_orders( [
			'limit'        => 50,
			'status'       => [ 'processing' ],
			'date_created' => '>' . ( time() - 6 * HOUR_IN_SECONDS ),
			'meta_query'   => [ [ 'key' => '_nc_type', 'compare' => 'EXISTS' ] ],
		] );
		$late = [];
		foreach ( $list as $o ) {
			if ( (string) $o->get_meta( '_nc_ack_at' ) !== '' || (string) $o->get_meta( '_nc_watch_alert' ) !== '' ) {
				continue;
			}
			$created = $o->get_date_created();
			if ( $created && time() - $created->getTimestamp() >= $limit ) {
				$late[] = $o;
			}
		}
		if ( ! $late ) {
			return;
		}
		$lines = [];
		foreach ( $late as $o ) {
			$lines[] = sprintf( '#%s, %s, %s, %s', $o->get_order_number(), wp_date( 'H:i', $o->get_date_created()->getTimestamp() ), $o->get_formatted_billing_full_name(), wp_strip_all_tags( $o->get_formatted_order_total() ) );
			$o->update_meta_data( '_nc_watch_alert', time() );
			$o->save();
		}
		$body = "Achtung: Diese Bestellung(en) sind bei der Kasse noch nicht angekommen:\n\n" . implode( "\n", $lines )
			. "\n\nBitte die Kassen-App prüfen (läuft sie? Internet vorhanden?) oder die Bestellungen im WordPress-Dashboard ansehen:\n"
			. NC_Admin::url( 'start' ) . "\n" . admin_url( 'admin.php?page=nc-dashboard' );
		self::mail( 'Bestellung nicht an der Kasse angekommen', $body );
	}

	/** App offline during opening hours: one mail per outage, repeated hourly. */
	private static function check_connection() {
		$state = get_option( 'nc_watch_state', [] );
		$down  = self::offline() && ! get_option( 'nc_paused', 0 ) && self::in_hours();

		if ( $down ) {
			$last = (int) ( $state['last_mail'] ?? 0 );
			if ( ! $state || time() - $last >= HOUR_IN_SECONDS ) {
				$ago  = self::ago();
				$seen = $ago === null ? 'noch nie' : wp_date( 'H:i', time() - $ago ) . ' Uhr';
				$body = "Die Kassen-App ist nicht mit der Website verbunden.\n\nLetzter Kontakt: {$seen}\n\n"
					. "Bitte prüfen: Ist das Programm gestartet? Hat der Kassen-PC Internet?\n"
					. "Solange die Kasse offline ist, kommen Bestellungen nicht an und es gibt keinen Alarm. "
					. "Notfalls Bestellungen unter 'Nimcode Order > Einstellungen > Bestellung' pausieren.";
				self::mail( 'Kassen-App nicht verbunden', $body );
				update_option( 'nc_watch_state', [ 'since' => $state['since'] ?? time(), 'last_mail' => time() ], false );
			}
			return;
		}

		if ( $state ) {
			// connection is back (or opening hours are over): clean up; mail only if it is back during opening hours
			if ( ! self::offline() && self::in_hours() ) {
				self::mail( 'Kassen-App wieder verbunden', 'Die Kassen-App ist wieder mit der Website verbunden (seit ' . wp_date( 'H:i' ) . ' Uhr). Alles in Ordnung.' );
			}
			delete_option( 'nc_watch_state' );
		}
	}

	private static function mail( $subject, $body ) {
		$name = get_bloginfo( 'name' );
		return wp_mail( self::email(), '[' . $name . '] ' . $subject, $body );
	}

	/* ---------- Test mail (checks that e-mail delivery works, e.g. WP Mail SMTP) ---------- */

	public static function test_mail() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'Nicht erlaubt.' );
		}
		check_admin_referer( 'nc_watch_test' );
		$ok = self::mail( 'Test-E-Mail', "Diese Test-E-Mail kommt von Nimcode Order.\nWenn du sie liest, funktioniert der E-Mail-Versand." );
		wp_safe_redirect( NC_Admin::url( 'order', [ 'mailtest' => $ok ? 'ok' : 'fail' ] ) );
		exit;
	}

	/* ---------- Dashboard status line ---------- */

	public static function banner() {
		if ( ! self::uses_app() ) {
			return;
		}
		$ago = self::ago();
		if ( ! self::offline() ) {
			echo '<div class="notice notice-success inline" style="margin:10px 0"><p><strong>Kasse verbunden</strong> (letzter Kontakt vor ' . (int) $ago . ' Sek.)</p></div>';
			return;
		}
		$txt = $ago === null ? 'noch nie verbunden' : 'letzter Kontakt vor ' . human_time_diff( time() - $ago ) . ' (' . wp_date( 'H:i', time() - $ago ) . ' Uhr)';
		echo '<div class="notice notice-error inline" style="margin:10px 0"><p><strong>Kasse nicht verbunden</strong>, ' . esc_html( $txt ) . '. Läuft die Kassen-App?</p></div>';
	}

	/* ---------- WooCommerce e-mails: Lieferart, Zeit, Zahlung ---------- */

	public static function email_meta( $order, $sent_to_admin, $plain_text ) {
		if ( ! $order instanceof WC_Order || (string) $order->get_meta( '_nc_type' ) === '' ) {
			return;
		}
		$type = $order->get_meta( '_nc_type' ) === 'delivery' ? 'Lieferung' : 'Abholung';
		$time = (string) $order->get_meta( '_nc_time' );
		$when = $time === 'Sofort' ? 'so schnell wie möglich' : ( $time !== '' ? $time . ' Uhr' : '' );
		$pay  = (string) $order->get_payment_method_title();
		if ( $plain_text ) {
			echo "\n" . $type . ( $when ? ': ' . $when : '' ) . ( $pay ? "\nZahlung: " . $pay : '' ) . "\n";
			return;
		}
		echo '<p style="margin:0 0 16px"><strong>' . esc_html( $type ) . '</strong>' . ( $when ? ': ' . esc_html( $when ) : '' ) . ( $pay ? '<br>Zahlung: ' . esc_html( $pay ) : '' ) . '</p>';
	}
}
