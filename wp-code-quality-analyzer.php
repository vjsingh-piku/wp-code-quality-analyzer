<?php
/**
 * Plugin Name:       WP Code Quality Analyzer
 * Plugin URI:        https://github.com/vjsingh-piku/wp-code-quality-analyzer
 * Description:       Shows PHPCS code-quality reports generated via GitHub Actions inside the WordPress admin.
 * Version:           1.0.0
 * Author:            Vijay Singh (Soluzione)
 * Text Domain:       wcqa
 * Requires at least: 6.0
 * Requires PHP:      7.4
 *
 * @package WCQA
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Simple autoloader (no Composer on shared hosting).
 *
 * @param string $class Class name.
 * @return void
 */
spl_autoload_register(
	function ( string $class ): void {
		if ( 0 !== strpos( $class, 'WCQA\\' ) ) {
			return;
		}

		$relative = str_replace( array( 'WCQA\\', '\\' ), array( '', '/' ), $class );
		$path     = plugin_dir_path( __FILE__ ) . 'src/' . $relative . '.php';

		if ( file_exists( $path ) ) {
			require_once $path;
		}
	}
);

add_action(
	'plugins_loaded',
	function (): void {
		if ( is_admin() && class_exists( '\WCQA\AdminPage' ) ) {
			$admin = new \WCQA\AdminPage();
			$admin->register();
		}
	}
);
