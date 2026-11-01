<?php
/**
 * The isolated app shell (/dash). Theme templates are never used here.
 *
 * @package Cliezoo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?><!DOCTYPE html>
<html <?php language_attributes(); ?> class="cliezoo-html">
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="color-scheme" content="light dark">
<?php wp_head(); ?>
</head>
<body <?php body_class( 'cliezoo-app cliezoo-shell-app' ); ?>>
<?php wp_body_open(); ?>
<div id="cliezoo-root" class="cliezoo-root" aria-busy="true">
	<div class="cliezoo-boot" role="status">
		<span class="cliezoo-boot__mark" aria-hidden="true"></span>
		<span class="cliezoo-boot__text"><?php esc_html_e( 'Loading…', 'cliezoo' ); ?></span>
	</div>
	<noscript><p class="cliezoo-boot__noscript"><?php esc_html_e( 'Please turn on JavaScript to use this app.', 'cliezoo' ); ?></p></noscript>
</div>
<?php wp_footer(); ?>
</body>
</html>
