<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders Tabler outline icons (https://tabler.io/icons, MIT licensed) as
 * inline SVG for the admin UI. The icon set is bundled locally in
 * wcops-icon-data.php – a fixed, known list of names this plugin actually
 * uses – so icons never depend on a remote request at runtime.
 *
 * The rendered <svg> keeps the literal `dashicons` class alongside whatever
 * class the caller passes through, so every existing CSS rule written
 * against `.dashicons` (sizing, color tinting, `.some-wrapper .dashicons`
 * overrides) keeps applying unchanged, with no stylesheet rewrite needed –
 * `stroke` is `currentColor`, which follows the element's `color` the same
 * way a dashicon glyph did. `dashicons` here is purely a sizing/box-model
 * hook (WordPress core's own `.dashicons` rule); no dashicons webfont glyph
 * is selected, since we never include a `dashicons-{name}` class.
 */
class WCOPS_Icons {

	private static $map = null;

	private static function map() {
		if ( null === self::$map ) {
			self::$map = require WCOPS_PLUGIN_DIR . 'includes/wcops-icon-data.php';
		}
		return self::$map;
	}

	/**
	 * Echo an icon by name. $class is applied to the <svg> element itself.
	 */
	public static function render( $name, $class = '' ) {
		echo self::get( $name, $class ); // phpcs:ignore WordPress.Security.EscapeOutput -- built from a trusted, bundled path map plus esc_attr()'d class.
	}

	/**
	 * Same as render(), but returns the markup instead of printing it –
	 * for use inside an already-buffered string (e.g. a button label).
	 */
	public static function get( $name, $class = '' ) {
		$map = self::map();
		if ( empty( $map[ $name ] ) ) {
			return '';
		}
		return sprintf(
			'<svg class="dashicons wcops-icon%s" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">%s</svg>',
			$class ? ' ' . esc_attr( $class ) : '',
			$map[ $name ]
		);
	}
}
