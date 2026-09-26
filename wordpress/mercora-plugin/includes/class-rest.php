<?php
namespace Mercora;

defined( 'ABSPATH' ) || exit;

/**
 * Mercora REST namespace. Phase 1: health check only.
 */
final class Rest {

	public static function init(): void {
		add_action( 'rest_api_init', [ self::class, 'register' ] );
	}

	public static function register(): void {
		register_rest_route( 'mercora/v1', '/health', [
			'methods'             => 'GET',
			'permission_callback' => '__return_true',
			'callback'            => static fn() => rest_ensure_response( [
				'status'      => 'ok',
				'mercora'     => MERCORA_VERSION,
				'wordpress'   => get_bloginfo( 'version' ),
				'woocommerce' => defined( 'WC_VERSION' ) ? WC_VERSION : null,
				'currency'    => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : null,
			] ),
		] );
	}
}
