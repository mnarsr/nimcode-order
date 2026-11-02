<?php
defined( 'ABSPATH' ) || exit;

/**
 * Option groups (extras, sizes, sauces) as a custom post type.
 * Each group is stored once and can be assigned to many products.
 * Group data lives in post meta "_nc_group" (one array).
 */
class NC_Options {

	const CPT = 'nc_option_group';

	public static function init() {
		add_action( 'init', [ __CLASS__, 'register_cpt' ] );
		add_action( 'add_meta_boxes', [ __CLASS__, 'add_metabox' ] );
		add_action( 'save_post_' . self::CPT, [ __CLASS__, 'save_group' ] );
		add_action( 'woocommerce_product_options_general_product_data', [ __CLASS__, 'product_field' ] );
		add_action( 'woocommerce_process_product_meta', [ __CLASS__, 'save_product' ] );
		add_action( 'woocommerce_product_after_variable_attributes', [ __CLASS__, 'variation_field' ], 10, 3 );
		add_action( 'woocommerce_save_product_variation', [ __CLASS__, 'save_variation' ], 10, 2 );
	}

	public static function register_cpt() {
		register_post_type( self::CPT, [
			'labels'      => [
				'name'          => 'Optionsgruppen',
				'singular_name' => 'Optionsgruppe',
				'add_new'       => 'Neue Gruppe',
				'add_new_item'  => 'Neue Optionsgruppe',
				'edit_item'     => 'Optionsgruppe bearbeiten',
				'menu_name'     => 'Optionsgruppen',
				'all_items'     => 'Optionsgruppen',
			],
			'public'      => false,
			'show_ui'     => true,
			'show_in_menu' => 'nc-dashboard',
			'supports'    => [ 'title' ],
			'menu_position' => 56,
		] );
	}

	/* ---------- Data access ---------- */

	public static function get_group( $id ) {
		$g = get_post_meta( $id, '_nc_group', true );
		return wp_parse_args( is_array( $g ) ? $g : [], [
			'type'     => 'single', // single | multi
			'required' => 0,
			'max'      => 0,
			'options'  => [],
		] );
	}

	/** All groups assigned to a product, ready for the frontend (JSON). */
	public static function get_for_product( $product_id, $variation_id = 0 ) {
		$ids = [];
		if ( $variation_id ) {
			$ids = array_filter( array_map( 'absint', (array) get_post_meta( $variation_id, '_nc_option_groups', true ) ) );
		}
		if ( ! $ids ) { // no own groups on the variation: use the ones of the main product
			$ids = (array) get_post_meta( $product_id, '_nc_option_groups', true );
		}
		$out = [];
		foreach ( array_filter( array_map( 'absint', $ids ) ) as $gid ) {
			$post = get_post( $gid );
			if ( ! $post || $post->post_type !== self::CPT || $post->post_status !== 'publish' ) {
				continue;
			}
			$out[] = [ 'id' => $gid, 'title' => $post->post_title ] + self::get_group( $gid );
		}
		return $out;
	}

	/* ---------- Group editor ---------- */

	public static function add_metabox() {
		add_meta_box( 'nc_group', 'Einstellungen und Optionen', [ __CLASS__, 'render_metabox' ], self::CPT, 'normal', 'high' );
	}

	private static function row( $i, $name = '', $price = '' ) {
		printf(
			'<tr><td><input type="text" class="widefat" name="nc_opt[%1$s][name]" value="%2$s" placeholder="z. B. Extra Käse"></td>
			<td><input type="text" style="width:100px" name="nc_opt[%1$s][price]" value="%3$s" placeholder="0,00"></td>
			<td><button type="button" class="button nc-del">&times;</button></td></tr>',
			esc_attr( $i ), esc_attr( $name ), esc_attr( $price )
		);
	}

	public static function render_metabox( $post ) {
		$g = self::get_group( $post->ID );
		wp_nonce_field( 'nc_group', 'nc_group_nonce' );
		?>
		<p>
			<label>Auswahl:
				<select name="nc_type">
					<option value="single" <?php selected( $g['type'], 'single' ); ?>>Eine Option (z. B. Größe)</option>
					<option value="multi" <?php selected( $g['type'], 'multi' ); ?>>Mehrere Optionen (z. B. Extras)</option>
				</select>
			</label>
			&nbsp; <label><input type="checkbox" name="nc_required" value="1" <?php checked( $g['required'], 1 ); ?>> Pflichtauswahl</label>
			&nbsp; <label>Max. Anzahl (0 = unbegrenzt)
				<input type="number" name="nc_max" min="0" style="width:70px" value="<?php echo esc_attr( $g['max'] ); ?>"></label>
		</p>
		<table class="widefat striped">
			<thead><tr><th>Name</th><th>Aufpreis (&euro;)</th><th></th></tr></thead>
			<tbody id="nc-rows">
				<?php
				foreach ( $g['options'] as $i => $o ) {
					self::row( $i, $o['name'], number_format( (float) $o['price'], 2, ',', '' ) );
				}
				?>
			</tbody>
		</table>
		<p><button type="button" class="button button-primary" id="nc-add">+ Option hinzufügen</button></p>
		<template id="nc-tpl"><?php self::row( '__i__' ); ?></template>
		<script>
		(function () {
			var rows = document.getElementById('nc-rows'), n = Date.now();
			document.getElementById('nc-add').onclick = function () {
				rows.insertAdjacentHTML('beforeend',
					document.getElementById('nc-tpl').innerHTML.replace(/__i__/g, n++));
			};
			rows.addEventListener('click', function (e) {
				if (e.target.classList.contains('nc-del')) e.target.closest('tr').remove();
			});
		})();
		</script>
		<?php
	}

	public static function save_group( $post_id ) {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! isset( $_POST['nc_group_nonce'] ) || ! wp_verify_nonce( $_POST['nc_group_nonce'], 'nc_group' ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$options = [];
		foreach ( (array) ( $_POST['nc_opt'] ?? [] ) as $o ) {
			$name = sanitize_text_field( wp_unslash( $o['name'] ?? '' ) );
			if ( $name === '' ) {
				continue;
			}
			$price     = (float) str_replace( ',', '.', wp_unslash( $o['price'] ?? '0' ) );
			$options[] = [ 'name' => $name, 'price' => round( max( 0, $price ), 2 ) ];
		}

		update_post_meta( $post_id, '_nc_group', [
			'type'     => ( $_POST['nc_type'] ?? '' ) === 'multi' ? 'multi' : 'single',
			'required' => empty( $_POST['nc_required'] ) ? 0 : 1,
			'max'      => absint( $_POST['nc_max'] ?? 0 ),
			'options'  => $options,
		] );
	}

	/* ---------- Product field (WooCommerce > Product > General) ---------- */

	public static function product_field() {
		global $post;
		$groups   = get_posts( [ 'post_type' => self::CPT, 'numberposts' => -1, 'orderby' => 'title', 'order' => 'ASC' ] );
		$selected = array_map( 'absint', (array) get_post_meta( $post->ID, '_nc_option_groups', true ) );
		echo '<div class="options_group show_if_simple show_if_variable"><p class="form-field"><label for="nc_groups">Optionsgruppen</label>';
		echo '<select id="nc_groups" name="nc_groups[]" multiple class="wc-enhanced-select" style="width:50%">';
		foreach ( $groups as $g ) {
			printf( '<option value="%d"%s>%s</option>', $g->ID, selected( in_array( $g->ID, $selected, true ), true, false ), esc_html( $g->post_title ) );
		}
		echo '</select></p></div>';
	}

	public static function save_product( $post_id ) {
		// WooCommerce already verifies the nonce for the product form.
		$ids = isset( $_POST['nc_groups'] ) ? array_map( 'absint', (array) $_POST['nc_groups'] ) : [];
		update_post_meta( $post_id, '_nc_option_groups', $ids );
	}

	/* ---------- Variation field (Product > Variations) ---------- */

	public static function variation_field( $loop, $variation_data, $variation ) {
		$groups   = get_posts( [ 'post_type' => self::CPT, 'numberposts' => -1, 'orderby' => 'title', 'order' => 'ASC' ] );
		$selected = array_map( 'absint', (array) get_post_meta( $variation->ID, '_nc_option_groups', true ) );
		echo '<div class="form-row form-row-full"><label>Optionsgruppen <small>(leer = wie beim Hauptprodukt)</small></label>';
		printf( '<select name="nc_vgroups[%d][]" multiple class="wc-enhanced-select" style="width:100%%">', (int) $loop );
		foreach ( $groups as $g ) {
			printf( '<option value="%d"%s>%s</option>', $g->ID, selected( in_array( $g->ID, $selected, true ), true, false ), esc_html( $g->post_title ) );
		}
		echo '</select></div>';
	}

	public static function save_variation( $variation_id, $i ) {
		// WooCommerce already verifies the nonce for variation saving.
		$ids = isset( $_POST['nc_vgroups'][ $i ] ) ? array_map( 'absint', (array) $_POST['nc_vgroups'][ $i ] ) : [];
		update_post_meta( $variation_id, '_nc_option_groups', $ids );
	}
}
