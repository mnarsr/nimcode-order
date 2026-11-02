# Nimcode Order

Schlankes WordPress/WooCommerce-Plugin für Restaurants: Online-Bestellung mit Lieferung und Abholung, Optionsgruppen (Extras), Lieferzonen nach PLZ, Öffnungszeiten, zeitlich begrenzte Angebote (z. B. Mittagsangebot) und eine API für die Windows-Kassen-App **Nimcode KDS**.

## Funktionen
- Speisekarte per Shortcode `[nimcode_menu]`, Kasse per `[nimcode_checkout]`
- Lieferung/Abholung, Lieferzonen mit Mindestbestellwert und Liefergebühr
- Optionsgruppen (Extras) pro Produkt und pro Variante, zwei Preise (Lieferung/Abholung)
- Öffnungszeiten, Wunschzeit, Bestellungen pausieren (auch aus der Kassen-App)
- Produkte nur zu bestimmten Zeiten (z. B. Mo-Fr 12:00-14:00)
- Dashboard für Bestellungen, Vorlagen für Impressum, Datenschutz und AGB
- Demo-Daten mit 30 Gerichten (Reiter Demo-Daten)
- REST-API `nimcode/v1` mit Token für die Kassen-App

## Installation
1. Ordner als ZIP packen (der Ordner `nimcode-order` muss im ZIP enthalten sein)
2. WordPress: Plugins > Installieren > Plugin hochladen
3. Nimcode Order > Einstellungen > Übersicht: Seiten erstellen

Voraussetzung: WooCommerce.

## Entwicklung
Die Version steht in `nimcode-order.php` (Kopfzeile und `NC_VERSION`). Bei jeder Änderung an CSS oder JS die Version erhöhen, sonst liefern Browser alte Dateien aus.
