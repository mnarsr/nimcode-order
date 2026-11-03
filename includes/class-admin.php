<?php
defined( 'ABSPATH' ) || exit;

/** Settings page with tabs: Übersicht, Lieferung, Öffnungszeiten, Bestellung, Darstellung. */
class NC_Admin {

	const PAGE = 'nc-settings';
	const TABS = [
		'start'    => 'Übersicht',
		'menu'     => 'Speisekarte',
		'delivery' => 'Lieferung',
		'hours'    => 'Öffnungszeiten',
		'order'    => 'Bestellung',
		'app'      => 'Windows-App',
		'design'   => 'Darstellung',
		'legal'    => 'Rechtstexte',
		'demo'     => 'Demo-Daten',
	];
	const DAYS = [ 1 => 'Montag', 2 => 'Dienstag', 3 => 'Mittwoch', 4 => 'Donnerstag', 5 => 'Freitag', 6 => 'Samstag', 7 => 'Sonntag' ];
	const KEYS = [ 1 => 'Mo', 2 => 'Di', 3 => 'Mi', 4 => 'Do', 5 => 'Fr', 6 => 'Sa', 7 => 'So' ];

	public static function init() {
		add_action( 'admin_menu', [ __CLASS__, 'menu' ], 5 );
		add_action( 'admin_menu', [ __CLASS__, 'menu_late' ], 20 );
		add_action( 'admin_post_nc_save', [ __CLASS__, 'save' ] );
		add_action( 'admin_post_nc_create_page', [ __CLASS__, 'create_page' ] );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue' ] );
	}

	public static function enqueue() {
		if ( ( $_GET['page'] ?? '' ) === self::PAGE ) {
			wp_enqueue_script( 'jquery-ui-sortable' );
		}
	}

	public static function accent() {
		$c = sanitize_hex_color( (string) get_option( 'nc_color', '#d62828' ) );
		return $c ?: '#d62828';
	}

	/** Menu width on large screens, in % of the screen (50-100). */
	public static function width() {
		return max( 50, min( 100, (int) get_option( 'nc_width', 80 ) ) );
	}

	public static function url( $tab = 'start', $extra = [] ) {
		return add_query_arg( array_merge( [ 'page' => self::PAGE, 'tab' => $tab ], $extra ), admin_url( 'admin.php' ) );
	}

	/** Top-level menu "Nimcode Order": opens the order dashboard. */
	public static function menu() {
		add_menu_page( 'Bestellungen', 'Nimcode Order', 'manage_woocommerce', 'nc-dashboard', [ 'NC_Dashboard', 'page' ], 'dashicons-food', 56 );
		add_submenu_page( 'nc-dashboard', 'Bestellungen', 'Bestellungen', 'manage_woocommerce', 'nc-dashboard', [ 'NC_Dashboard', 'page' ] );
	}

	/** After the "Optionsgruppen" list was added by WordPress: add settings, drop "Neue Optionsgruppe". */
	public static function menu_late() {
		add_submenu_page( 'nc-dashboard', 'Einstellungen', 'Einstellungen', 'manage_woocommerce', self::PAGE, [ __CLASS__, 'page' ] );
		remove_submenu_page( 'nc-dashboard', 'post-new.php?post_type=' . NC_Options::CPT );
	}

	/* ---------- Saving ---------- */

	private static function money_in( $v ) {
		return number_format( max( 0, (float) str_replace( ',', '.', (string) $v ) ), 2, ',', '' );
	}

	public static function save() {
		$tab = sanitize_key( $_POST['tab'] ?? '' );
		if ( ! current_user_can( 'manage_woocommerce' ) || ! isset( self::TABS[ $tab ] ) ) {
			wp_die( 'Nicht erlaubt.' );
		}
		check_admin_referer( 'nc_save_' . $tab );
		$p = wp_unslash( $_POST );

		if ( $tab === 'delivery' ) {
			$lines = [];
			$seen = [];
			foreach ( (array) ( $p['zone'] ?? [] ) as $z ) {
				$plz = preg_replace( '/[^0-9*]/', '', (string) ( $z['plz'] ?? '' ) );
				if ( ! preg_match( '/^\d{1,5}\*?$/', $plz ) ) {
					continue;
				}
				$city = trim( str_replace( [ ';', "\n", "\r" ], ' ', sanitize_text_field( (string) ( $z['city'] ?? '' ) ) ) );
				$key  = $plz . '|' . mb_strtolower( $city );
				if ( isset( $seen[ $key ] ) ) {
					continue;
				}
				$seen[ $key ] = true;
				$lines[] = implode( ';', [ $plz, $city, self::money_in( $z['min'] ?? 0 ), self::money_in( $z['fee'] ?? 0 ), self::money_in( $z['free'] ?? 0 ) ] );
			}
			update_option( 'nc_zones', implode( "\n", $lines ) );
			update_option( 'nc_city', sanitize_text_field( $p['city'] ?? '' ) );
		} elseif ( $tab === 'hours' ) {
			$lines = [];
			foreach ( self::KEYS as $d => $key ) {
				$row = (array) ( $p['h'][ $d ] ?? [] );
				if ( empty( $row['open'] ) ) {
					continue;
				}
				$ranges = [];
				foreach ( [ [ 'a1', 'b1' ], [ 'a2', 'b2' ] ] as $pair ) {
					$from = (string) ( $row[ $pair[0] ] ?? '' );
					$to   = (string) ( $row[ $pair[1] ] ?? '' );
					if ( preg_match( '/^\d{2}:\d{2}$/', $from ) && preg_match( '/^\d{2}:\d{2}$/', $to ) && $to > $from ) {
						$ranges[] = $from . '-' . $to;
					}
				}
				if ( $ranges ) {
					$lines[] = $key . ': ' . implode( ', ', $ranges );
				}
			}
			update_option( 'nc_hours', implode( "\n", $lines ) );
		} elseif ( $tab === 'order' ) {
			update_option( 'nc_prep_pickup', absint( $p['prep_pickup'] ?? 20 ) );
			update_option( 'nc_prep_delivery', absint( $p['prep_delivery'] ?? 45 ) );
			$slot = absint( $p['slot'] ?? 15 );
			update_option( 'nc_slot', in_array( $slot, [ 10, 15, 20, 30, 60 ], true ) ? $slot : 15 );
			update_option( 'nc_card', empty( $p['card'] ) ? 0 : 1 );
			update_option( 'nc_paused', empty( $p['paused'] ) ? 0 : 1 );
			update_option( 'nc_watch_on', empty( $p['watch_on'] ) ? 0 : 1 );
			update_option( 'nc_watch_email', is_email( $p['watch_email'] ?? '' ) ? sanitize_email( $p['watch_email'] ) : '' );
			update_option( 'nc_watch_min', max( 2, min( 60, absint( $p['watch_min'] ?? 5 ) ) ) );
			update_option( 'nc_paused_msg', mb_substr( sanitize_textarea_field( (string) ( $p['paused_msg'] ?? '' ) ), 0, 300 ) );
			update_option( 'nc_price_mode', ( $p['price_mode'] ?? '' ) === 'split' ? 'split' : 'wc' );
			foreach ( [ 'name', 'street', 'zip', 'city', 'phone' ] as $f ) {
				update_option( 'nc_rest_' . $f, sanitize_text_field( $p[ 'rest_' . $f ] ?? '' ) );
			}
		} elseif ( $tab === 'menu' ) {
			$out = [];
			foreach ( (array) ( $p['cat'] ?? [] ) as $id ) {
				$id = absint( $id );
				if ( $id ) {
					$out[] = [ 'id' => $id, 'show' => empty( $p['show'][ $id ] ) ? 0 : 1 ];
				}
			}
			update_option( 'nc_cats', $out );
		} elseif ( $tab === 'design' ) {
			update_option( 'nc_color', sanitize_hex_color( (string) ( $p['color'] ?? '' ) ) ?: '#d62828' );
			update_option( 'nc_width', max( 50, min( 100, absint( $p['width'] ?? 80 ) ) ) );
		}

		wp_safe_redirect( self::url( $tab, [ 'saved' => 1 ] ) );
		exit;
	}

	public static function create_page() {
		if ( ! current_user_can( 'publish_pages' ) ) {
			wp_die( 'Nicht erlaubt.' );
		}
		check_admin_referer( 'nc_create_page' );
		$menu = ( $_GET['which'] ?? '' ) !== 'checkout';
		wp_insert_post( [
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_title'   => $menu ? 'Speisekarte' : 'Kasse',
			'post_content' => $menu ? '[nimcode_menu]' : '[nimcode_checkout]',
		] );
		delete_transient( 'nc_checkout_url' );
		delete_transient( 'nc_menu_url' );
		wp_safe_redirect( self::url( 'start', [ 'created' => 1 ] ) );
		exit;
	}

	/* ---------- Page ---------- */

	private static function styles() {
		?>
		<style>
			.nc-admin .nc-card{max-width:860px;margin:16px 0;padding:6px 20px 16px;background:#fff;border:1px solid #dcdcde;border-radius:8px}
			.nc-admin .nc-card h2{margin:14px 0 4px;font-size:16px}
			.nc-admin .desc{color:#646970}
			.nc-admin .nc-t input[type=text],.nc-admin .nc-t input[type=time]{width:100%;max-width:140px}
			.nc-admin .nc-t td,.nc-admin .nc-t th{vertical-align:middle}
			.nc-admin .nc-checks{margin:8px 0 0;padding:0;list-style:none}
			.nc-admin .nc-checks li{display:flex;gap:10px;align-items:flex-start;padding:10px 0;border-bottom:1px solid #f0f0f1}
			.nc-admin .nc-checks li:last-child{border:0}
			.nc-admin .nc-checks .ok .dashicons{color:#00a32a}
			.nc-admin .nc-checks .bad .dashicons{color:#d63638}
			.nc-admin .nc-hrow.off{opacity:.5}
			.nc-admin .nc-code{display:flex;gap:10px;align-items:center;margin:8px 0}
			.nc-admin .nc-code code{padding:6px 10px;font-size:14px}
			.nc-admin .nc-prev{display:inline-block;padding:10px 18px;border-radius:10px;color:#fff;font-weight:700}
			.nc-admin .nc-sort{margin:12px 0 0;padding:0}
			.nc-admin .nc-sort li{display:flex;align-items:center;gap:10px;margin:0 0 6px;padding:8px 12px;background:#fff;border:1px solid #dcdcde;border-radius:6px}
			.nc-admin .nc-handle{cursor:grab;color:#8c8f94}
			.nc-admin .nc-sortlink{margin-left:auto;display:flex;gap:6px;align-items:center}
		</style>
		<?php
	}

	public static function page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$tab = sanitize_key( $_GET['tab'] ?? 'start' );
		if ( ! isset( self::TABS[ $tab ] ) ) {
			$tab = 'start';
		}

		echo '<div class="wrap nc-admin">';
		self::styles();
		echo '<h1>Nimcode Order</h1>';
		if ( ! empty( $_GET['saved'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>Gespeichert.</p></div>';
		}
		if ( ! empty( $_GET['created'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>Seite wurde erstellt.</p></div>';
		}
		echo '<nav class="nav-tab-wrapper">';
		foreach ( self::TABS as $k => $label ) {
			printf( '<a href="%s" class="nav-tab%s">%s</a>', esc_url( self::url( $k ) ), $k === $tab ? ' nav-tab-active' : '', esc_html( $label ) );
		}
		echo '</nav>';

		if ( in_array( $tab, [ 'start', 'app', 'demo', 'legal' ], true ) ) {
			if ( $tab === 'demo' ) {
				NC_Demo::render();
			} elseif ( $tab === 'legal' ) {
				NC_Legal::render();
			} else {
				call_user_func( [ __CLASS__, 'tab_' . $tab ] );
			}
		} else {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			echo '<input type="hidden" name="action" value="nc_save"><input type="hidden" name="tab" value="' . esc_attr( $tab ) . '">';
			wp_nonce_field( 'nc_save_' . $tab );
			call_user_func( [ __CLASS__, 'tab_' . $tab ] );
			submit_button( 'Speichern' );
			echo '</form>';
		}
		echo '</div>';
	}

	/* ---------- Tab: Übersicht ---------- */

	private static function check( $ok, $label, $detail = '', $fix = '' ) {
		printf(
			'<li class="%s"><span class="dashicons %s"></span><div><strong>%s</strong>%s%s</div></li>',
			$ok ? 'ok' : 'bad', $ok ? 'dashicons-yes-alt' : 'dashicons-warning', esc_html( $label ),
			$detail ? '<br><span class="desc">' . esc_html( $detail ) . '</span>' : '', $fix ? '<br>' . $fix : ''
		);
	}

	private static function find_page( $sc ) {
		foreach ( get_posts( [ 'post_type' => 'page', 'post_status' => 'publish', 's' => $sc, 'numberposts' => 10 ] ) as $p ) {
			if ( has_shortcode( $p->post_content, $sc ) ) {
				return $p;
			}
		}
		return null;
	}

	private static function page_check( $sc, $which, $label ) {
		$page = self::find_page( $sc );
		if ( $page ) {
			self::check( true, $label, 'Seite „' . $page->post_title . '“ gefunden.', '<a href="' . esc_url( get_permalink( $page ) ) . '" target="_blank">Seite ansehen</a>' );
			return;
		}
		$url = wp_nonce_url( admin_url( 'admin-post.php?action=nc_create_page&which=' . $which ), 'nc_create_page' );
		self::check( false, $label, 'Keine Seite mit ' . $sc . ' gefunden.', '<a class="button button-primary" href="' . esc_url( $url ) . '">Seite automatisch erstellen</a>' );
	}

	private static function tab_start() {
		$tz     = wp_timezone_string();
		$zones  = count( NC_Delivery::zones() );
		$hours  = count( NC_Hours::hours() );
		$prods  = (int) ( wp_count_posts( 'product' )->publish ?? 0 );
		$groups = (int) ( wp_count_posts( NC_Options::CPT )->publish ?? 0 );
		?>
		<div class="nc-card">
			<h2>Shortcodes</h2>
			<p class="desc">Füge diese Codes in je eine normale Seite ein (Block „Shortcode“ oder einfach als Text).</p>
			<div class="nc-code"><code>[nimcode_menu]</code> <button type="button" class="button nc-copy" data-t="[nimcode_menu]">Kopieren</button> <span class="desc">Speisekarte</span></div>
			<div class="nc-code"><code>[nimcode_checkout]</code> <button type="button" class="button nc-copy" data-t="[nimcode_checkout]">Kopieren</button> <span class="desc">Kasse</span></div>
		</div>
		<div class="nc-card">
			<h2>Einrichtung</h2>
			<ul class="nc-checks">
				<?php
				self::page_check( 'nimcode_menu', 'menu', 'Speisekarte-Seite' );
				self::page_check( 'nimcode_checkout', 'checkout', 'Kasse-Seite' );
				self::check( $prods > 0, 'Produkte', $prods . ' veröffentlicht', $prods ? '' : '<a href="' . esc_url( admin_url( 'post-new.php?post_type=product' ) ) . '">Produkt anlegen</a>' );
				self::check( $groups > 0, 'Optionsgruppen (optional)', $groups . ' vorhanden', $groups ? '' : '<a href="' . esc_url( admin_url( 'post-new.php?post_type=' . NC_Options::CPT ) ) . '">Gruppe anlegen</a>' );
				self::check( $hours > 0, 'Öffnungszeiten', $hours ? $hours . ' Tage eingetragen' : 'Ohne Öffnungszeiten kann niemand bestellen.', $hours ? '' : '<a href="' . esc_url( self::url( 'hours' ) ) . '">Jetzt eintragen</a>' );
				self::check( $zones > 0, 'Liefergebiete', $zones ? $zones . ' Gebiete' : 'Ohne Gebiete ist nur Abholung möglich.', $zones ? '' : '<a href="' . esc_url( self::url( 'delivery' ) ) . '">Jetzt eintragen</a>' );
				self::check( strpos( $tz, '/' ) !== false, 'Zeitzone', 'Aktuell: ' . $tz, strpos( $tz, '/' ) !== false ? '' : '<a href="' . esc_url( admin_url( 'options-general.php' ) ) . '">Auf „Berlin“ stellen</a>' );
				self::check( get_woocommerce_currency() === 'EUR', 'Währung', 'Aktuell: ' . get_woocommerce_currency(), get_woocommerce_currency() === 'EUR' ? '' : '<a href="' . esc_url( admin_url( 'admin.php?page=wc-settings' ) ) . '">In WooCommerce ändern</a>' );
				?>
			</ul>
		</div>
		<script>
		document.querySelectorAll('.nc-copy').forEach(function (b) {
			b.addEventListener('click', function () {
				navigator.clipboard.writeText(b.dataset.t);
				b.textContent = 'Kopiert ✓';
				setTimeout(function () { b.textContent = 'Kopieren'; }, 1500);
			});
		});
		</script>
		<?php
	}

	/* ---------- Tab: Speisekarte ---------- */

	private static function tab_menu() {
		$cats = NC_Menu::categories( true );
		?>
		<div class="nc-card">
			<h2>Reihenfolge der Kategorien</h2>
			<p class="desc">Mit dem Griff ziehen oder die Pfeile benutzen. Die Reihenfolge gilt für die Navigation und die Speisekarte (z. B. Pizza ganz oben). Kategorien ohne Haken werden nicht angezeigt. Die Reihenfolge der Gerichte innerhalb einer Kategorie stellst du über „Produkte sortieren“ ein.</p>
			<ul id="nc-cats" class="nc-sort">
				<?php foreach ( $cats as $c ) :
					$t = $c['term']; ?>
					<li>
						<span class="dashicons dashicons-menu nc-handle" title="Ziehen"></span>
						<input type="hidden" name="cat[]" value="<?php echo (int) $t->term_id; ?>">
						<label><input type="checkbox" name="show[<?php echo (int) $t->term_id; ?>]" value="1" <?php checked( $c['show'] ); ?>> <strong><?php echo esc_html( $t->name ); ?></strong></label>
						<span class="desc">(<?php echo (int) $t->count; ?>)</span>
						<span class="nc-sortlink">
							<a href="<?php echo esc_url( add_query_arg( [ 'post_type' => 'product', 'product_cat' => $t->slug, 'orderby' => 'menu_order title', 'order' => 'asc' ], admin_url( 'edit.php' ) ) ); ?>">Produkte sortieren</a>
							<button type="button" class="button nc-up" title="Nach oben">▲</button><button type="button" class="button nc-down" title="Nach unten">▼</button>
						</span>
					</li>
				<?php endforeach; ?>
			</ul>
			<?php if ( ! $cats ) : ?><p>Noch keine Produktkategorien vorhanden.</p><?php endif; ?>
		</div>
		<script>
		document.addEventListener('DOMContentLoaded', function () {
			var ul = document.getElementById('nc-cats');
			if (!ul) return;
			ul.addEventListener('click', function (e) {
				var li = e.target.closest('li');
				if (!li) return;
				if (e.target.classList.contains('nc-up') && li.previousElementSibling) ul.insertBefore(li, li.previousElementSibling);
				if (e.target.classList.contains('nc-down') && li.nextElementSibling) ul.insertBefore(li.nextElementSibling, li);
			});
			if (window.jQuery && jQuery.fn.sortable) jQuery(ul).sortable({ handle: '.nc-handle', axis: 'y' });
		});
		</script>
		<?php
	}

	/* ---------- Tab: Lieferung ---------- */

	private static function zone_row( $i, $plz, $city, $min, $fee, $free ) {
		printf(
			'<tr><td><input type="text" name="zone[%1$s][plz]" value="%2$s" placeholder="24576 oder 227*"></td>
			<td><input type="text" name="zone[%1$s][city]" value="%3$s" placeholder="leer = ganze PLZ" style="max-width:190px"></td>
			<td><input type="text" inputmode="decimal" name="zone[%1$s][min]" value="%4$s" placeholder="10,00"></td>
			<td><input type="text" inputmode="decimal" name="zone[%1$s][fee]" value="%5$s" placeholder="2,50"></td>
			<td><input type="text" inputmode="decimal" name="zone[%1$s][free]" value="%6$s" placeholder="30,00"></td>
			<td><button type="button" class="button nc-del" title="Entfernen">&times;</button></td></tr>',
			esc_attr( $i ), esc_attr( $plz ), esc_attr( $city ), esc_attr( $min ), esc_attr( $fee ), esc_attr( $free )
		);
	}

	private static function tab_delivery() {
		$rows = NC_Delivery::rows();
		?>
		<div class="nc-card">
			<h2>Liefergebiete nach Postleitzahl und Ort</h2>
			<p class="desc">Eine Zeile pro Gebiet. Haben mehrere Orte dieselbe PLZ, trage je Ort eine Zeile ein: Der Kunde wählt dann seinen Ort aus einer Liste. Gibt es nur einen Ort, wird er automatisch eingetragen. „Ort“ leer = ganze PLZ (der Kunde tippt den Ort selbst). Ein <code>*</code> am Ende der PLZ gilt für alle PLZ, die so beginnen (z. B. <code>227*</code>). „Gratis ab“ leer = nie gratis.</p>
			<table class="widefat striped nc-t">
				<thead><tr><th>PLZ</th><th>Ort</th><th>Mindestbestellwert (€)</th><th>Liefergebühr (€)</th><th>Gratis ab (€)</th><th></th></tr></thead>
				<tbody id="nc-zrows">
					<?php
					$i = 0;
					foreach ( $rows as $z ) {
						self::zone_row( $i++, $z['plz'], $z['city'], number_format( $z['min'], 2, ',', '' ), number_format( $z['fee'], 2, ',', '' ), $z['free_from'] > 0 ? number_format( $z['free_from'], 2, ',', '' ) : '' );
					}
					if ( ! $rows ) {
						self::zone_row( 0, '', '', '', '', '' );
					}
					?>
				</tbody>
			</table>
			<p><button type="button" class="button" id="nc-zadd">+ Zeile hinzufügen</button></p>
			<template id="nc-ztpl"><?php self::zone_row( '__i__', '', '', '', '', '' ); ?></template>

			<details style="margin-top:12px">
				<summary><strong>Mehrere Orte einer PLZ auf einmal hinzufügen</strong></summary>
				<p class="desc">Orte mit Komma trennen, z. B. „Hitzhusen, Bissenmoor, Weddelbrook“. Alle Orte bekommen dieselben Werte (danach in der Tabelle einzeln änderbar).</p>
				<p><input type="text" id="nc-b-plz" placeholder="PLZ" style="width:90px">
				<input type="text" id="nc-b-min" placeholder="Min. €" style="width:80px">
				<input type="text" id="nc-b-fee" placeholder="Gebühr €" style="width:80px">
				<input type="text" id="nc-b-free" placeholder="Gratis ab €" style="width:90px"></p>
				<p><textarea id="nc-b-cities" rows="3" class="large-text" placeholder="Hitzhusen, Bissenmoor, Weddelbrook"></textarea></p>
				<p><button type="button" class="button" id="nc-badd">Zeilen erzeugen</button></p>
			</details>
		</div>
		<div class="nc-card">
			<h2>Standard-Ort</h2>
			<p class="desc">Wird im Checkout vorausgefüllt, wenn für die PLZ kein Ort hinterlegt ist (z. B. Ihre Stadt).</p>
			<input type="text" name="city" class="regular-text" value="<?php echo esc_attr( get_option( 'nc_city', '' ) ); ?>">
		</div>
		<script>
		(function () {
			var rows = document.getElementById('nc-zrows'), n = Date.now();
			function addRow() {
				rows.insertAdjacentHTML('beforeend', document.getElementById('nc-ztpl').innerHTML.replace(/__i__/g, n++));
				return rows.lastElementChild;
			}
			function set(tr, key, val) { tr.querySelector('[name$="[' + key + ']"]').value = val; }
			document.getElementById('nc-zadd').addEventListener('click', addRow);
			rows.addEventListener('click', function (e) {
				if (e.target.classList.contains('nc-del')) e.target.closest('tr').remove();
			});
			document.getElementById('nc-badd').addEventListener('click', function () {
				var plz = document.getElementById('nc-b-plz').value.trim();
				var cities = document.getElementById('nc-b-cities').value.split(/[,\n]/).map(function (c) { return c.trim(); }).filter(Boolean);
				if (!plz || !cities.length) return;
				cities.forEach(function (c) {
					var tr = addRow();
					set(tr, 'plz', plz); set(tr, 'city', c);
					set(tr, 'min', document.getElementById('nc-b-min').value);
					set(tr, 'fee', document.getElementById('nc-b-fee').value);
					set(tr, 'free', document.getElementById('nc-b-free').value);
				});
				document.getElementById('nc-b-cities').value = '';
			});
		})();
		</script>
		<?php
	}

	/* ---------- Tab: Öffnungszeiten ---------- */

	private static function hm( $minutes ) {
		if ( $minutes === null ) {
			return '';
		}
		$minutes = min( $minutes, 1439 );
		return sprintf( '%02d:%02d', intdiv( $minutes, 60 ), $minutes % 60 );
	}

	private static function tab_hours() {
		$h = NC_Hours::hours();
		?>
		<div class="nc-card">
			<h2>Öffnungszeiten</h2>
			<p class="desc">Nur in dieser Zeit können Kunden bestellen. „Zweite Zeit“ ist für eine Mittagspause (z. B. 11:00–14:30 und 17:00–22:00). Ruhetag: Haken entfernen.</p>
			<table class="widefat striped nc-t">
				<thead><tr><th>Tag</th><th>Geöffnet</th><th>Von</th><th>Bis</th><th>Zweite Zeit: Von</th><th>Bis</th></tr></thead>
				<tbody>
				<?php foreach ( self::DAYS as $d => $name ) :
					$r = $h[ $d ] ?? []; ?>
					<tr class="nc-hrow">
						<td><strong><?php echo esc_html( $name ); ?></strong></td>
						<td><input type="checkbox" name="h[<?php echo (int) $d; ?>][open]" value="1" <?php checked( ! empty( $r ) ); ?>></td>
						<td><input type="time" name="h[<?php echo (int) $d; ?>][a1]" value="<?php echo esc_attr( self::hm( $r[0][0] ?? null ) ); ?>"></td>
						<td><input type="time" name="h[<?php echo (int) $d; ?>][b1]" value="<?php echo esc_attr( self::hm( $r[0][1] ?? null ) ); ?>"></td>
						<td><input type="time" name="h[<?php echo (int) $d; ?>][a2]" value="<?php echo esc_attr( self::hm( $r[1][0] ?? null ) ); ?>"></td>
						<td><input type="time" name="h[<?php echo (int) $d; ?>][b2]" value="<?php echo esc_attr( self::hm( $r[1][1] ?? null ) ); ?>"></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<p><button type="button" class="button" id="nc-copymon">Montag auf alle Tage übernehmen</button></p>
		</div>
		<script>
		(function () {
			var rows = Array.prototype.slice.call(document.querySelectorAll('.nc-hrow'));
			function update(row) {
				var on = row.querySelector('input[type=checkbox]').checked;
				row.classList.toggle('off', !on);
				row.querySelectorAll('input[type=time]').forEach(function (i) { i.disabled = !on; });
			}
			rows.forEach(function (r) {
				r.querySelector('input[type=checkbox]').addEventListener('change', function () { update(r); });
				update(r);
			});
			document.getElementById('nc-copymon').addEventListener('click', function () {
				var src = rows[0];
				rows.slice(1).forEach(function (r) {
					r.querySelector('input[type=checkbox]').checked = src.querySelector('input[type=checkbox]').checked;
					var s = src.querySelectorAll('input[type=time]'), t = r.querySelectorAll('input[type=time]');
					s.forEach(function (x, i) { t[i].value = x.value; });
					update(r);
				});
				update(src);
			});
		})();
		</script>
		<?php
	}

	/* ---------- Tab: Bestellung ---------- */

	private static function tab_order() {
		$slot = (int) get_option( 'nc_slot', 15 );
		?>
		<div class="nc-card">
			<h2>Restaurant (Abholadresse)</h2>
			<p class="desc">Wird auf der Kasse angezeigt, wenn der Kunde „Abholung“ wählt, mit Link zu Google Maps.</p>
			<table class="form-table" role="presentation">
				<tr><th>Name</th><td><input type="text" name="rest_name" class="regular-text" value="<?php echo esc_attr( get_option( 'nc_rest_name', '' ) ); ?>"></td></tr>
				<tr><th>Straße und Hausnummer</th><td><input type="text" name="rest_street" class="regular-text" value="<?php echo esc_attr( get_option( 'nc_rest_street', '' ) ); ?>"></td></tr>
				<tr><th>PLZ und Ort</th><td><input type="text" name="rest_zip" value="<?php echo esc_attr( get_option( 'nc_rest_zip', '' ) ); ?>" style="width:90px" placeholder="PLZ"> <input type="text" name="rest_city" value="<?php echo esc_attr( get_option( 'nc_rest_city', '' ) ); ?>" placeholder="Ort"></td></tr>
				<tr><th>Telefon</th><td><input type="text" name="rest_phone" class="regular-text" value="<?php echo esc_attr( get_option( 'nc_rest_phone', '' ) ); ?>"></td></tr>
			</table>
		</div>
		<div class="nc-card">
			<h2>Preise: Lieferung und Abholung</h2>
			<p class="desc">Jedes Produkt (und jede Variante) hat in WooCommerce einen „Normalpreis“ und einen „Angebotspreis“.</p>
			<label><input type="radio" name="price_mode" value="wc" <?php checked( get_option( 'nc_price_mode', 'wc' ), 'wc' ); ?>> <strong>Standard von WooCommerce:</strong> Ist ein Angebotspreis eingetragen, gilt er für alle.</label><br>
			<label><input type="radio" name="price_mode" value="split" <?php checked( get_option( 'nc_price_mode', 'wc' ), 'split' ); ?>> <strong>Getrennt:</strong> Lieferung = Normalpreis, Abholung = Angebotspreis (falls vorhanden, sonst Normalpreis).</label>
		</div>
		<div class="nc-card">
			<h2>Zeiten</h2>
			<table class="form-table" role="presentation">
				<tr><th>Vorbereitungszeit Abholung</th><td><input type="number" min="0" name="prep_pickup" value="<?php echo esc_attr( get_option( 'nc_prep_pickup', 20 ) ); ?>" style="width:80px"> Minuten</td></tr>
				<tr><th>Lieferzeit</th><td><input type="number" min="0" name="prep_delivery" value="<?php echo esc_attr( get_option( 'nc_prep_delivery', 45 ) ); ?>" style="width:80px"> Minuten</td></tr>
				<tr><th>Abstand der Uhrzeiten</th><td>
					<select name="slot"><?php foreach ( [ 10, 15, 20, 30, 60 ] as $v ) {
						printf( '<option value="%1$d"%2$s>%1$d Minuten</option>', $v, selected( $slot, $v, false ) );
					} ?></select>
					<p class="desc">Auswahl im Checkout, z. B. 18:00, 18:15, 18:30 …</p></td></tr>
			</table>
		</div>
		<div class="nc-card">
			<h2>Zahlung</h2>
			<p class="desc">Barzahlung bei Lieferung/Abholung ist immer möglich.</p>
			<label><input type="checkbox" name="card" value="1" <?php checked( get_option( 'nc_card', 1 ), 1 ); ?>> Kartenzahlung vor Ort anbieten (EC/Kreditkarte)</label>
		</div>
		<div class="nc-card">
			<h2>Bestellannahme</h2>
			<label><input type="checkbox" name="paused" value="1" <?php checked( get_option( 'nc_paused', 0 ), 1 ); ?>> <strong>Bestellungen pausieren</strong> (z. B. bei Überlastung oder Ruhetag)</label>
			<p class="desc">Solange aktiv, kann niemand bestellen, unabhängig von den Öffnungszeiten. Das lässt sich auch in der Windows-Kassen-App (Einstellungen) ein- und ausschalten.</p>
			<p><label><strong>Nachricht für Gäste</strong><br><textarea name="paused_msg" rows="2" class="large-text" maxlength="300" placeholder="<?php echo esc_attr( NC_Hours::DEFAULT_PAUSE_MSG ); ?>"><?php echo esc_textarea( (string) get_option( 'nc_paused_msg', '' ) ); ?></textarea></label><span class="desc">Leer lassen = Standardtext.</span></p>
		</div>
		<div class="nc-card">
			<h2>Kassen-Überwachung</h2>
			<?php if ( ! empty( $_GET['mailtest'] ) ) : ?>
				<div class="notice notice-<?php echo $_GET['mailtest'] === 'ok' ? 'success' : 'error'; ?> inline"><p><?php echo $_GET['mailtest'] === 'ok' ? 'Test-E-Mail wurde an WordPress zum Versand übergeben. Bitte im Postfach prüfen (auch Spam).' : 'Der E-Mail-Versand ist fehlgeschlagen. Bitte SMTP einrichten (z. B. WP Mail SMTP).'; ?></p></div>
			<?php endif; ?>
			<p class="desc">Schickt dem Inhaber eine E-Mail, wenn die Kassen-App zu den Öffnungszeiten nicht verbunden ist oder eine Bestellung nicht bei der Kasse ankommt.</p>
			<label><input type="checkbox" name="watch_on" value="1" <?php checked( get_option( 'nc_watch_on', 1 ), 1 ); ?>> <strong>Überwachung aktiv</strong></label>
			<table class="form-table" role="presentation">
				<tr><th>E-Mail des Inhabers</th><td><input type="email" name="watch_email" class="regular-text" value="<?php echo esc_attr( (string) get_option( 'nc_watch_email', '' ) ); ?>" placeholder="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>"><p class="desc">Leer = Administrator-E-Mail.</p></td></tr>
				<tr><th>Warnen nach (Minuten)</th><td><input type="number" name="watch_min" min="2" max="60" value="<?php echo (int) NC_Watch::minutes(); ?>" style="width:80px"><p class="desc">So lange darf die Kasse offline sein bzw. eine Bestellung unbestätigt bleiben.</p></td></tr>
			</table>
			<p><a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=nc_watch_test' ), 'nc_watch_test' ) ); ?>">Test-E-Mail senden</a></p>
			<p class="desc"><strong>Zuverlässigkeit:</strong> Die Prüfung läuft über WP-Cron und startet nur, wenn die Website aufgerufen wird. Für Sicherheit besser einen echten Cron-Job beim Hoster einrichten (jede Minute): <code>wget -q -O - "<?php echo esc_html( site_url( '/wp-cron.php?doing_wp_cron' ) ); ?>"</code> und in der wp-config.php <code>define('DISABLE_WP_CRON', true);</code> setzen.</p>
		</div>
		<?php
	}

	/* ---------- Tab: Windows-App ---------- */

	private static function tab_app() {
		$new = get_transient( 'nc_token_show' );
		if ( $new ) {
			delete_transient( 'nc_token_show' );
		}
		$has  = (string) get_option( 'nc_api_token_hash', '' ) !== '';
		$seen = (int) get_option( 'nc_api_last_seen', 0 );
		$ago  = $seen ? time() - $seen : null;
		$on   = $ago !== null && $ago < 60;
		$url  = wp_nonce_url( admin_url( 'admin-post.php?action=nc_token' ), 'nc_token' );
		?>
		<div class="nc-card">
			<h2>Verbindung</h2>
			<ul class="nc-checks">
				<?php self::check( $on, 'Kassen-App', $seen ? ( $on ? 'Verbunden, zuletzt vor ' . (int) $ago . ' Sekunden aktiv.' : 'Zuletzt aktiv: ' . wp_date( 'd.m.Y H:i', $seen ) ) : 'Noch nie verbunden.' ); ?>
			</ul>
		</div>
		<div class="nc-card">
			<h2>Zugangsdaten für die Windows-App</h2>
			<table class="form-table" role="presentation">
				<tr><th>API-Adresse</th><td><code><?php echo esc_html( rest_url( 'nimcode/v1/' ) ); ?></code></td></tr>
				<tr><th>Token</th><td>
					<?php if ( $new ) : ?>
						<code style="padding:8px 12px;font-size:15px"><?php echo esc_html( $new ); ?></code>
						<button type="button" class="button nc-copy" data-t="<?php echo esc_attr( $new ); ?>">Kopieren</button>
						<p class="desc"><strong>Jetzt kopieren und in der App eintragen.</strong> Der Token wird nur dieses eine Mal angezeigt.</p>
					<?php elseif ( $has ) : ?>
						<span class="desc">Ein Token ist gesetzt (aus Sicherheitsgründen nicht sichtbar).</span>
					<?php else : ?>
						<span class="desc">Noch kein Token erstellt.</span>
					<?php endif; ?>
				</td></tr>
			</table>
			<p><a class="button button-primary" href="<?php echo esc_url( $url ); ?>" <?php echo $has ? 'onclick="return confirm(\'Der alte Token wird ungültig. Die App muss danach neu eingerichtet werden. Fortfahren?\')"' : ''; ?>><?php echo $has ? 'Neuen Token erstellen' : 'Token erstellen'; ?></a></p>
		</div>
		<div class="nc-card">
			<h2>Schnelltest im Browser</h2>
			<p class="desc">Öffne deine Website, drücke F12, wechsle zu „Konsole“ und füge ein (Token einsetzen):</p>
			<code style="display:block;padding:10px;white-space:pre-wrap">fetch('/wp-json/nimcode/v1/ping',{headers:{'X-Nimcode-Token':'DEIN_TOKEN'}}).then(r=>r.json()).then(console.log)</code>
		</div>
		<script>
		document.querySelectorAll('.nc-copy').forEach(function (b) {
			b.addEventListener('click', function () {
				navigator.clipboard.writeText(b.dataset.t);
				b.textContent = 'Kopiert ✓';
			});
		});
		</script>
		<?php
	}

	/* ---------- Tab: Darstellung ---------- */

	private static function tab_design() {
		?>
		<div class="nc-card">
			<h2>Breite der Speisekarte</h2>
			<p class="desc">Auf großen Bildschirmen (ab 900 px) nimmt die Speisekarte diesen Anteil der Bildschirmbreite ein (höchstens 1500 px). Auf Handys immer die volle Breite.</p>
			<input type="number" name="width" min="50" max="100" value="<?php echo esc_attr( self::width() ); ?>" style="width:80px"> %
		</div>
		<div class="nc-card">
			<h2>Hauptfarbe</h2>
			<p class="desc">Farbe für Buttons und Hervorhebungen auf Speisekarte und Kasse.</p>
			<input type="color" name="color" id="nc-color" value="<?php echo esc_attr( self::accent() ); ?>">
			<button type="button" class="button" id="nc-colreset">Standard</button>
			<p><span class="nc-prev" id="nc-prev" style="background:<?php echo esc_attr( self::accent() ); ?>">In den Warenkorb</span></p>
		</div>
		<script>
		(function () {
			var c = document.getElementById('nc-color'), p = document.getElementById('nc-prev');
			c.addEventListener('input', function () { p.style.background = c.value; });
			document.getElementById('nc-colreset').addEventListener('click', function () { c.value = '#d62828'; p.style.background = c.value; });
		})();
		</script>
		<?php
	}
}
