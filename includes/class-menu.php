<?php
defined( 'ABSPATH' ) || exit;

/**
 * Shortcode [nimcode_menu] (optional: checkout="/kasse/")
 * Server-rendered menu (fast, SEO-friendly) + one JSON block for the JS app.
 */
class NC_Menu {

	public static function init() {
		add_shortcode( 'nimcode_menu', [ __CLASS__, 'render' ] );
		add_action( 'wp_enqueue_scripts', [ __CLASS__, 'assets' ] );
	}

	public static function assets() {
		$post = get_post();
		if ( ! $post || ! has_shortcode( $post->post_content, 'nimcode_menu' ) ) {
			return;
		}
		wp_enqueue_style( 'nc-app', NC_URL . 'assets/app.css', [], NC_VERSION );
		wp_add_inline_style( 'nc-app', ':root{--nc-accent:' . NC_Admin::accent() . ';--nc-width:' . NC_Admin::width() . 'vw}' );
		wp_enqueue_script( 'nc-app', NC_URL . 'assets/app.js', [], NC_VERSION, [ 'in_footer' => true, 'strategy' => 'defer' ] );
	}

	private static function price_text( $price ) {
		return html_entity_decode( wp_strip_all_tags( wc_price( $price ) ), ENT_QUOTES, 'UTF-8' );
	}

	/** [delivery price, pickup price] as the customer sees them (tax display as configured). */
	private static function prices( $product ) {
		$out = [];
		foreach ( [ 'delivery', 'pickup' ] as $type ) {
			$raw   = NC_Cart::price_for( $product, $type ) ?: (float) $product->get_price();
			$out[] = (float) wc_get_price_to_display( $product, [ 'price' => $raw ] );
		}
		return $out;
	}

	private static function compact_groups( $groups ) {
		$out = [];
		foreach ( $groups as $g ) {
			$out[] = [
				'id'   => $g['id'],
				't'    => $g['title'],
				'type' => $g['type'],
				'req'  => (int) $g['required'],
				'max'  => (int) $g['max'],
				'o'    => array_map( function ( $o ) {
					return [ 'n' => $o['name'], 'p' => (float) $o['price'] ];
				}, $g['options'] ),
			];
		}
		return $out;
	}

	/** Variations as a flat list. Variations with an "any" attribute are skipped. */
	private static function variations( $product ) {
		$out = [];
		foreach ( $product->get_children() as $vid ) {
			$v = wc_get_product( $vid );
			if ( ! $v || ! $v->is_purchasable() || ! $v->is_in_stock() ) {
				continue;
			}
			$parts = [];
			foreach ( $v->get_variation_attributes() as $key => $val ) {
				if ( $val === '' ) {
					continue 2;
				}
				$tax     = substr( $key, 10 ); // strip "attribute_"
				$term    = taxonomy_exists( $tax ) ? get_term_by( 'slug', $val, $tax ) : false;
				$parts[] = $term ? $term->name : $val;
			}
			list( $pd, $pp ) = self::prices( $v );
			$out[] = [
				'id' => (int) $vid,
				'n'  => implode( ' / ', $parts ),
				'p'  => $pd,
				'pp' => $pp,
				'g'  => self::compact_groups( NC_Options::get_for_product( (int) $product->get_id(), (int) $vid ) ),
			];
		}
		return $out;
	}

	/**
	 * Product categories in the order set under "Speisekarte" (then WooCommerce order, then name).
	 * Returns [ ['term' => WP_Term, 'show' => bool], ... ]
	 */
	public static function categories( $admin = false ) {
		$terms = get_terms( [
			'taxonomy'   => 'product_cat',
			'hide_empty' => ! $admin,
			'exclude'    => [ (int) get_option( 'default_product_cat' ) ],
		] );
		if ( is_wp_error( $terms ) || ! $terms ) {
			return [];
		}
		$pos  = [];
		$hide = [];
		foreach ( (array) get_option( 'nc_cats', [] ) as $i => $c ) {
			$id         = (int) ( $c['id'] ?? 0 );
			$pos[ $id ] = $i;
			if ( empty( $c['show'] ) ) {
				$hide[ $id ] = true;
			}
		}
		usort( $terms, function ( $a, $b ) use ( $pos ) {
			$pa = $pos[ $a->term_id ] ?? PHP_INT_MAX;
			$pb = $pos[ $b->term_id ] ?? PHP_INT_MAX;
			if ( $pa !== $pb ) {
				return $pa <=> $pb;
			}
			$oa = (int) get_term_meta( $a->term_id, 'order', true );
			$ob = (int) get_term_meta( $b->term_id, 'order', true );
			if ( $oa !== $ob ) {
				return $oa <=> $ob;
			}
			return strcasecmp( $a->name, $b->name );
		} );
		$out = [];
		foreach ( $terms as $t ) {
			$out[] = [ 'term' => $t, 'show' => empty( $hide[ $t->term_id ] ) ];
		}
		return $out;
	}

	public static function render( $atts ) {
		$atts     = shortcode_atts( [ 'checkout' => '' ], $atts, 'nimcode_menu' );
		$checkout = $atts['checkout'] ? esc_url_raw( $atts['checkout'] ) : ( NC_Checkout::page_url() ?: wc_get_checkout_url() );

		$cats = self::categories();
		if ( ! $cats ) {
			return '<p>Keine Produkte vorhanden.</p>';
		}

		$products_data = [];
		$nav           = '';
		$sections      = '';

		foreach ( $cats as $c ) {
			if ( ! $c['show'] ) {
				continue;
			}
			$term     = $c['term'];
			$products = wc_get_products( [
				'status'   => 'publish',
				'type'     => [ 'simple', 'variable' ],
				'limit'    => -1,
				'category' => [ $term->slug ],
				'orderby'  => 'menu_order',
				'order'    => 'ASC',
			] );

			$cards = '';
			foreach ( $products as $product ) {
				if ( ! $product->is_purchasable() || ! $product->is_in_stock() ) {
					continue;
				}
				$id     = $product->get_id();
				$is_var = $product->is_type( 'variable' );
				$vars   = [];
				if ( $is_var ) {
					$vars = self::variations( $product );
					if ( ! $vars ) {
						continue;
					}
					$pd = min( wp_list_pluck( $vars, 'p' ) );
					$pp = min( wp_list_pluck( $vars, 'pp' ) );
				} else {
					list( $pd, $pp ) = self::prices( $product );
				}

				$groups = $is_var ? [] : self::compact_groups( NC_Options::get_for_product( $id ) );
				$products_data[ $id ] = [ 'n' => $product->get_name(), 'p' => $pd, 'pp' => $pp, 'g' => $groups ];
				if ( $is_var ) {
					$products_data[ $id ]['v']  = $vars;
					$products_data[ $id ]['vl'] = implode( ' / ', array_map( function ( $n ) use ( $product ) {
						return wc_attribute_label( $n, $product );
					}, array_keys( $product->get_variation_attributes() ) ) );
				}

				$desc = wp_trim_words( wp_strip_all_tags( $product->get_short_description() ?: $product->get_description() ), 22 );
				$img  = $product->get_image_id()
					? wp_get_attachment_image( $product->get_image_id(), 'medium', false, [ 'loading' => 'lazy', 'class' => 'nc-img' ] )
					: '';

				$na    = ! NC_Avail::is_now( $id );
				$badge = NC_Avail::has( $id ) ? '<span class="nc-avail">' . esc_html( NC_Avail::text( $id ) ) . '</span>' : '';
				$cards .= sprintf(
					'<article class="nc-card' . ( $na ? ' nc-na' : '' ) . '"><div class="nc-info"><h3>%1$s</h3>%2$s<span class="nc-price" data-id="%5$d">%3$s</span><small class="nc-alt"></small>' . $badge . '</div>%4$s<button type="button" class="nc-add" data-id="%5$d" aria-label="%6$s">+</button></article>',
					esc_html( $product->get_name() ),
					$desc ? '<p class="nc-desc">' . esc_html( $desc ) . '</p>' : '',
					esc_html( ( $is_var ? 'ab ' : '' ) . self::price_text( $pd ) ),
					$img,
					$id,
					esc_attr( 'Hinzufügen: ' . $product->get_name() )
				);
			}

			if ( $cards === '' ) {
				continue;
			}
			$slug      = sanitize_title( $term->slug );
			$nav      .= sprintf( '<a href="#nc-cat-%1$s">%2$s</a>', esc_attr( $slug ), esc_html( $term->name ) );
			$sections .= sprintf( '<section class="nc-cat" id="nc-cat-%1$s"><h2>%2$s</h2><div class="nc-grid">%3$s</div></section>', esc_attr( $slug ), esc_html( $term->name ), $cards );
		}

		$data = [
			'rest'     => esc_url_raw( rest_url( 'nimcode/v1/' ) ),
			'nonce'    => is_user_logged_in() ? wp_create_nonce( 'wp_rest' ) : '',
			'paused'   => NC_Hours::paused() ? NC_Hours::paused_msg() : '',
			'cur'      => get_woocommerce_currency(),
			'checkout' => $checkout,
			'products' => (object) $products_data,
			'restaurant' => NC_Checkout::restaurant(),
		];

		$banner = NC_Hours::paused() ? '<div class="nc-paused" role="status"><strong>Bestellungen pausiert</strong><br>' . esc_html( NC_Hours::paused_msg() ) . '</div>' : '';
		return '<div class="nc-app' . ( $banner ? ' nc-closed-app' : '' ) . '">' . $banner . '<div class="nc-navwrap"><button type="button" class="nc-navbtn" data-dir="-1" aria-label="Kategorien nach links" hidden>‹</button><nav class="nc-nav" aria-label="Kategorien">' . $nav . '</nav><button type="button" class="nc-navbtn" data-dir="1" aria-label="Kategorien nach rechts" hidden>›</button></div><div class="nc-menu">' . $sections . '</div>'
			. '<script type="application/json" id="nc-data">' . wp_json_encode( $data, JSON_HEX_TAG | JSON_HEX_AMP ) . '</script></div>';
	}
}
