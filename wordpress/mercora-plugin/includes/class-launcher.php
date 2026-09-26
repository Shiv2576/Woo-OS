<?php
namespace Mercora;

defined( 'ABSPATH' ) || exit;

/**
 * Entry points into Alice from the normal storefront:
 * - a floating "Ask Alice" button on every storefront page
 * - an [alice_button] shortcode for placing a button anywhere
 */
final class Launcher {

	public static function init(): void {
		add_action( 'wp_enqueue_scripts', [ self::class, 'enqueue' ] );
		add_action( 'wp_footer', [ self::class, 'render_floating' ] );
		add_shortcode( 'alice_button', [ self::class, 'shortcode' ] );
	}

	public static function url(): string {
		return home_url( '/assistant/' );
	}

	private static function on_storefront(): bool {
		return ! is_admin() && ! get_query_var( Assistant_Route::QUERY_VAR );
	}

	public static function enqueue(): void {
		if ( self::on_storefront() ) {
			wp_enqueue_style( 'mercora-launcher', MERCORA_URL . 'assets/launcher.css', [], MERCORA_VERSION );
		}
	}

	public static function render_floating(): void {
		if ( ! self::on_storefront() || ! apply_filters( 'mercora_show_floating_launcher', true ) ) {
			return;
		}
		printf(
			'<a class="mercora-launcher" href="%s" aria-label="%s"><span aria-hidden="true">✦</span> %s</a>',
			esc_url( self::url() ),
			esc_attr__( 'Open Alice shopping assistant', 'mercora' ),
			esc_html__( 'Ask Alice', 'mercora' )
		);
	}

	public static function shortcode( $atts ): string {
		$a = shortcode_atts( [ 'label' => __( 'Ask Alice', 'mercora' ) ], $atts, 'alice_button' );
		return sprintf(
			'<a class="mercora-alice-button" href="%s">%s</a>',
			esc_url( self::url() ),
			esc_html( $a['label'] )
		);
	}
}
