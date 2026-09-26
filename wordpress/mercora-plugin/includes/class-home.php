<?php
namespace Mercora;

defined( 'ABSPATH' ) || exit;

/**
 * Front-page assets (shadcn-style homepage).
 */
final class Home {

	public static function init(): void {
		add_action( 'wp_enqueue_scripts', [ self::class, 'enqueue' ] );
	}

	public static function enqueue(): void {
		if ( ! is_front_page() ) {
			return;
		}

		wp_enqueue_style( 'mercora-geist', 'https://fonts.googleapis.com/css2?family=Geist:wght@400;500;600;700&display=swap', [], null );
		$ver = function_exists( 'mercora_asset_ver' ) ? mercora_asset_ver( 'assets/home.css' ) : MERCORA_VERSION;
		wp_enqueue_style( 'mercora-home', MERCORA_URL . 'assets/home.css', [ 'mercora-geist' ], $ver );

		// Measure the real header height so the hero can slide exactly underneath it.
		wp_register_script( 'mercora-home', false, [], $ver, [ 'in_footer' => true ] );
		wp_enqueue_script( 'mercora-home' );
		wp_add_inline_script( 'mercora-home', <<<'JS'
(function () {
  function sync() {
    var h = document.querySelector('.wp-site-blocks > header, header.wp-block-template-part, #masthead, body > header');
    if (!h) return;
    document.documentElement.style.setProperty('--mrc-header-h', Math.round(h.getBoundingClientRect().height) + 'px');
  }
  sync();
  window.addEventListener('load', sync);     // after fonts/images settle
  window.addEventListener('resize', sync);
  if (document.fonts && document.fonts.ready) document.fonts.ready.then(sync);
})();
JS
		);
	}
}
