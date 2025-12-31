<?php
/**
 * Plugin Name: WP Code Quality Analyzer
 * Description: Run PHPCS + WordPress Coding Standards scans for themes/plugins from WP Admin.
 * Version: 1.0.0
 * Author: Your Name
 */

if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/vendor/autoload.php';

use WCQA\AdminPage;

add_action('plugins_loaded', function () {
  (new AdminPage())->register();
});
