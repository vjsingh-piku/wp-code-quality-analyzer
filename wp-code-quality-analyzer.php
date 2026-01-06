<?php
/**
 * Plugin Name: WP Code Quality Analyzer
 * Description: Monitor code quality of themes/plugins via PHPCS reports generated in GitHub Actions.
 * Version: 1.0.0
 * Author: Soluzione
 * Text Domain: wcqa
 */

if ( ! defined('ABSPATH') ) {
	exit;
}

spl_autoload_register(function ($class) {
	if (strpos($class, 'WCQA\\') !== 0) {
		return;
	}
	$path = plugin_dir_path(__FILE__) . 'src/' . str_replace(['WCQA\\', '\\'], ['', '/'], $class) . '.php';
	if (file_exists($path)) {
		require_once $path;
	}
});

add_action('plugins_loaded', function () {
	if (is_admin()) {
		$admin = new \WCQA\AdminPage();
		$admin->register();
	}
});
