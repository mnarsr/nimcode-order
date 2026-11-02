<?php
defined( 'ABSPATH' ) || exit;

/**
 * Templates for Impressum, Datenschutz and AGB. Created as DRAFTS with placeholders in [brackets].
 * These are templates, not legal advice: the restaurant owner must check and complete them.
 */
class NC_Legal {

	public static function init() {
		add_action( 'admin_post_nc_legal_create', [ __CLASS__, 'create' ] );
	}

	private static function pages() {
		return [ 'impressum' => 'Impressum', 'datenschutz' => 'Datenschutzerklärung', 'agb' => 'AGB' ];
	}

	public static function render() {
		echo '<div class="nc-card"><h2>Rechtstexte (Vorlagen)</h2>';
		echo '<p class="desc">Erstellt die Seiten <strong>Impressum</strong>, <strong>Datenschutzerklärung</strong> und <strong>AGB</strong> als <strong>Entwurf</strong>. Name, Adresse, Telefon und E-Mail werden aus den Restaurant-Angaben (Reiter „Bestellung“) übernommen. Alles, was noch fehlt, steht in <code>[eckigen Klammern]</code>.</p>';
		echo '<p><strong>Wichtig:</strong> Das sind Vorlagen und keine Rechtsberatung. Bitte alles lesen, ergänzen und vom Inhaber (am besten mit einem Anwalt oder einem Rechtstext-Dienst) prüfen lassen, bevor die Seiten veröffentlicht werden.</p>';
		if ( ! empty( $_GET['legal'] ) ) {
			echo '<div class="notice notice-success inline"><p>Fertig: ' . (int) ( $_GET['n'] ?? 0 ) . ' Seite(n) als Entwurf angelegt. Bitte prüfen und veröffentlichen.</p></div>';
		}
		echo '<ul>';
		foreach ( self::pages() as $slug => $title ) {
			$p = get_page_by_path( $slug );
			echo '<li>' . esc_html( $title ) . ': ';
			if ( $p ) {
				echo '<a href="' . esc_url( get_edit_post_link( $p->ID ) ) . '">bearbeiten</a> (' . esc_html( $p->post_status === 'publish' ? 'veröffentlicht' : 'Entwurf' ) . ')';
			} else {
				echo 'noch nicht vorhanden';
			}
			echo '</li>';
		}
		echo '</ul>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="nc_legal_create">';
		wp_nonce_field( 'nc_legal_create' );
		submit_button( 'Fehlende Seiten als Entwurf erstellen', 'primary', 'submit', false );
		echo ' <span class="desc">Vorhandene Seiten werden nicht überschrieben.</span></form></div>';
	}

	public static function create() {
		if ( ! current_user_can( 'manage_woocommerce' ) || ! current_user_can( 'publish_pages' ) ) {
			wp_die( 'Nicht erlaubt.' );
		}
		check_admin_referer( 'nc_legal_create' );
		$n = 0;
		foreach ( self::pages() as $slug => $title ) {
			if ( get_page_by_path( $slug ) ) {
				continue;
			}
			$id = wp_insert_post( [
				'post_type'    => 'page',
				'post_status'  => 'draft',
				'post_title'   => $title,
				'post_name'    => $slug,
				'post_content' => self::content( $slug ),
			] );
			if ( $id && ! is_wp_error( $id ) ) {
				$n++;
			}
		}
		wp_safe_redirect( NC_Admin::url( 'legal', [ 'legal' => 1, 'n' => $n ] ) );
		exit;
	}

	/* ---------- Content ---------- */

	private static function data() {
		$todo = function ( $v, $label ) {
			$v = trim( (string) $v );
			return $v !== '' ? esc_html( $v ) : '[' . $label . ']';
		};
		$name = get_option( 'nc_rest_name', '' ) ?: get_bloginfo( 'name' );
		return [
			'name'   => $todo( $name, 'Name des Restaurants' ),
			'street' => $todo( get_option( 'nc_rest_street', '' ), 'Straße und Hausnummer' ),
			'city'   => $todo( trim( get_option( 'nc_rest_zip', '' ) . ' ' . get_option( 'nc_rest_city', '' ) ), 'PLZ und Ort' ),
			'phone'  => $todo( get_option( 'nc_rest_phone', '' ), 'Telefon' ),
			'mail'   => $todo( get_option( 'admin_email', '' ), 'E-Mail-Adresse' ),
			'owner'  => '[Vor- und Nachname des Inhabers]',
			'site'   => esc_html( home_url() ),
		];
	}

	private static function content( $slug ) {
		$d = self::data();
		return call_user_func( [ __CLASS__, 'tpl_' . $slug ], $d );
	}

	private static function tpl_impressum( $d ) {
		return <<<HTML
<p><strong>Hinweis:</strong> Diese Seite ist eine Vorlage. Bitte alle Angaben in [eckigen Klammern] ergänzen und diesen Hinweis löschen.</p>

<h2>Angaben gemäß § 5 DDG</h2>
<p>{$d['name']}<br>Inhaber: {$d['owner']}<br>{$d['street']}<br>{$d['city']}</p>

<h2>Kontakt</h2>
<p>Telefon: {$d['phone']}<br>E-Mail: {$d['mail']}</p>

<h2>Umsatzsteuer-ID</h2>
<p>Umsatzsteuer-Identifikationsnummer gemäß § 27a Umsatzsteuergesetz: [USt-IdNr., falls vorhanden]</p>

<h2>Verantwortlich für den Inhalt nach § 18 Abs. 2 MStV</h2>
<p>{$d['owner']}, Anschrift wie oben</p>

<h2>Verbraucherstreitbeilegung</h2>
<p>Wir sind nicht bereit und nicht verpflichtet, an Streitbeilegungsverfahren vor einer Verbraucherschlichtungsstelle teilzunehmen. [Bitte prüfen]</p>

<h2>Haftung für Inhalte und Links</h2>
<p>Als Diensteanbieter sind wir für eigene Inhalte auf diesen Seiten nach den allgemeinen Gesetzen verantwortlich. Für die Inhalte externer Links übernehmen wir keine Haftung; für diese sind ausschließlich deren Betreiber verantwortlich.</p>

<h2>Webdesign</h2>
<p>Design und technische Umsetzung: <a href="https://nimcode.de" target="_blank" rel="noopener">nimcode</a></p>
HTML;
	}

	private static function tpl_datenschutz( $d ) {
		return <<<HTML
<p><strong>Hinweis:</strong> Diese Seite ist eine Vorlage. Bitte alle Angaben in [eckigen Klammern] prüfen und ergänzen und diesen Hinweis löschen. Sie ersetzt keine Rechtsberatung.</p>

<h2>1. Verantwortlicher</h2>
<p>{$d['name']}, {$d['owner']}<br>{$d['street']}, {$d['city']}<br>Telefon: {$d['phone']}<br>E-Mail: {$d['mail']}</p>

<h2>2. Besuch dieser Website (Server-Logfiles)</h2>
<p>Beim Aufruf unserer Website verarbeitet der Hosting-Anbieter technisch notwendige Daten (IP-Adresse, Datum und Uhrzeit, aufgerufene Seite, Browser und Betriebssystem). Das ist nötig, um die Seite auszuliefern und sicher zu betreiben. Rechtsgrundlage ist Art. 6 Abs. 1 lit. f DSGVO (berechtigtes Interesse an einem sicheren und stabilen Betrieb). Die Logfiles werden nach [Dauer, z. B. 7 Tagen] gelöscht. Hosting-Anbieter: [Name und Sitz des Hosters]. Mit dem Anbieter besteht ein Vertrag zur Auftragsverarbeitung.</p>

<h2>3. Online-Bestellung</h2>
<p>Wenn Sie bei uns bestellen, verarbeiten wir die Daten, die Sie im Bestellformular angeben: Name, Telefonnummer, bei Lieferung Ihre Adresse, optional E-Mail-Adresse, Ihre Bestellung, Wunschzeit, Zahlungsart und Anmerkungen. Wir nutzen diese Daten nur, um Ihre Bestellung anzunehmen, zuzubereiten, zu liefern und bei Rückfragen mit Ihnen Kontakt aufzunehmen. Rechtsgrundlage ist Art. 6 Abs. 1 lit. b DSGVO (Vertragserfüllung).</p>
<p>Aus steuer- und handelsrechtlichen Gründen müssen wir Bestelldaten und Belege bis zu [6 bzw. 10] Jahre aufbewahren (Art. 6 Abs. 1 lit. c DSGVO). Danach werden sie gelöscht.</p>
<p>Ihre Bestelldaten werden in unserem Shopsystem (WordPress mit WooCommerce) auf dem oben genannten Server gespeichert und in unserer Kassen-Software im Restaurant angezeigt und ausgedruckt. Eine Weitergabe an Dritte findet nur statt, soweit sie zur Erfüllung nötig ist (z. B. Lieferdienst-Fahrer) oder wir gesetzlich dazu verpflichtet sind.</p>

<h2>4. Cookies und ähnliche Technik</h2>
<p>Wir setzen nur technisch notwendige Cookies bzw. Speicherungen ein, zum Beispiel für Ihren Warenkorb und die Wahl zwischen Lieferung und Abholung. Dafür ist keine Einwilligung nötig (§ 25 Abs. 2 Nr. 2 TDDDG). Wir verwenden keine Tracking- oder Werbe-Cookies. [Bitte prüfen, falls weitere Dienste wie Statistik oder Karten eingebunden sind.]</p>

<h2>5. Kontaktaufnahme</h2>
<p>Wenn Sie uns per E-Mail oder Telefon kontaktieren, speichern wir Ihre Angaben zur Bearbeitung der Anfrage. Rechtsgrundlage ist Art. 6 Abs. 1 lit. b oder f DSGVO. Wir löschen die Daten, sobald sie nicht mehr erforderlich sind.</p>

<h2>6. Externe Links</h2>
<p>Die Adresse des Restaurants kann mit einem Link zu Google Maps verknüpft sein. Erst wenn Sie den Link anklicken, werden Daten an Google übertragen. Es gelten dann die Datenschutzbestimmungen von Google.</p>

<h2>7. Ihre Rechte</h2>
<p>Sie haben das Recht auf Auskunft (Art. 15 DSGVO), Berichtigung (Art. 16), Löschung (Art. 17), Einschränkung der Verarbeitung (Art. 18), Datenübertragbarkeit (Art. 20) und Widerspruch (Art. 21). Wenden Sie sich dazu an die oben genannten Kontaktdaten.</p>
<p>Sie haben außerdem das Recht, sich bei einer Datenschutz-Aufsichtsbehörde zu beschweren. [Zuständige Behörde ergänzen. In Schleswig-Holstein: Unabhängiges Landeszentrum für Datenschutz (ULD), Holstenstraße 98, 24103 Kiel.]</p>

<h2>8. Aktualität</h2>
<p>Stand: [Datum]. Wir passen diese Erklärung an, wenn sich unsere Angebote oder die Rechtslage ändern.</p>
HTML;
	}

	private static function tpl_agb( $d ) {
		return <<<HTML
<p><strong>Hinweis:</strong> Diese Seite ist eine Vorlage. Bitte alle Angaben in [eckigen Klammern] prüfen und ergänzen und diesen Hinweis löschen. Sie ersetzt keine Rechtsberatung.</p>

<h2>1. Geltungsbereich</h2>
<p>Diese Bedingungen gelten für alle Bestellungen über die Website {$d['site']} bei {$d['name']}, {$d['street']}, {$d['city']} (nachfolgend „wir“).</p>

<h2>2. Vertragsschluss</h2>
<p>Die Darstellung der Speisen und Getränke ist kein verbindliches Angebot. Mit dem Klick auf „Jetzt bestellen“ geben Sie eine verbindliche Bestellung ab. Der Vertrag kommt zustande, wenn wir Ihre Bestellung annehmen, spätestens mit der Lieferung bzw. der Übergabe bei Abholung. Wir können eine Bestellung ablehnen, zum Beispiel bei Betriebsstörungen, Ausverkauf oder außerhalb der Öffnungszeiten.</p>

<h2>3. Preise</h2>
<p>Alle Preise sind Endpreise in Euro und enthalten die gesetzliche Umsatzsteuer. Bei Lieferung kommen die im Bestellvorgang angezeigten Liefergebühren hinzu. Preise können je nach Lieferung oder Abholung unterschiedlich sein; maßgeblich ist der im Warenkorb angezeigte Betrag.</p>

<h2>4. Lieferung und Abholung</h2>
<p>Wir liefern in die im Bestellvorgang genannten Liefergebiete. Für jedes Gebiet gilt ein Mindestbestellwert, der im Warenkorb angezeigt wird. Genannte Zeiten sind Richtwerte; bei großem Andrang oder schlechtem Wetter kann es länger dauern. Bitte sorgen Sie dafür, dass Sie unter der angegebenen Telefonnummer erreichbar sind. Bei Abholung holen Sie Ihre Bestellung zur angegebenen Zeit im Restaurant ab.</p>

<h2>5. Bezahlung</h2>
<p>Sie zahlen bar bei Lieferung bzw. Abholung. [Weitere Zahlungsarten ergänzen, z. B. Kartenzahlung per Terminal.]</p>

<h2>6. Widerrufsrecht</h2>
<p>Bei Verträgen über die Lieferung von Waren, die schnell verderben können oder deren Verfallsdatum schnell überschritten würde, besteht kein Widerrufsrecht (§ 312g Abs. 2 Nr. 2 BGB). Das gilt für frisch zubereitete Speisen und Getränke.</p>

<h2>7. Mängel und Reklamation</h2>
<p>Sind Speisen oder Getränke fehlerhaft oder fehlen Teile Ihrer Bestellung, melden Sie sich bitte sofort telefonisch unter {$d['phone']}. Es gelten die gesetzlichen Gewährleistungsrechte.</p>

<h2>8. Allergene und Zusatzstoffe</h2>
<p>Informationen zu Allergenen und Zusatzstoffen erhalten Sie auf Anfrage im Restaurant. [Bitte prüfen: Kennzeichnung gemäß Lebensmittelinformationsverordnung ergänzen.]</p>

<h2>9. Haftung</h2>
<p>Wir haften unbeschränkt bei Vorsatz und grober Fahrlässigkeit sowie bei Verletzung von Leben, Körper und Gesundheit. Bei leichter Fahrlässigkeit haften wir nur bei Verletzung wesentlicher Vertragspflichten und begrenzt auf den vorhersehbaren, typischen Schaden. Die Haftung nach dem Produkthaftungsgesetz bleibt unberührt.</p>

<h2>10. Datenschutz</h2>
<p>Informationen zur Verarbeitung Ihrer Daten finden Sie in unserer Datenschutzerklärung.</p>

<h2>11. Schlussbestimmungen</h2>
<p>Es gilt deutsches Recht. Ist eine Bestimmung unwirksam, bleibt der Rest wirksam. Stand: [Datum].</p>
HTML;
	}
}
