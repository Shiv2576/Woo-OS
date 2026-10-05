<?php defined("ABSPATH") || exit(); ?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo("charset"); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1, interactive-widget=resizes-content">
	<title>Alice — <?php echo esc_html(get_bloginfo("name")); ?></title>
	<?php wp_head(); ?>
</head>
<body <?php body_class("mercora-assistant"); ?>>
<?php wp_body_open(); ?>
<div class="alice">

	<header class="alice__bar">
		<a class="alice__home" href="<?php echo esc_url(
      home_url("/"),
  ); ?>">&larr; <?php echo esc_html(get_bloginfo("name")); ?></a>
		<span class="alice__title">Alice</span>
		<span class="alice__status" id="alice-status">Checking store…</span>
		<button type="button" class="alice__new" id="alice-new">New chat</button>
	</header>

	<main class="alice__thread" id="alice-scroll">
		<div class="alice__thread-inner" id="alice-thread" aria-live="polite">
			<div class="alice__msg alice__msg--assistant">Hi, I'm Alice. Ask me to find products, compare options, or manage your cart.</div>
		</div>
	</main>

	<div class="alice__dock">
		<form class="alice__composer" id="alice-composer" autocomplete="off">
			<textarea id="alice-input" rows="1" placeholder="Ask Alice anything…" aria-label="Message Alice"></textarea>
			<button type="submit" class="alice__send" id="alice-send" aria-label="Send" disabled>
				<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 19V5M5 12l7-7 7 7"/></svg>
			</button>
		</form>
		<p class="alice__hint">Enter to send · Shift + Enter for a new line</p>
	</div>

</div>
<?php wp_footer(); ?>
</body>
</html>
