<?php
/**
 * Plugin Name: WP Code Quality Analyzer
 * Description: Analyze WordPress code quality using PHPCS reports from GitHub Actions.
 * Version: 1.0.0
 * Author: Your Name
 * Text Domain: wcqa
 */

if (!defined('ABSPATH')) {
  exit;
}

// Simple autoloader (if not using Composer autoload)
spl_autoload_register(function ($class) {
  if (strpos($class, 'WCQA\\') !== 0) {
    return;
  }

  $path = plugin_dir_path(__FILE__) . 'src/' .
          str_replace(['WCQA\\', '\\'], ['', '/'], $class) . '.php';

  if (file_exists($path)) {
    require_once $path;
  }
});

// Register Admin Page
add_action('plugins_loaded', function () {
  if (is_admin()) {
    $admin = new \WCQA\AdminPage();
    $admin->register();
  }
});
