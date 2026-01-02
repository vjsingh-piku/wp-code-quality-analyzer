<?php
declare(strict_types=1);

/**
 * Plugin Name: WP Code Quality Analyzer
 * Description: Analyze WordPress code quality using PHPCS reports from GitHub Actions.
 * Version: 1.0.0
 * Author: Vijay Singh (Soluzione)
 * Text Domain: wcqa
 */

if (!defined('ABSPATH')) {
  exit;
}

/**
 * Minimal PSR-4-ish autoloader for WCQA\* classes in /src
 */
spl_autoload_register(static function (string $class): void {
  $prefix = 'WCQA\\';

  if (strpos($class, $prefix) !== 0) {
    return;
  }

  $relative = substr($class, strlen($prefix));
  $relative = str_replace('\\', '/', $relative);

  $path = plugin_dir_path(__FILE__) . 'src/' . $relative . '.php';

  if (is_readable($path)) {
    require_once $path;
  }
});

/**
 * Boot admin UI
 */
add_action('plugins_loaded', static function (): void {
  if (!is_admin()) {
    return;
  }

  if (class_exists('\WCQA\AdminPage')) {
    $admin = new \WCQA\AdminPage();
    $admin->register();
  }
});
