<?php
defined( 'ABSPATH' ) || exit;

/** Admin start page "Bestellungen": KPIs, best seller, chart and nicely rendered order cards. */
class NC_Dashboard {

	private static function url( $args = [] ) {
		return add_query_arg( array_merge( [ 'page' => 'nc-dashboard' ], $args ), admin_url( 'admin.php' ) );
	}

	/** $value and $sub are trusted HTML built from escaped parts. */
	private static function kpi( $label, $value, $sub = '', $class = '' ) {
		printf(
			'<div class="nc-kpi %s"><span class="l">%s</span><strong>%s</strong>%s</div>',
			esc_attr( $class ), esc_html( $label ), $value, $sub ? '<span class="s">' . $sub . '</span>' : ''
		);
	}

	private static function styles() {
		?>
		<style>
			.nc-dash{max-width:1280px}
			.nc-dash .nc-range{display:flex;gap:6px;margin:14px 0}
			.nc-dash .nc-range a{padding:7px 16px;border-radius:999px;background:#fff;border:1px solid #dcdcde;color:#1d2327;text-decoration:none;font-weight:600}
			.nc-dash .nc-range a.on{background:#1d2327;color:#fff;border-color:#1d2327}
			.nc-dash .nc-kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:14px}
			.nc-dash .nc-kpi{padding:16px 18px;background:#fff;border:1px solid #dcdcde;border-radius:12px;display:flex;flex-direction:column;gap:4px}
			.nc-dash .nc-kpi .l{color:#646970;font-size:13px}
			.nc-dash .nc-kpi strong{font-size:28px;line-height:1.15}
			.nc-dash .nc-kpi .s{color:#646970;font-size:13px}
			.nc-dash .nc-kpi.hero{background:#1d2327;border-color:#1d2327;color:#fff}
			.nc-dash .nc-kpi.hero .l,.nc-dash .nc-kpi.hero .s{color:#c3c4c7}
			.nc-dash .nc-split{display:flex;height:8px;margin-top:8px;border-radius:4px;overflow:hidden;background:#f0f0f1}
			.nc-dash .nc-split i{display:block;height:100%}
			.nc-dash .nc-panels{display:grid;grid-template-columns:minmax(0,2fr) minmax(0,1fr);gap:14px;margin:14px 0 6px}
			@media(max-width:900px){.nc-dash .nc-panels{grid-template-columns:1fr}}
			.nc-dash .nc-panel{padding:16px 18px;background:#fff;border:1px solid #dcdcde;border-radius:12px}
			.nc-dash .nc-panel h2{margin:0 0 12px;font-size:15px}
			.nc-dash .nc-bars{display:flex;align-items:flex-end;gap:4px;height:150px}
			.nc-dash .bar{flex:1;min-width:0;height:100%;display:flex;flex-direction:column;justify-content:flex-end;align-items:center;gap:4px}
			.nc-dash .bar i{display:block;width:100%;background:#2271b1;border-radius:3px 3px 0 0}
			.nc-dash .bar span{font-size:11px;color:#646970;height:14px;white-space:nowrap}
			.nc-dash .nc-top{margin:0;padding:0;list-style:none}
			.nc-dash .nc-top li{margin:0 0 10px}
			.nc-dash .nc-top .row{display:flex;justify-content:space-between;gap:10px;font-size:14px}
			.nc-dash .nc-top .meter{height:6px;margin-top:4px;border-radius:3px;background:#f0f0f1}
			.nc-dash .nc-top .meter i{display:block;height:100%;border-radius:3px;background:#2271b1}
			.nc-dash h2.nc-h{margin:26px 0 12px;font-size:18px}
			.nc-dash .nc-orders{display:grid;grid-template-columns:repeat(auto-fill,minmax(360px,1fr));gap:14px}
			.nc-dash .nc-ord{background:#fff;border:1px solid #dcdcde;border-radius:12px;overflow:hidden;display:flex;flex-direction:column}
			.nc-dash .nc-ord-h{display:flex;align-items:center;gap:8px;flex-wrap:wrap;padding:12px 16px;background:#f6f7f7;border-bottom:1px solid #dcdcde}
			.nc-dash .nc-ord-h .sum{margin-left:auto;font-weight:700;font-size:16px}
			.nc-dash .badge{padding:3px 10px;border-radius:999px;font-size:12px;font-weight:700}
			.nc-dash .badge.delivery{background:#dbeafe;color:#1e40af}
			.nc-dash .badge.pickup{background:#dcfce7;color:#166534}
			.nc-dash .badge.other{background:#e5e7eb;color:#374151}
			.nc-dash .badge.st-processing{background:#fef3c7;color:#92400e}
			.nc-dash .badge.st-completed{background:#dcfce7;color:#166534}
			.nc-dash .badge.st-on-hold{background:#e5e7eb;color:#374151}
			.nc-dash .nc-ord-b{padding:12px 16px;flex:1}
			.nc-dash .nc-items{margin:0 0 10px;padding:0;list-style:none}
			.nc-dash .nc-items li{display:flex;gap:8px;margin:0;padding:6px 0;border-bottom:1px dashed #e0e0e0}
			.nc-dash .nc-items .q{font-weight:700;min-width:26px}
			.nc-dash .nc-items .n{flex:1}
			.nc-dash .nc-items small{display:block;color:#646970}
			.nc-dash .nc-cust{font-size:13px;color:#3c434a;line-height:1.5}
			.nc-dash .nc-note{margin-top:8px;padding:8px 10px;border-radius:8px;background:#fff8e1;font-size:13px}
			.nc-dash .nc-ord-f{display:flex;justify-content:space-between;gap:8px;padding:10px 16px;border-top:1px solid #f0f0f1;font-size:12px;color:#646970}
			.nc-dash .nc-empty{padding:40px;text-align:center;background:#fff;border:1px dashed #c3c4c7;border-radius:12px;color:#646970}
		</style>
		<?php
	}

	private static function order_card( $o, $range ) {
		$type   = (string) $o->get_meta( '_nc_type' );
		$del    = $type === 'delivery';
		$cls    = isset( [ 'delivery' => 1, 'pickup' => 1 ][ $type ] ) ? $type : 'other';
		$label  = $del ? 'Lieferung' : ( $type === 'pickup' ? 'Abholung' : 'Bestellung' );
		$dt     = $o->get_date_created();
		$placed = $dt ? wp_date( $range === 'today' ? 'H:i' : 'd.m. H:i', $dt->getTimestamp() ) : '';
		$when   = (string) $o->get_meta( '_nc_time' );
		$status = $o->get_status();

		echo '<article class="nc-ord"><div class="nc-ord-h">';
		printf( '<strong>#%s</strong><span class="badge %s">%s</span><span class="badge st-%s">%s</span><span class="sum">%s</span>',
			esc_html( $o->get_order_number() ), esc_attr( $cls ), esc_html( $label ), esc_attr( $status ), esc_html( wc_get_order_status_name( $status ) ), wc_price( $o->get_total() ) );
		echo '</div><div class="nc-ord-b"><ul class="nc-items">';
		foreach ( $o->get_items() as $it ) {
			$sub = [];
			if ( (string) $it->get_meta( 'Optionen' ) !== '' ) {
				$sub[] = (string) $it->get_meta( 'Optionen' );
			}
			if ( (string) $it->get_meta( 'Anmerkung' ) !== '' ) {
				$sub[] = '„' . $it->get_meta( 'Anmerkung' ) . '“';
			}
			printf( '<li><span class="q">%d×</span><span class="n">%s%s</span><span>%s</span></li>',
				(int) $it->get_quantity(), esc_html( $it->get_name() ), $sub ? '<small>' . esc_html( implode( ' · ', $sub ) ) . '</small>' : '',
				wc_price( (float) $it->get_total() + (float) $it->get_total_tax() ) );
		}
		foreach ( $o->get_fees() as $f ) {
			printf( '<li><span class="q"></span><span class="n">%s</span><span>%s</span></li>', esc_html( $f->get_name() ), wc_price( (float) $f->get_total() + (float) $f->get_total_tax() ) );
		}
		echo '</ul><div class="nc-cust">';
		$name  = trim( $o->get_billing_first_name() . ' ' . $o->get_billing_last_name() );
		$lines = [ '<strong>' . esc_html( $name ?: 'Gast' ) . '</strong>' ];
		if ( $o->get_billing_phone() ) {
			$lines[] = esc_html( $o->get_billing_phone() );
		}
		if ( $del ) {
			$street = $o->get_shipping_address_1() ?: $o->get_billing_address_1();
			$zip    = $o->get_shipping_postcode() ?: $o->get_billing_postcode();
			$city   = $o->get_shipping_city() ?: $o->get_billing_city();
			$lines[] = esc_html( trim( $street . ', ' . trim( $zip . ' ' . $city ), ' ,' ) );
		}
		if ( $when !== '' ) {
			$lines[] = 'Wunschzeit: ' . esc_html( $when === 'Sofort' ? 'so schnell wie möglich' : $when . ' Uhr' );
		}
		echo implode( '<br>', $lines ) . '</div>';
		if ( $o->get_customer_note() ) {
			echo '<div class="nc-note"><strong>Anmerkung:</strong> ' . esc_html( $o->get_customer_note() ) . '</div>';
		}
		$ack = (int) $o->get_meta( '_nc_ack_at' );
		printf( '</div><div class="nc-ord-f"><span>%s · %s%s</span><a href="%s">Öffnen</a></div></article>',
			esc_html( $o->get_payment_method_title() ), esc_html( $placed ),
			esc_html( $ack ? ' · ✓ Kasse ' . wp_date( 'H:i', $ack ) : '' ), esc_url( $o->get_edit_order_url() ) );
	}

	public static function page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$range = sanitize_key( $_GET['range'] ?? 'today' );
		if ( ! in_array( $range, [ 'today', '7', '30', '90' ], true ) ) {
			$range = 'today';
		}
		$days  = $range === 'today' ? 1 : (int) $range;
		$end   = new DateTime( 'tomorrow', wp_timezone() );
		$start = ( clone $end )->modify( '-' . $days . ' days' );

		$orders = wc_get_orders( [
			'limit'        => 500,
			'orderby'      => 'date',
			'order'        => 'DESC',
			'status'       => [ 'processing', 'on-hold', 'completed' ],
			'date_created' => $start->getTimestamp() . '...' . ( $end->getTimestamp() - 1 ),
		] );

		$count   = count( $orders );
		$sum     = 0.0;
		$types   = [ 'delivery' => 0, 'pickup' => 0, 'other' => 0 ];
		$buckets = [];
		$prod    = [];
		foreach ( $orders as $o ) {
			$sum += (float) $o->get_total();
			$t    = (string) $o->get_meta( '_nc_type' );
			$types[ isset( $types[ $t ] ) ? $t : 'other' ]++;
			$dt = $o->get_date_created();
			if ( $dt ) {
				$k             = $range === 'today' ? wp_date( 'G', $dt->getTimestamp() ) : wp_date( 'Y-m-d', $dt->getTimestamp() );
				$buckets[ $k ] = ( $buckets[ $k ] ?? 0 ) + 1;
			}
			foreach ( $o->get_items() as $it ) {
				$n = $it->get_name();
				if ( ! isset( $prod[ $n ] ) ) {
					$prod[ $n ] = [ 'q' => 0, 'r' => 0.0 ];
				}
				$prod[ $n ]['q'] += (int) $it->get_quantity();
				$prod[ $n ]['r'] += (float) $it->get_total() + (float) $it->get_total_tax();
			}
		}
		uasort( $prod, function ( $a, $b ) {
			return $b['q'] <=> $a['q'];
		} );
		$top = array_slice( $prod, 0, 5, true );

		$bars = [];
		if ( $range === 'today' ) {
			for ( $h = 8; $h <= 23; $h++ ) {
				$bars[] = [ $buckets[ (string) $h ] ?? 0, sprintf( '%02d', $h ) ];
			}
		} else {
			$d = clone $start;
			for ( $i = 0; $i < $days; $i++ ) {
				$bars[] = [ $buckets[ $d->format( 'Y-m-d' ) ] ?? 0, $range === '7' ? wp_date( 'D', $d->getTimestamp() ) : $d->format( 'd.m.' ) ];
				$d->modify( '+1 day' );
			}
		}
		$max = max( 1, max( wp_list_pluck( $bars, 0 ) ) );

		$limit = max( 12, min( 200, absint( $_GET['limit'] ?? 12 ) ) );

		echo '<div class="wrap nc-dash">';
		self::styles();
		echo '<h1>Bestellungen</h1><div class="nc-range">';
		foreach ( [ 'today' => 'Heute', '7' => '7 Tage', '30' => '30 Tage', '90' => '90 Tage' ] as $k => $l ) {
			printf( '<a href="%s" class="%s">%s</a>', esc_url( self::url( [ 'range' => $k ] ) ), $range === $k ? 'on' : '', esc_html( $l ) );
		}
		echo '</div>';

		/* KPIs */
		$pd = $count ? round( $types['delivery'] / $count * 100 ) : 0;
		$pp = $count ? round( $types['pickup'] / $count * 100 ) : 0;
		echo '<div class="nc-kpis">';
		if ( $top ) {
			$name = key( $top );
			$best = current( $top );
			self::kpi( 'Stärkstes Gericht', esc_html( $name ), $best['q'] . '× verkauft · ' . wc_price( $best['r'] ), 'hero' );
		} else {
			self::kpi( 'Stärkstes Gericht', '–', 'Noch keine Verkäufe', 'hero' );
		}
		self::kpi( 'Bestellungen', (string) $count );
		self::kpi( 'Umsatz', wc_price( $sum ) );
		self::kpi( 'Ø Bestellwert', wc_price( $count ? $sum / $count : 0 ) );
		self::kpi( 'Lieferung / Abholung', (int) $types['delivery'] . ' / ' . (int) $types['pickup'],
			$pd . '% Lieferung · ' . $pp . '% Abholung<div class="nc-split"><i style="width:' . (int) $pd . '%;background:#2271b1"></i><i style="width:' . (int) $pp . '%;background:#00a32a"></i></div>' );
		echo '</div>';

		/* Chart + top dishes */
		echo '<div class="nc-panels"><div class="nc-panel"><h2>' . esc_html( $range === 'today' ? 'Bestellungen nach Uhrzeit' : 'Bestellungen pro Tag' ) . '</h2><div class="nc-bars">';
		$nb = count( $bars );
		foreach ( $bars as $i => $b ) {
			printf( '<div class="bar" title="%s: %d"><i style="height:%d%%"></i><span>%s</span></div>',
				esc_attr( $b[1] ), (int) $b[0], max( $b[0] ? 6 : 2, (int) round( $b[0] / $max * 100 ) ), ( $nb <= 16 || $i % 5 === 0 ) ? esc_html( $b[1] ) : '' );
		}
		echo '</div></div><div class="nc-panel"><h2>Top-Gerichte</h2>';
		if ( $top ) {
			echo '<ul class="nc-top">';
			$first = reset( $top );
			foreach ( $top as $n => $p ) {
				printf( '<li><div class="row"><span>%s</span><strong>%d×</strong></div><div class="meter"><i style="width:%d%%"></i></div></li>',
					esc_html( $n ), (int) $p['q'], (int) round( $p['q'] / max( 1, $first['q'] ) * 100 ) );
			}
			echo '</ul>';
		} else {
			echo '<p>Noch keine Daten.</p>';
		}
		echo '</div></div>';

		/* Orders */
		echo '<h2 class="nc-h">Bestellungen</h2>';
		if ( ! $orders ) {
			echo '<div class="nc-empty">Noch keine Bestellungen in diesem Zeitraum.</div>';
		} else {
			echo '<div class="nc-orders">';
			foreach ( array_slice( $orders, 0, $limit ) as $o ) {
				self::order_card( $o, $range );
			}
			echo '</div>';
			if ( $count > $limit ) {
				printf( '<p><a class="button" href="%s">Mehr anzeigen (%d weitere)</a></p>', esc_url( self::url( [ 'range' => $range, 'limit' => $limit + 12 ] ) ), $count - $limit );
			}
		}
		if ( $range === 'today' ) {
			echo '<script>setTimeout(function(){ location.reload(); }, 60000);</script>';
		}
		echo '</div>';
	}
}
