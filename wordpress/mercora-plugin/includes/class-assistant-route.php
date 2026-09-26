<?php
namespace Mercora;

defined( 'ABSPATH' ) || exit;

/**
 * Serves the dedicated /assistant route that hosts the Alice UI.
 */
final class Assistant_Route {

	public const QUERY_VAR = 'mercora_assistant';

	public static function init(): void {
		add_action( 'init', [ self::class, 'add_rewrite' ] );
		add_filter( 'query_vars', static fn( array $vars ) => [ ...$vars, self::QUERY_VAR ] );
		add_action( 'template_redirect', [ self::class, 'render' ], 0 );
	}

	public static function add_rewrite(): void {
		add_rewrite_rule( '^assistant/?$', 'index.php?' . self::QUERY_VAR . '=1', 'top' );
	}

	public static function render(): void {
		if ( ! get_query_var( self::QUERY_VAR ) ) {
			return;
		}

		nocache_headers();
		status_header( 200 );

		$config = [
			'aliceApi'   => (string) apply_filters( 'mercora_alice_api_base', '' ),
			'storeApi'   => esc_url_raw( rest_url( 'wc/store/v1' ) ),
			'mercoraApi' => esc_url_raw( rest_url( 'mercora/v1' ) ),
			'isLoggedIn' => is_user_logged_in(),
		];

		wp_enqueue_style( 'mercora-alice', MERCORA_URL . 'assets/alice.css', [], MERCORA_VERSION );
		wp_enqueue_script( 'mercora-alice', MERCORA_URL . 'assets/alice.js', [], MERCORA_VERSION, [ 'in_footer' => true, 'strategy' => 'defer' ] );
		wp_add_inline_script( 'mercora-alice', 'window.MERCORA = ' . wp_json_encode( $config ) . ';', 'before' );

		include MERCORA_PATH . 'templates/assistant.php';
		exit;
	}
}
