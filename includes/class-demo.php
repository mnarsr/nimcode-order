<?php
defined( 'ABSPATH' ) || exit;

/**
 * Demo data: 30 products (simple + variable) in 8 categories, with extras (option groups),
 * two prices (regular = delivery, sale = pickup) and illustrated images.
 * Everything created here is marked with the meta "_nc_demo" so it can be removed in one click.
 */
class NC_Demo {

	public static function init() {
		add_action( 'admin_post_nc_demo_import', [ __CLASS__, 'import' ] );
		add_action( 'admin_post_nc_demo_delete', [ __CLASS__, 'delete' ] );
	}

	/* ---------- Data ---------- */

	private static function groups() {
		$pizza = [ [ 'Extra Käse', 1.00 ], [ 'Schinken', 1.50 ], [ 'Salami', 1.50 ], [ 'Champignons', 1.00 ], [ 'Zwiebeln', 0.50 ], [ 'Oliven', 1.00 ], [ 'Thunfisch', 1.50 ], [ 'Peperoni', 0.80 ], [ 'Knoblauch', 0.50 ] ];
		$party = array_map( function ( $o ) {
			return [ $o[0], round( $o[1] * 2, 2 ) ];
		}, $pizza );
		return [
			'pizza'   => [ 'Pizza Extras', 'multi', 0, $pizza ],
			'party'   => [ 'Pizza Extras Party', 'multi', 0, $party ],
			'sauce'   => [ 'Soßen', 'multi', 0, [ [ 'Knoblauchsoße', 0.50 ], [ 'Kräutersoße', 0.50 ], [ 'Scharfe Soße', 0.50 ] ] ],
			'pasta'   => [ 'Pasta Extras', 'multi', 0, [ [ 'Extra Parmesan', 1.00 ], [ 'Extra Soße', 1.50 ], [ 'Brot dazu', 1.50 ] ] ],
			'doener'  => [ 'Döner Extras', 'multi', 0, [ [ 'Extra Fleisch', 2.00 ], [ 'Extra Käse', 1.00 ], [ 'Scharf', 0 ], [ 'Ohne Zwiebeln', 0 ] ] ],
			'dsauce'  => [ 'Döner Soße', 'single', 1, [ [ 'Knoblauchsoße', 0 ], [ 'Kräutersoße', 0 ], [ 'Scharfe Soße', 0 ], [ 'Mix', 0 ] ] ],
			'burger'  => [ 'Burger Extras', 'multi', 0, [ [ 'Extra Patty', 2.50 ], [ 'Bacon', 1.50 ], [ 'Extra Käse', 1.00 ], [ 'Jalapeños', 0.80 ] ] ],
			'menu'    => [ 'Burger Menü', 'single', 1, [ [ 'Nur Burger', 0 ], [ 'Mit Pommes', 2.50 ], [ 'Mit Pommes und Cola', 4.50 ] ] ],
			'dressing' => [ 'Dressing', 'single', 1, [ [ 'Joghurt', 0 ], [ 'Balsamico', 0 ], [ 'Essig und Öl', 0 ], [ 'French', 0 ] ] ],
			'dip'     => [ 'Dip', 'multi', 0, [ [ 'Ketchup', 0.50 ], [ 'Mayo', 0.50 ], [ 'Knoblauchdip', 0.70 ] ] ],
		];
	}

	private static function categories() {
		return [ 'Pizza', 'Pasta', 'Döner & Grill', 'Burger', 'Salate', 'Beilagen', 'Desserts', 'Getränke' ];
	}

	/** [slug, name, category, description, type, price|variations, extra: attribute name, groups, sale discount] */
	private static function products() {
		$sizes = function ( $a, $b, $c ) {
			return [ [ 'klein 26 cm', $a, 'pizza' ], [ 'normal 32 cm', $b, 'pizza' ], [ 'party 40*60 cm', $c, 'party' ] ];
		};
		$meat = function ( $a, $b, $c ) {
			return [ [ 'Kalbfleisch', $a ], [ 'Hähnchen', $b ], [ 'Mix', $c ] ];
		};
		return [
			[ 'margherita', 'Pizza Margherita', 'Pizza', 'Klassisch mit Tomatensoße, Mozzarella und frischem Basilikum.', 'variable', $sizes( 8.90, 11.30, 20.50 ), 'Größe', [ 'pizza', 'sauce' ], 1.0 ],
			[ 'salami', 'Pizza Salami', 'Pizza', 'Mit würziger Rindersalami.', 'variable', $sizes( 9.90, 12.90, 21.40 ), 'Größe', [ 'pizza', 'sauce' ], 1.0 ],
			[ 'prosciutto', 'Pizza Prosciutto', 'Pizza', 'Mit gekochtem Schinken.', 'variable', $sizes( 9.90, 12.90, 21.40 ), 'Größe', [ 'pizza', 'sauce' ], 1.0 ],
			[ 'funghi', 'Pizza Funghi', 'Pizza', 'Mit frischen Champignons.', 'variable', $sizes( 9.90, 12.50, 21.00 ), 'Größe', [ 'pizza', 'sauce' ], 1.0 ],
			[ 'hawaii', 'Pizza Hawaii', 'Pizza', 'Mit Schinken und Ananas.', 'variable', $sizes( 10.50, 13.50, 22.50 ), 'Größe', [ 'pizza', 'sauce' ], 1.0 ],
			[ 'tonno', 'Pizza Tonno', 'Pizza', 'Mit Thunfisch, Zwiebeln und Oliven.', 'variable', $sizes( 10.90, 13.90, 22.90 ), 'Größe', [ 'pizza', 'sauce' ], 1.0 ],
			[ 'diavola', 'Pizza Diavola', 'Pizza', 'Scharf mit Peperoniwurst, Peperoni und Paprika.', 'variable', $sizes( 10.50, 13.50, 22.50 ), 'Größe', [ 'pizza', 'sauce' ], 1.0 ],
			[ 'quattro-formaggi', 'Pizza Quattro Formaggi', 'Pizza', 'Vier Käsesorten: Mozzarella, Gorgonzola, Parmesan und Edamer.', 'variable', $sizes( 11.00, 14.00, 23.50 ), 'Größe', [ 'pizza', 'sauce' ], 1.0 ],
			[ 'vegetale', 'Pizza Vegetale', 'Pizza', 'Vegetarisch mit Paprika, Tomaten, Champignons, Oliven und Mais.', 'variable', $sizes( 10.50, 13.50, 22.50 ), 'Größe', [ 'pizza', 'sauce' ], 1.0 ],

			[ 'spaghetti-bolognese', 'Spaghetti Bolognese', 'Pasta', 'Mit Hackfleischsoße nach Hausrezept.', 'variable', [ [ 'normale Portion', 10.90 ], [ 'große Portion', 13.90 ] ], 'Portion', [ 'pasta' ], 0 ],
			[ 'penne-arrabbiata', 'Penne Arrabbiata', 'Pasta', 'Scharfe Tomatensoße mit Knoblauch und Peperoni. Vegetarisch.', 'simple', 9.90, '', [ 'pasta' ], 0 ],
			[ 'tagliatelle-lachs', 'Tagliatelle Lachs-Sahne', 'Pasta', 'Bandnudeln mit Lachs in Sahnesoße und Dill.', 'simple', 13.50, '', [ 'pasta' ], 0 ],
			[ 'lasagne', 'Lasagne al Forno', 'Pasta', 'Überbacken mit Hackfleischsoße, Béchamel und Käse.', 'simple', 11.90, '', [ 'pasta' ], 0 ],

			[ 'doener-im-brot', 'Döner im Brot', 'Döner & Grill', 'Mit Salat, Tomaten, Zwiebeln und Soße.', 'variable', $meat( 7.50, 7.50, 8.00 ), 'Fleisch', [ 'dsauce', 'doener' ], 0 ],
			[ 'dueruem-doener', 'Dürüm Döner', 'Döner & Grill', 'Im dünnen Fladen gerollt, mit Salat und Soße.', 'variable', $meat( 8.50, 8.50, 9.00 ), 'Fleisch', [ 'dsauce', 'doener' ], 0 ],
			[ 'doener-teller', 'Döner Teller', 'Döner & Grill', 'Mit Reis, Salat und Soße.', 'variable', $meat( 14.50, 14.50, 15.00 ), 'Fleisch', [ 'dsauce', 'doener' ], 0 ],
			[ 'lahmacun', 'Lahmacun', 'Döner & Grill', 'Türkische Pizza mit Hackfleisch, Salat und Zitrone.', 'simple', 6.50, '', [ 'dsauce' ], 0 ],

			[ 'cheeseburger', 'Cheeseburger', 'Burger', 'Rindfleisch-Patty, Cheddar, Salat, Tomaten und Burgersoße.', 'simple', 8.90, '', [ 'menu', 'burger' ], 0.5 ],
			[ 'chickenburger', 'Chickenburger', 'Burger', 'Knuspriges Hähnchen mit Salat, Tomaten und Mayo.', 'simple', 9.50, '', [ 'menu', 'burger' ], 0.5 ],
			[ 'bacon-burger', 'Bacon Burger', 'Burger', 'Rindfleisch-Patty mit Bacon, Cheddar und BBQ-Soße.', 'simple', 10.50, '', [ 'menu', 'burger' ], 0.5 ],
			[ 'veggie-burger', 'Veggie Burger', 'Burger', 'Gemüse-Patty mit Salat, Tomaten und Zwiebeln. Vegetarisch.', 'simple', 9.90, '', [ 'menu', 'burger' ], 0.5 ],

			[ 'gemischter-salat', 'Gemischter Salat', 'Salate', 'Blattsalat, Tomaten, Gurken, Möhren und Mais.', 'simple', 7.90, '', [ 'dressing' ], 0 ],
			[ 'caesar-salat', 'Caesar Salat', 'Salate', 'Mit Hähnchenbruststreifen, Croutons und Parmesan.', 'simple', 9.50, '', [ 'dressing' ], 0 ],
			[ 'thunfisch-salat', 'Thunfisch Salat', 'Salate', 'Mit Thunfisch, Ei, Oliven und Zwiebeln.', 'simple', 9.90, '', [ 'dressing' ], 0 ],

			[ 'pommes-frites', 'Pommes Frites', 'Beilagen', 'Frisch frittiert und knusprig.', 'simple', 3.90, '', [ 'dip' ], 0 ],
			[ 'pizzabroetchen', 'Pizzabrötchen', 'Beilagen', 'Warm mit Kräuterbutter, dazu Knoblauchdip.', 'simple', 4.50, '', [ 'dip' ], 0 ],

			[ 'tiramisu', 'Tiramisu', 'Desserts', 'Hausgemacht mit Mascarpone und Espresso.', 'simple', 5.50, '', [], 0 ],
			[ 'panna-cotta', 'Panna Cotta', 'Desserts', 'Mit Fruchtsoße aus Kirschen.', 'simple', 5.20, '', [], 0 ],

			[ 'cola', 'Cola', 'Getränke', 'Eisgekühlt.', 'variable', [ [ '0,33 l', 2.50 ], [ '0,5 l', 3.20 ], [ '1,0 l', 4.50 ] ], 'Größe', [], 0 ],
			[ 'ayran', 'Ayran', 'Getränke', 'Erfrischender Joghurtdrink, 0,25 l.', 'simple', 2.20, '', [], 0 ],
		];
	}

	/* ---------- Admin tab ---------- */

	public static function render() {
		$count = count( self::demo_products() );
		echo '<div class="nc-card"><h2>Demo-Daten</h2>';
		echo '<p class="desc">Legt 30 Beispielgerichte in 8 Kategorien an, mit Größen, Extras (Optionsgruppen), zwei Preisen und Bildern. Ideal für eine Demo-Seite und Bilder für die Werbung. Alles, was hier entsteht, lässt sich mit einem Klick wieder löschen.</p>';
		if ( ! class_exists( 'WC_Product_Simple' ) ) {
			echo '<p><strong>WooCommerce ist nicht aktiv.</strong></p></div>';
			return;
		}
		if ( ! empty( $_GET['demo'] ) ) {
			$n = (int) ( $_GET['n'] ?? 0 );
			$msg = $_GET['demo'] === 'imported' ? "Fertig: {$n} Gerichte wurden angelegt." : "Gelöscht: {$n} Gerichte und alle Demo-Extras.";
			echo '<div class="notice notice-success inline"><p>' . esc_html( $msg ) . '</p></div>';
		}
		echo '<p>Aktuell vorhanden: <strong>' . (int) $count . '</strong> Demo-Gerichte.</p>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin:14px 0">';
		echo '<input type="hidden" name="action" value="nc_demo_import">';
		wp_nonce_field( 'nc_demo_import' );
		echo '<label><input type="checkbox" name="split" value="1" checked> Preise getrennt einstellen (Lieferung = normaler Preis, Abholung = Angebotspreis)</label><br><br>';
		submit_button( 'Demo-Daten importieren', 'primary', 'submit', false );
		echo ' <span class="desc">Dauert etwa eine halbe Minute. Beim zweiten Klick werden vorhandene Gerichte übersprungen.</span></form>';

		if ( $count ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" onsubmit="return confirm(\'Alle Demo-Gerichte, Demo-Extras, Demo-Bilder und Demo-Kategorien löschen?\');">';
			echo '<input type="hidden" name="action" value="nc_demo_delete">';
			wp_nonce_field( 'nc_demo_delete' );
			submit_button( 'Alle Demo-Daten löschen', 'delete', 'submit', false );
			echo '</form>';
		}
		echo '</div>';
	}

	/* ---------- Import ---------- */

	private static function demo_ids( $post_type ) {
		return get_posts( [ 'post_type' => $post_type, 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids', 'meta_key' => '_nc_demo', 'meta_value' => '1' ] );
	}

	private static function demo_products() {
		return self::demo_ids( 'product' );
	}

	private static function find_demo( $post_type, $key ) {
		$ids = get_posts( [ 'post_type' => $post_type, 'post_status' => 'any', 'numberposts' => 1, 'fields' => 'ids', 'meta_key' => '_nc_demo_key', 'meta_value' => $key ] );
		return $ids ? (int) $ids[0] : 0;
	}

	public static function import() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'Nicht erlaubt.' );
		}
		check_admin_referer( 'nc_demo_import' );
		if ( ! class_exists( 'WC_Product_Simple' ) ) {
			wp_die( 'WooCommerce ist nicht aktiv.' );
		}
		@set_time_limit( 300 );
		ignore_user_abort( true );
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		// 1) Categories
		$cat_ids = [];
		foreach ( self::categories() as $i => $name ) {
			$term = term_exists( $name, 'product_cat' );
			$ours = false;
			if ( ! $term ) {
				$term = wp_insert_term( $name, 'product_cat' );
				$ours = true;
			}
			if ( is_wp_error( $term ) ) {
				continue;
			}
			$tid = (int) ( is_array( $term ) ? $term['term_id'] : $term );
			$cat_ids[ $name ] = $tid;
			if ( $ours ) {
				update_term_meta( $tid, '_nc_demo', 1 );
				update_term_meta( $tid, 'order', $i );
			}
		}

		// 2) Option groups
		$group_ids = [];
		foreach ( self::groups() as $key => $g ) {
			$id = self::find_demo( NC_Options::CPT, 'grp-' . $key );
			if ( ! $id ) {
				$id = wp_insert_post( [ 'post_type' => NC_Options::CPT, 'post_status' => 'publish', 'post_title' => $g[0] ] );
				if ( is_wp_error( $id ) || ! $id ) {
					continue;
				}
				$opts = [];
				foreach ( $g[3] as $o ) {
					$opts[] = [ 'name' => $o[0], 'price' => (float) $o[1] ];
				}
				update_post_meta( $id, '_nc_group', [ 'type' => $g[1], 'required' => (int) $g[2], 'max' => 0, 'options' => $opts ] );
				update_post_meta( $id, '_nc_demo', 1 );
				update_post_meta( $id, '_nc_demo_key', 'grp-' . $key );
			}
			$group_ids[ $key ] = (int) $id;
		}

		// 3) Products
		$made = 0;
		foreach ( self::products() as $order => $p ) {
			list( $slug, $name, $cat, $desc, $type, $price, $attr, $gkeys, $disc ) = $p;
			if ( self::find_demo( 'product', 'prd-' . $slug ) ) {
				continue;
			}
			$gids = [];
			foreach ( $gkeys as $k ) {
				if ( isset( $group_ids[ $k ] ) ) {
					$gids[] = $group_ids[ $k ];
				}
			}

			$prod = $type === 'variable' ? new WC_Product_Variable() : new WC_Product_Simple();
			$prod->set_name( $name );
			$prod->set_status( 'publish' );
			$prod->set_catalog_visibility( 'visible' );
			$prod->set_description( $desc );
			$prod->set_short_description( $desc );
			$prod->set_menu_order( $order );
			if ( isset( $cat_ids[ $cat ] ) ) {
				$prod->set_category_ids( [ $cat_ids[ $cat ] ] );
			}
			if ( $type === 'simple' ) {
				$prod->set_regular_price( number_format( (float) $price, 2, '.', '' ) );
				if ( $disc > 0 ) {
					$prod->set_sale_price( number_format( (float) $price - $disc, 2, '.', '' ) );
				}
			} else {
				$labels = array_map( function ( $v ) {
					return $v[0];
				}, $price );
				$a = new WC_Product_Attribute();
				$a->set_id( 0 );
				$a->set_name( $attr );
				$a->set_options( $labels );
				$a->set_position( 0 );
				$a->set_visible( true );
				$a->set_variation( true );
				$prod->set_attributes( [ $a ] );
			}
			$pid = $prod->save();
			if ( ! $pid ) {
				continue;
			}
			update_post_meta( $pid, '_nc_option_groups', $gids );
			update_post_meta( $pid, '_nc_demo', 1 );
			update_post_meta( $pid, '_nc_demo_key', 'prd-' . $slug );

			if ( $type === 'variable' ) {
				$attr_key = sanitize_title( $attr );
				foreach ( $price as $vi => $v ) {
					$var = new WC_Product_Variation();
					$var->set_parent_id( $pid );
					$var->set_attributes( [ $attr_key => $v[0] ] );
					$var->set_regular_price( number_format( (float) $v[1], 2, '.', '' ) );
					if ( $disc > 0 ) {
						$var->set_sale_price( number_format( (float) $v[1] - $disc, 2, '.', '' ) );
					}
					$var->set_menu_order( $vi );
					$var->set_status( 'publish' );
					$vid = $var->save();
					if ( $vid && ! empty( $v[2] ) && isset( $group_ids[ $v[2] ] ) ) {
						// own extras for this size (e.g. higher prices for the party pizza)
						update_post_meta( $vid, '_nc_option_groups', array_merge( [ $group_ids[ $v[2] ] ], isset( $group_ids['sauce'] ) ? [ $group_ids['sauce'] ] : [] ) );
					}
				}
				WC_Product_Variable::sync( $pid );
				wc_delete_product_transients( $pid );
			}

			// image
			$file = NC_PATH . 'assets/demo/' . $slug . '.jpg';
			if ( file_exists( $file ) ) {
				$tmp = wp_tempnam( $file );
				if ( $tmp && copy( $file, $tmp ) ) {
					$aid = media_handle_sideload( [ 'name' => $slug . '.jpg', 'tmp_name' => $tmp ], $pid, $name );
					if ( is_wp_error( $aid ) ) {
						@unlink( $tmp );
					} else {
						update_post_meta( $aid, '_nc_demo', 1 );
						set_post_thumbnail( $pid, $aid );
					}
				}
			}
			$made++;
		}

		if ( ! empty( $_POST['split'] ) ) {
			update_option( 'nc_price_mode', 'split' );
		}
		delete_transient( 'nc_menu_url' );
		wp_safe_redirect( NC_Admin::url( 'demo', [ 'demo' => 'imported', 'n' => $made ] ) );
		exit;
	}

	/* ---------- Delete ---------- */

	public static function delete() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'Nicht erlaubt.' );
		}
		check_admin_referer( 'nc_demo_delete' );
		@set_time_limit( 300 );
		$n = 0;
		foreach ( self::demo_products() as $pid ) {
			$prod = wc_get_product( $pid );
			if ( $prod ) {
				$prod->delete( true );
			} else {
				wp_delete_post( $pid, true );
			}
			$n++;
		}
		foreach ( self::demo_ids( 'attachment' ) as $aid ) {
			wp_delete_attachment( $aid, true );
		}
		foreach ( self::demo_ids( NC_Options::CPT ) as $gid ) {
			wp_delete_post( $gid, true );
		}
		$terms = get_terms( [ 'taxonomy' => 'product_cat', 'hide_empty' => false, 'meta_key' => '_nc_demo', 'meta_value' => '1' ] );
		if ( ! is_wp_error( $terms ) ) {
			foreach ( $terms as $t ) {
				wp_delete_term( $t->term_id, 'product_cat' );
			}
		}
		wp_safe_redirect( NC_Admin::url( 'demo', [ 'demo' => 'deleted', 'n' => $n ] ) );
		exit;
	}
}
