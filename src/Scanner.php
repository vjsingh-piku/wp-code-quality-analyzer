<?php
namespace WCQA;

class Scanner {

  private function phpcs_path(): string {
    return plugin_dir_path(__DIR__) . 'vendor/bin/phpcs';
  }

  private function validate_target(string $path): string {
    $real = realpath($path);
    if (!$real) throw new \Exception("Invalid target path.");

    $real = wp_normalize_path($real);
    $wp_content = wp_normalize_path(WP_CONTENT_DIR);

    if (strpos($real, $wp_content) !== 0) {
      throw new \Exception("Target must be inside wp-content.");
    }

    return $real;
  }

  public function run(string $target, string $standard, string $report): array {
    $phpcs = $this->phpcs_path();
    if (!file_exists($phpcs)) {
      throw new \Exception("PHPCS not found. Run composer install inside plugin folder.");
    }

    $target = $this->validate_target($target);

    $report_flag = '--report=summary';
    if ($report === 'full') $report_flag = '--report=full';
    if ($report === 'json') $report_flag = '--report=json';

    $ignore = escapeshellarg('*/vendor/*,*/node_modules/*,*/dist/*,*/build/*');

    $cmd = escapeshellcmd($phpcs)
      . ' --standard=' . escapeshellarg($standard)
      . " {$report_flag}"
      . " --ignore={$ignore} "
      . escapeshellarg($target)
      . ' 2>&1';

    $out = [];
    $exit = 0;
    exec($cmd, $out, $exit);

    return [
      'created_at' => current_time('mysql'),
      'target' => $target,
      'exit_code' => $exit,
      'output' => implode("\n", $out),
    ];
  }
}
