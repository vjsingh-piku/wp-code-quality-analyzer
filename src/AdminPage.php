<?php
declare(strict_types=1);

namespace WCQA;

class AdminPage {
  public const CAP = 'manage_options';
  public const NONCE_RUN  = 'wcqa_run_scan';
  public const NONCE_FETCH = 'wcqa_fetch_report';

  public function register(): void {
    add_action('admin_menu', [$this, 'menu']);

    // Local scan (optional)
    add_action('admin_post_wcqa_run_scan', [$this, 'handle_scan']);

    // Settings + GitHub report fetch
    add_action('admin_init', [$this, 'register_settings']);
    add_action('admin_post_wcqa_fetch_report', [$this, 'handle_fetch_report']);
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

    // Optional: for Private GitHub repos
    register_setting('wcqa_settings', 'wcqa_github_token', [
      'type'              => 'string',
      'sanitize_callback' => [$this, 'sanitize_token'],
      'default'           => '',
    ]);
  }

  public function sanitize_token(string $value): string {
    $value = trim($value);
    // Remove accidental "Bearer " prefix if user pastes it.
    $value = preg_replace('/^Bearer\s+/i', '', $value) ?? $value;
    return $value;
  }

  /**
   * Fetch report from GitHub Raw URL and store in options.
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

    $token = (string) get_option('wcqa_github_token', '');
    $headers = [
      'Accept' => 'application/json',
    ];

    // If repo is private, add token.
    if (!empty($token)) {
      $headers['Authorization'] = 'Bearer ' . $token;
    }

    $res = wp_remote_get($url, [
      'timeout' => 25,
      'headers' => $headers,
    ]);

    if (is_wp_error($res)) {
      wp_die('Fetch failed: ' . esc_html($res->get_error_message()));
    }

    $code = (int) wp_remote_retrieve_response_code($res);
    $body = (string) wp_remote_retrieve_body($res);

    if ($code !== 200) {
      // GitHub private raw URL often returns 404 without token.
      wp_die(
        'Fetch failed. HTTP ' . esc_html((string) $code) .
        '<br><br><strong>Tip:</strong> If your repo is private, add a GitHub Token in settings.' .
        '<br><br><strong>Response:</strong><br><pre>' . esc_html(substr($body, 0, 400)) . '</pre>'
      );
    }

    $json = json_decode($body, true);
    if (!is_array($json)) {
      wp_die('Invalid JSON. Make sure you used the RAW URL of reports/wcqa-report.json.');
    }

    update_option('wcqa_last_report', $json, false);
    update_option('wcqa_last_meta', [
      'fetched_at' => current_time('mysql'),
      'source'     => $url,
    ], false);

    wp_safe_redirect(admin_url('admin.php?page=wcqa'));
    exit;
  }

  public function render(): void {
    if (!current_user_can(self::CAP)) {
      return;
    }

    // Local scan (optional legacy)
    $themes  = function_exists('wp_get_themes') ? wp_get_themes() : [];
    $plugins = function_exists('get_plugins') ? get_plugins() : [];
    $last = null;
    if (class_exists('\WCQA\ReportStore')) {
      $last = \WCQA\ReportStore::get_last();
    }

    // GitHub report data
    $report_url = (string) get_option('wcqa_report_url', '');
    $token      = (string) get_option('wcqa_github_token', '');
    $last_meta  = get_option('wcqa_last_meta', []);
    $report     = get_option('wcqa_last_report', []);

    if (!is_array($last_meta)) $last_meta = [];
    if (!is_array($report)) $report = [];

    $summary = $this->wcqa_build_summary($report);

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
                     placeholder="https://raw.githubusercontent.com/.../reports/wcqa-report.json"
                     required>
              <p class="description">
                Paste the RAW GitHub URL of <code>reports/wcqa-report.json</code>.
                <br>Example: <code>https://raw.githubusercontent.com/USER/REPO/main/reports/wcqa-report.json</code>
              </p>
            </td>
          </tr>

          <tr>
            <th scope="row">
              <label for="wcqa_github_token">GitHub Token (optional)</label>
            </th>
            <td>
              <input type="password"
                     id="wcqa_github_token"
                     name="wcqa_github_token"
                     value="<?php echo esc_attr($token); ?>"
                     class="regular-text"
                     placeholder="Only needed for PRIVATE repos">
              <p class="description">
                If your repo is <strong>Private</strong>, create a GitHub token and paste here.
                Required scope: <code>repo</code> (classic token) or a fine-grained token with read access to this repo.
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
        echo $this->wcqa_render_dashboard($summary, $report, $last_meta);
      ?>

      <hr>

      <h2>Run Local Scan (Optional)</h2>
      <p class="description">
        Note: Many shared hosting providers disable <code>exec()</code>. If local scan fails, use the GitHub report above.
      </p>

      <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <input type="hidden" name="action" value="wcqa_run_scan">
        <?php wp_nonce_field(self::NONCE_RUN); ?>

        <table class="form-table" role="presentation">
          <tr>
            <th>Scan Type</th>
            <td>
              <select name="scan_type">
                <option value="theme">Theme</option>
                <option value="plugin">Plugin</option>
              </select>
            </td>
          </tr>

          <tr>
            <th>Theme</th>
            <td>
              <select name="theme_slug">
                <?php foreach ($themes as $slug => $theme): ?>
                  <option value="<?php echo esc_attr((string) $slug); ?>">
                    <?php echo esc_html((string) $theme->get('Name')); ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </td>
          </tr>

          <tr>
            <th>Plugin</th>
            <td>
              <select name="plugin_file">
                <?php foreach ($plugins as $file => $data): ?>
                  <option value="<?php echo esc_attr((string) $file); ?>">
                    <?php echo esc_html((string) ($data['Name'] ?? $file)); ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </td>
          </tr>

          <tr>
            <th>Standard</th>
            <td>
              <select name="standard">
                <option value="WordPress">WordPress</option>
                <option value="WordPress-Core">WordPress-Core</option>
                <option value="WordPress-Extra">WordPress-Extra</option>
                <option value="WordPress-Docs">WordPress-Docs</option>
              </select>
            </td>
          </tr>

          <tr>
            <th>Report</th>
            <td>
              <select name="report">
                <option value="summary">Summary</option>
                <option value="full">Full</option>
                <option value="json">JSON</option>
              </select>
            </td>
          </tr>
        </table>

        <?php submit_button('Run Scan'); ?>
      </form>

      <hr>

      <h2>Last Local Scan Result</h2>
      <?php if (!empty($last) && is_array($last)): ?>
        <p><strong>Date:</strong> <?php echo esc_html((string) ($last['created_at'] ?? '')); ?></p>
        <p><strong>Target:</strong> <?php echo esc_html((string) ($last['target'] ?? '')); ?></p>
        <p><strong>Exit Code:</strong> <?php echo esc_html((string) ($last['exit_code'] ?? '')); ?></p>
        <textarea class="large-text code" rows="18" readonly><?php echo esc_textarea((string) ($last['output'] ?? '')); ?></textarea>
      <?php else: ?>
        <p>No local scan run yet.</p>
      <?php endif; ?>
    </div>
    <?php
  }

  /**
   * LOCAL scan handler (optional). Will fail on shared hosting if exec is disabled.
   */
  public function handle_scan(): void {
    if (!current_user_can(self::CAP)) {
      wp_die('Not allowed.');
    }
    check_admin_referer(self::NONCE_RUN);

    $scan_type = sanitize_text_field($_POST['scan_type'] ?? 'theme');
    $standard  = sanitize_text_field($_POST['standard'] ?? 'WordPress');
    $report    = sanitize_text_field($_POST['report'] ?? 'summary');

    if ($scan_type === 'plugin') {
      $plugin_file = sanitize_text_field($_POST['plugin_file'] ?? '');
      $target = WP_PLUGIN_DIR . '/' . dirname($plugin_file);
    } else {
      $theme_slug = sanitize_text_field($_POST['theme_slug'] ?? '');
      $target = WP_CONTENT_DIR . '/themes/' . $theme_slug;
    }

    try {
      if (!class_exists('\WCQA\Scanner')) {
        throw new \RuntimeException('Scanner class not found.');
      }
      $scanner = new \WCQA\Scanner();
      $result  = $scanner->run($target, $standard, $report);

      if (class_exists('\WCQA\ReportStore')) {
        \WCQA\ReportStore::save_last($result);
      }
    } catch (\Throwable $e) {
      if (class_exists('\WCQA\ReportStore')) {
        \WCQA\ReportStore::save_last([
          'created_at' => current_time('mysql'),
          'target' => 'ERROR',
          'exit_code' => 1,
          'output' => $e->getMessage(),
        ]);
      }
    }

    wp_safe_redirect(admin_url('admin.php?page=wcqa'));
    exit;
  }

  /**
   * Build summary from PHPCS JSON report.
   */
  private function wcqa_build_summary(array $report): array {
    $totalErrors = 0;
    $totalWarnings = 0;
    $filesWithIssues = 0;

    $files = $report['files'] ?? [];
    if (!is_array($files)) {
      $files = [];
    }

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
   * Render professional dashboard UI (Option 2).
   */
  private function wcqa_render_dashboard(array $summary, array $report, array $meta): string {
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
}
