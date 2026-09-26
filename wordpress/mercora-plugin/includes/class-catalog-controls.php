<?php
namespace Mercora;

defined( 'ABSPATH' ) || exit;

/**
 * Shop page controls:
 * - extra sort options in WooCommerce's "Sort by" dropdown
 * - a category dropdown next to it (keeps the current sort when switching)
 */
final class Catalog_Controls {

	/** Keys must not contain "-" except for WooCommerce's built-in "field-direction" format. */
	private const CUSTOM_SORTS = [
		'title'      => 'Name: A to Z',
		'title-desc' => 'Name: Z to A',
		'instock'    => 'In stock first',
		'onsale'     => 'On sale first',
	];

	private static bool $rendered = false;

	public static function init(): void {
		add_filter( 'woocommerce_catalog_orderby', [ self::class, 'sort_options' ] );
		add_filter( 'woocommerce_default_catalog_orderby_options', [ self::class, 'sort_options' ] );
		add_filter( 'posts_clauses', [ self::class, 'sort_clauses' ], 20, 2 );

		// Block themes: attach the category dropdown to the "Catalog Sorting" block.
		add_filter( 'render_block_woocommerce/catalog-sorting', [ self::class, 'wrap_sorting_block' ] );
		// Classic themes: render before WooCommerce's own sorting dropdown.
		add_action( 'woocommerce_before_shop_loop', [ self::class, 'render_classic' ], 29 );

		add_action( 'wp_enqueue_scripts', [ self::class, 'enqueue' ] );
	}

	/* ---------------- Sorting ---------------- */

	public static function sort_options( array $options ): array {
		foreach ( self::CUSTOM_SORTS as $key => $label ) {
			$options[ $key ] = __( $label, 'mercora' );
		}
		return $options;
	}

	private static function requested_sort(): string {
		return isset( $_GET['orderby'] ) ? wc_clean( wp_unslash( $_GET['orderby'] ) ) : '';
	}

	/**
	 * "title" / "title-desc" are handled natively by WooCommerce.
	 * "instock" / "onsale" are implemented here using WooCommerce's product lookup table.
	 */
	public static function sort_clauses( array $clauses, \WP_Query $query ): array {
		$sort = self::requested_sort();
		if ( is_admin() || ! in_array( $sort, [ 'instock', 'onsale' ], true ) || ! self::is_product_query( $query ) ) {
			return $clauses;
		}

		global $wpdb;
		$clauses['join'] .= " LEFT JOIN {$wpdb->wc_product_meta_lookup} mrc_l ON {$wpdb->posts}.ID = mrc_l.product_id ";
		$clauses['orderby'] = 'instock' === $sort
			? "FIELD(mrc_l.stock_status, 'instock', 'onbackorder', 'outofstock') ASC, mrc_l.total_sales DESC, {$wpdb->posts}.ID DESC"
			: "mrc_l.onsale DESC, mrc_l.total_sales DESC, {$wpdb->posts}.ID DESC";

		return $clauses;
	}

	private static function is_product_query( \WP_Query $q ): bool {
		return in_array( 'product', (array) $q->get( 'post_type' ), true )
			|| $q->is_post_type_archive( 'product' )
			|| $q->is_tax( get_object_taxonomies( 'product' ) );
	}

	/* ---------------- Category dropdown ---------------- */

	public static function category_dropdown(): string {
		$terms = get_terms( [ 'taxonomy' => 'product_cat', 'hide_empty' => true, 'orderby' => 'name' ] );
		if ( is_wp_error( $terms ) || ! $terms ) {
			return '';
		}

		$children = [];
		foreach ( $terms as $t ) {
			$children[ $t->parent ][] = $t;
		}

		$current = is_product_category() ? (int) get_queried_object_id() : 0;
		$keep    = array_filter( [ 'orderby' => self::requested_sort() ] );
		$shop    = get_permalink( wc_get_page_id( 'shop' ) );

		$html = sprintf( '<option value="%s">%s</option>', esc_url( add_query_arg( $keep, $shop ) ), esc_html__( 'All categories', 'mercora' ) );
		foreach ( $children[0] ?? [] as $parent ) {
			$html .= self::option( $parent, $current, $keep, '' );
			foreach ( $children[ $parent->term_id ] ?? [] as $child ) {
				$html .= self::option( $child, $current, $keep, '— ' );
			}
		}

		return '<div class="mercora-cat-filter">'
			. '<label class="screen-reader-text" for="mercora-cat">' . esc_html__( 'Category', 'mercora' ) . '</label>'
			. '<select id="mercora-cat" onchange="window.location.href=this.value">' . $html . '</select>'
			. '</div>';
	}

	private static function option( \WP_Term $t, int $current, array $keep, string $prefix ): string {
		return sprintf(
			'<option value="%s"%s>%s%s (%d)</option>',
			esc_url( add_query_arg( $keep, get_term_link( $t ) ) ),
			selected( $t->term_id, $current, false ),
			$prefix,
			esc_html( $t->name ),
			(int) $t->count
		);
	}

	public static function wrap_sorting_block( string $content ): string {
		if ( self::$rendered ) {
			return $content;
		}
		self::$rendered = true;
		return '<div class="mercora-catalog-controls">' . self::category_dropdown() . $content . '</div>';
	}

	public static function render_classic(): void {
		if ( self::$rendered || wp_is_block_theme() ) {
			return;
		}
		self::$rendered = true;
		echo self::category_dropdown(); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped above
	}

	public static function enqueue(): void {
		if ( ! ( is_shop() || is_product_taxonomy() ) ) {
			return;
		}
		$ver = function_exists( 'mercora_asset_ver' ) ? mercora_asset_ver( 'assets/catalog.css' ) : MERCORA_VERSION;
		wp_enqueue_style( 'mercora-catalog', MERCORA_URL . 'assets/catalog.css', [], $ver );
	}
}
