<?php
namespace Mercora;

defined( 'ABSPATH' ) || exit;

/**
 * Site-wide shadcn-style footer. Replaces the theme's footer template part
 * so it lives in version-controlled code rather than the Site Editor.
 */
final class Footer {

	public static function init(): void {
		add_filter( 'render_block_core/template-part', [ self::class, 'replace' ], 10, 2 );
		add_action( 'wp_enqueue_scripts', [ self::class, 'enqueue' ] );
	}

	public static function replace( string $content, array $block ): string {
		$slug = (string) ( $block['attrs']['slug'] ?? '' );
		$area = (string) ( $block['attrs']['area'] ?? '' );
		$tag  = (string) ( $block['attrs']['tagName'] ?? '' );
		if ( is_admin() || ! ( str_contains( $slug, 'footer' ) || 'footer' === $area || 'footer' === $tag ) ) {
			return $content;
		}
		return self::markup();
	}

	public static function enqueue(): void {
		if ( is_admin() ) {
			return;
		}
		wp_enqueue_style( 'mercora-geist', 'https://fonts.googleapis.com/css2?family=Geist:wght@400;500;600;700&display=swap', [], null );
		$ver = function_exists( 'mercora_asset_ver' ) ? mercora_asset_ver( 'assets/footer.css' ) : MERCORA_VERSION;
		wp_enqueue_style( 'mercora-footer', MERCORA_URL . 'assets/footer.css', [ 'mercora-geist' ], $ver );
	}

	private static function link( string $url, string $label ): string {
		return sprintf( '<li><a href="%s">%s</a></li>', esc_url( $url ), esc_html( $label ) );
	}

	private static function markup(): string {
		$has_wc = function_exists( 'wc_get_page_permalink' );

		// Top departments (by product count), excluding "Uncategorized".
		$shop_links = '';
		$terms      = get_terms( [
			'taxonomy'   => 'product_cat',
			'parent'     => 0,
			'hide_empty' => true,
			'orderby'    => 'count',
			'order'      => 'DESC',
			'number'     => 6,
			'exclude'    => [ (int) get_option( 'default_product_cat' ) ],
		] );
		if ( ! is_wp_error( $terms ) ) {
			foreach ( $terms as $t ) {
				$shop_links .= self::link( get_term_link( $t ), $t->name );
			}
		}
		if ( $has_wc ) {
			$shop_links .= self::link( wc_get_page_permalink( 'shop' ), __( 'All products', 'mercora' ) );
		}

		$account_links = $has_wc
			? self::link( wc_get_page_permalink( 'myaccount' ), __( 'My account', 'mercora' ) )
			. self::link( wc_get_account_endpoint_url( 'orders' ), __( 'Order history', 'mercora' ) )
			. self::link( wc_get_page_permalink( 'cart' ), __( 'Cart', 'mercora' ) )
			. self::link( wc_get_page_permalink( 'checkout' ), __( 'Checkout', 'mercora' ) )
			: '';

		$help_links = self::link( home_url( '/assistant/' ), __( 'Ask Alice', 'mercora' ) )
			. self::link( home_url( '/shop/?orderby=onsale' ), __( 'Today’s deals', 'mercora' ) )
			. self::link( 'mailto:' . antispambot( (string) get_option( 'admin_email' ) ), __( 'Contact us', 'mercora' ) );

		$name = get_bloginfo( 'name' );

		ob_start();
		?>
<footer class="mrc-footer" role="contentinfo">
	<div class="mrc-footer__container">
		<div class="mrc-footer__top">
			<div class="mrc-footer__brand">
				<a class="mrc-footer__logo" href="<?php echo esc_url( home_url( '/' ) ); ?>">
					<span class="mrc-footer__mark" aria-hidden="true">✦</span><?php echo esc_html( $name ); ?>
				</a>
				<p><?php esc_html_e( 'Shop by asking. Alice finds, compares and adds to cart, so you spend less time scrolling.', 'mercora' ); ?></p>
				<a class="mrc-footer__cta" href="<?php echo esc_url( home_url( '/assistant/' ) ); ?>"><?php esc_html_e( 'Ask Alice', 'mercora' ); ?> <span aria-hidden="true">→</span></a>
			</div>

			<nav class="mrc-footer__col" aria-label="<?php esc_attr_e( 'Shop', 'mercora' ); ?>">
				<h4><?php esc_html_e( 'Shop', 'mercora' ); ?></h4>
				<ul><?php echo $shop_links; // phpcs:ignore -- escaped in link() ?></ul>
			</nav>
			<nav class="mrc-footer__col" aria-label="<?php esc_attr_e( 'Account', 'mercora' ); ?>">
				<h4><?php esc_html_e( 'Account', 'mercora' ); ?></h4>
				<ul><?php echo $account_links; // phpcs:ignore ?></ul>
			</nav>
			<nav class="mrc-footer__col" aria-label="<?php esc_attr_e( 'Help', 'mercora' ); ?>">
				<h4><?php esc_html_e( 'Help', 'mercora' ); ?></h4>
				<ul><?php echo $help_links; // phpcs:ignore ?></ul>
			</nav>
		</div>

		<div class="mrc-footer__bottom">
			<span>© <?php echo esc_html( gmdate( 'Y' ) . ' ' . $name ); ?>. <?php esc_html_e( 'All rights reserved.', 'mercora' ); ?></span>
			<span class="mrc-footer__badge"><span class="mrc-footer__dot" aria-hidden="true"></span><?php esc_html_e( 'Powered by Alice', 'mercora' ); ?></span>
		</div>
	</div>
</footer>
		<?php
		return (string) ob_get_clean();
	}
}
