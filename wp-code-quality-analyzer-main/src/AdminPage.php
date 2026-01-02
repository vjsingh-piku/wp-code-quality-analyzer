<?php
declare(strict_types=1);

namespace WCQA;

class AdminPage {
  public const CAP = 'manage_options';
  public const NONCE_FETCH  = 'wcqa_fetch_report';
  public const NONCE_DELETE = 'wcqa_delete_history';

  private const HISTORY_OPTION = 'wcqa_report_history';
  private const HISTORY_LIMIT  = 10;

  public function register(): void {
    add_action('admin_menu', [$this, 'menu']);
    add_action('admin_init', [$this, 'register_settings']);

    add_action('admin_post_wcqa_fetch_report', [$this, 'handle_fetch_report']);
    add_action('admin_post_wcqa_delete_history', [$this, 'handle_delete_history']);
  }

  public function menu(): void {
    add_menu_page(
      'Code Quality Analyzer',
      'Code Quality',
      self::CAP,
      'wcqa',
      [$this, 'render'],
      'dashicons-search',
      80
    );
  }

  public function register_settings(): void {
    register_setting('wcqa_settings', 'wcqa_report_url', [
      'type'              => 'string',
      'sanitize_callback' => 'esc_url_raw',
      'default'           => '',
    ]);
  }

  /**
   * Fetch report from GitHub Raw URL and store:
   * - latest report
   * - meta
   * - history (last 10)
   */
  public function handle_fetch_report(): void {
    if (!current_user_can(self::CAP)) {
      wp_die('Not allowed.');
    }
    check_admin_referer(self::NONCE_FETCH);

    $url = (string) get_option('wcqa_report_url', '');
    if (empty($url)) {
      wp_die('Report URL is not set. Save the Raw URL first.');
    }

    $res = wp_remote_get($url, [
      'timeout' => 25,
      'headers' => [
        'Accept' => 'application/json',
      ],
    ]);

    if (is_wp_error($res)) {
      wp_die('Fetch failed: ' . esc_html($res->get_error_message()));
    }

    $code = (int) wp_remote_retrieve_response_code($res);
    $body = (string) wp_remote_retrieve_body($res);

    if ($code !== 200) {
      wp_die(
        'Fetch failed. HTTP ' . esc_html((string) $code) .
        '<br><br><strong>Response:</strong><br><pre>' . esc_html(substr($body, 0, 400)) . '</pre>'
      );
    }

    $json = json_decode($body, true);
    if (!is_array($json)) {
      wp_die('Invalid JSON. Make sure you used the RAW URL of reports/wcqa-report.json.');
    }

    $summary = $this->wcqa_build_summary($json);
    $score   = (int) $this->wcqa_calculate_score($summary);

    $meta = [
      'fetched_at' => current_time('mysql'),
      'source'     => $url,
      'score'      => $score,
      'errors'     => (int) ($summary['errors'] ?? 0),
      'warnings'   => (int) ($summary['warnings'] ?? 0),
      'files'      => (int) ($summary['files_with_issues'] ?? 0),
    ];

    // Save latest
    update_option('wcqa_last_report', $json, false);
    update_option('wcqa_last_meta', $meta, false);

    // Push into history
    $this->wcqa_push_history($meta, $json);

    wp_safe_redirect(admin_url('admin.php?page=wcqa'));
    exit;
  }

  public function handle_delete_history(): void {
    if (!current_user_can(self::CAP)) {
      wp_die('Not allowed.');
    }
    check_admin_referer(self::NONCE_DELETE);

    delete_option(self::HISTORY_OPTION);
    wp_safe_redirect(admin_url('admin.php?page=wcqa'));
    exit;
  }

  public function render(): void {
    if (!current_user_can(self::CAP)) {
      return;
    }

    $report_url = (string) get_option('wcqa_report_url', '');
    $last_meta  = get_option('wcqa_last_meta', []);
    $report     = get_option('wcqa_last_report', []);
    $history    = get_option(self::HISTORY_OPTION, []);

    if (!is_array($last_meta)) $last_meta = [];
    if (!is_array($report)) $report = [];
    if (!is_array($history)) $history = [];

    $summary = $this->wcqa_build_summary($report);
    $score   = isset($last_meta['score']) ? (int) $last_meta['score'] : (int) $this->wcqa_calculate_score($summary);

    ?>
    <div class="wrap">
      <h1>WP Code Quality Analyzer</h1>

      <h2>Report Source (GitHub Actions)</h2>

      <form method="post" action="<?php echo esc_url(admin_url('options.php')); ?>">
        <?php settings_fields('wcqa_settings'); ?>
        <table class="form-table" role="presentation">
          <tr>
            <th scope="row">
              <label for="wcqa_report_url">PHPCS Report Raw URL</label>
            </th>
            <td>
              <input type="url"
                     id="wcqa_report_url"
                     name="wcqa_report_url"
                     value="<?php echo esc_attr($report_url); ?>"
                     class="regular-text"
                     placeholder="https://raw.githubusercontent.com/USER/REPO/main/reports/wcqa-report.json"
                     required>
              <p class="description">
                Paste the RAW GitHub URL of <code>reports/wcqa-report.json</code>.
              </p>
            </td>
          </tr>
        </table>
        <?php submit_button('Save Settings'); ?>
      </form>

      <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <input type="hidden" name="action" value="wcqa_fetch_report">
        <?php wp_nonce_field(self::NONCE_FETCH); ?>
        <?php submit_button('Fetch Latest Report', 'primary'); ?>
      </form>

      <?php if (!empty($last_meta['fetched_at'])): ?>
        <p><em>Last fetched: <?php echo esc_html((string) $last_meta['fetched_at']); ?></em></p>
      <?php endif; ?>

      <hr>

      <?php
        echo $this->wcqa_render_dashboard($summary, $score, $report, $last_meta);
      ?>

      <hr>

      <?php echo $this->wcqa_render_history($history); ?>
    </div>
    <?php
  }

  /**
   * Build summary from PHPCS JSON report.
   */
  private function wcqa_build_summary(array $report): array {
    $totalErrors = 0;
    $totalWarnings = 0;
    $filesWithIssues = 0;

    $files = $report['files'] ?? [];
    if (!is_array($files)) $files = [];

    foreach ($files as $filePath => $fileData) {
      if (!is_array($fileData)) continue;
      $messages = $fileData['messages'] ?? [];
      if (!is_array($messages) || empty($messages)) continue;

      $filesWithIssues++;

      foreach ($messages as $m) {
        if (!is_array($m)) continue;
        $type = strtoupper((string)($m['type'] ?? ''));
        if ($type === 'ERROR')   $totalErrors++;
        if ($type === 'WARNING') $totalWarnings++;
      }
    }

    return [
      'errors' => $totalErrors,
      'warnings' => $totalWarnings,
      'files_with_issues' => $filesWithIssues,
    ];
  }

  /**
   * Simple scoring model (0–100):
   * - Start 100
   * - Each ERROR: -3
   * - Each WARNING: -1
   * - Clamp 0..100
   */
  private function wcqa_calculate_score(array $summary): int {
    $errors   = (int) ($summary['errors'] ?? 0);
    $warnings = (int) ($summary['warnings'] ?? 0);

    $score = 100 - ($errors * 3) - ($warnings * 1);
    if ($score < 0) $score = 0;
    if ($score > 100) $score = 100;

    return $score;
  }

  /**
   * Store history items:
   * Each item:
   * - id, fetched_at, score, errors, warnings, files, source, report
   */
  private function wcqa_push_history(array $meta, array $report): void {
    $history = get_option(self::HISTORY_OPTION, []);
    if (!is_array($history)) $history = [];

    $item = [
      'id'        => wp_generate_uuid4(),
      'fetched_at'=> (string) ($meta['fetched_at'] ?? current_time('mysql')),
      'score'     => (int) ($meta['score'] ?? 0),
      'errors'    => (int) ($meta['errors'] ?? 0),
      'warnings'  => (int) ($meta['warnings'] ?? 0),
      'files'     => (int) ($meta['files'] ?? 0),
      'source'    => (string) ($meta['source'] ?? ''),
      'report'    => $report, // store full report for “View”
    ];

    array_unshift($history, $item);
    $history = array_slice($history, 0, self::HISTORY_LIMIT);

    update_option(self::HISTORY_OPTION, $history, false);
  }

  /**
   * Dashboard UI with Quality Score.
   */
  private function wcqa_render_dashboard(array $summary, int $score, array $report, array $meta): string {
    $errors   = (int)($summary['errors'] ?? 0);
    $warnings = (int)($summary['warnings'] ?? 0);
    $filesWithIssues = (int)($summary['files_with_issues'] ?? 0);

    $files = $report['files'] ?? [];
    if (!is_array($files)) $files = [];

    ob_start(); ?>
      <style>
        .wcqa-cards { display:flex; gap:12px; margin:16px 0; flex-wrap:wrap; }
        .wcqa-card { background:#fff; border:1px solid #ccd0d4; border-radius:12px; padding:14px 16px; min-width:180px; }
        .wcqa-card .label { font-size:12px; opacity:.75; margin-bottom:6px; }
        .wcqa-card .value { font-size:24px; font-weight:700; }
        .wcqa-meta { margin: 10px 0 18px; opacity: .85; }
        .wcqa-file { background:#fff; border:1px solid #ccd0d4; border-radius:12px; margin:10px 0; padding:10px 12px; }
        .wcqa-issue { border-top:1px solid #eee; padding:8px 0; font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace; }
        .wcqa-tag { display:inline-block; padding:2px 8px; border-radius:999px; font-size:12px; border:1px solid #ccd0d4; margin-right:8px; }
        .wcqa-error { border-color:#d63638; color:#8a1f1f; }
        .wcqa-warning { border-color:#dba617; color:#7a5a00; }
        details summary { cursor:pointer; }
        .wcqa-muted { opacity: .75; }
      </style>

      <h2>Latest Scan Summary</h2>

      <div class="wcqa-cards">
        <div class="wcqa-card">
          <div class="label">Quality Score</div>
          <div class="value"><?php echo esc_html((string)$score); ?>/100</div>
        </div>
        <div class="wcqa-card">
          <div class="label">Errors</div>
          <div class="value"><?php echo esc_html((string)$errors); ?></div>
        </div>
        <div class="wcqa-card">
          <div class="label">Warnings</div>
          <div class="value"><?php echo esc_html((string)$warnings); ?></div>
        </div>
        <div class="wcqa-card">
          <div class="label">Files with issues</div>
          <div class="value"><?php echo esc_html((string)$filesWithIssues); ?></div>
        </div>
      </div>

      <?php if (!empty($meta['fetched_at']) || !empty($meta['source'])): ?>
        <div class="wcqa-meta">
          <?php if (!empty($meta['fetched_at'])): ?>
            <div><strong>Last updated:</strong> <?php echo esc_html((string)$meta['fetched_at']); ?></div>
          <?php endif; ?>
          <?php if (!empty($meta['source'])): ?>
            <div class="wcqa-muted"><strong>Source:</strong> <?php echo esc_html((string)$meta['source']); ?></div>
          <?php endif; ?>
        </div>
      <?php endif; ?>

      <h2>Issues by File</h2>

      <?php
      $any = false;

      foreach ($files as $filePath => $fileData) {
        if (!is_array($fileData)) continue;
        $messages = $fileData['messages'] ?? [];
        if (!is_array($messages) || empty($messages)) continue;

        $any = true;

        $fileErrors = 0; $fileWarnings = 0;
        foreach ($messages as $m) {
          if (!is_array($m)) continue;
          $t = strtoupper((string)($m['type'] ?? ''));
          if ($t === 'ERROR') $fileErrors++;
          if ($t === 'WARNING') $fileWarnings++;
        }
        ?>
        <div class="wcqa-file">
          <details>
            <summary>
              <strong><?php echo esc_html((string)$filePath); ?></strong>
              — <?php echo esc_html("Errors: $fileErrors, Warnings: $fileWarnings"); ?>
            </summary>

            <?php foreach ($messages as $m):
              if (!is_array($m)) continue;

              $type  = strtoupper((string)($m['type'] ?? ''));
              $line  = (int)($m['line'] ?? 0);
              $col   = (int)($m['column'] ?? 0);
              $msg   = (string)($m['message'] ?? '');
              $sniff = (string)($m['source'] ?? '');

              $tagClass = ($type === 'ERROR') ? 'wcqa-tag wcqa-error' : 'wcqa-tag wcqa-warning';
              ?>
              <div class="wcqa-issue">
                <span class="<?php echo esc_attr($tagClass); ?>"><?php echo esc_html($type); ?></span>
                <strong>Line <?php echo esc_html((string)$line); ?></strong><?php if ($col > 0) echo esc_html(" : $col"); ?>
                — <?php echo esc_html($msg); ?>

                <?php if (!empty($sniff)): ?>
                  <div class="wcqa-muted" style="margin-top:4px;">Sniff: <?php echo esc_html($sniff); ?></div>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>

          </details>
        </div>
        <?php
      }

      if (!$any): ?>
        <p>No issues found in the current report (or no report fetched yet). Click <strong>Fetch Latest Report</strong>.</p>
      <?php endif; ?>

    <?php
    return (string) ob_get_clean();
  }

  /**
   * Render scan history list (last 10).
   */
  private function wcqa_render_history(array $history): string {
    ob_start(); ?>
      <h2>Scan History (Last <?php echo esc_html((string) self::HISTORY_LIMIT); ?>)</h2>

      <?php if (empty($history)): ?>
        <p>No history yet. Click <strong>Fetch Latest Report</strong> to save entries.</p>
      <?php else: ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-bottom:10px;">
          <input type="hidden" name="action" value="wcqa_delete_history">
          <?php wp_nonce_field(self::NONCE_DELETE); ?>
          <?php submit_button('Delete History', 'delete', 'submit', false); ?>
        </form>

        <table class="widefat striped">
          <thead>
            <tr>
              <th>Date</th>
              <th>Score</th>
              <th>Errors</th>
              <th>Warnings</th>
              <th>Files</th>
              <th>Details</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($history as $item):
            if (!is_array($item)) continue;

            $date = (string) ($item['fetched_at'] ?? '');
            $score = (int) ($item['score'] ?? 0);
            $errors = (int) ($item['errors'] ?? 0);
            $warnings = (int) ($item['warnings'] ?? 0);
            $files = (int) ($item['files'] ?? 0);

            $report = $item['report'] ?? [];
            if (!is_array($report)) $report = [];

            $summary = $this->wcqa_build_summary($report);
            $meta = [
              'fetched_at' => $date,
              'source' => (string) ($item['source'] ?? ''),
            ];
            ?>
            <tr>
              <td><?php echo esc_html($date); ?></td>
              <td><strong><?php echo esc_html((string)$score); ?></strong>/100</td>
              <td><?php echo esc_html((string)$errors); ?></td>
              <td><?php echo esc_html((string)$warnings); ?></td>
              <td><?php echo esc_html((string)$files); ?></td>
              <td>
                <details>
                  <summary>View</summary>
                  <?php echo $this->wcqa_render_dashboard($summary, $score, $report, $meta); ?>
                </details>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>

    <?php
    return (string) ob_get_clean();
  }
}
