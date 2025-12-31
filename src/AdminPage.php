<?php
namespace WCQA;

class AdminPage {
  const CAP = 'manage_options';
  const NONCE = 'wcqa_run_scan';

  public function register(): void {
    add_action('admin_menu', [$this, 'menu']);
    add_action('admin_post_wcqa_run_scan', [$this, 'handle_scan']);
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

  public function render(): void {
    if (!current_user_can(self::CAP)) return;

    $themes  = wp_get_themes();
    $plugins = get_plugins();
    $last    = ReportStore::get_last();
    ?>
    <div class="wrap">
      <h1>WP Code Quality Analyzer</h1>

      <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <input type="hidden" name="action" value="wcqa_run_scan">
        <?php wp_nonce_field(self::NONCE); ?>

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
                  <option value="<?php echo esc_attr($slug); ?>">
                    <?php echo esc_html($theme->get('Name')); ?>
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
                  <option value="<?php echo esc_attr($file); ?>">
                    <?php echo esc_html($data['Name']); ?>
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

      <h2>Last Scan Result</h2>
      <?php if ($last): ?>
        <p><strong>Date:</strong> <?php echo esc_html($last['created_at']); ?></p>
        <p><strong>Target:</strong> <?php echo esc_html($last['target']); ?></p>
        <p><strong>Exit Code:</strong> <?php echo esc_html((string)$last['exit_code']); ?></p>
        <textarea class="large-text code" rows="18" readonly><?php echo esc_textarea($last['output']); ?></textarea>
      <?php else: ?>
        <p>No scan run yet.</p>
      <?php endif; ?>
    </div>
    <?php
  }

  public function handle_scan(): void {
    if (!current_user_can(self::CAP)) wp_die('Not allowed.');
    check_admin_referer(self::NONCE);

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
      $scanner = new Scanner();
      $result  = $scanner->run($target, $standard, $report);
      ReportStore::save_last($result);
    } catch (\Throwable $e) {
      ReportStore::save_last([
        'created_at' => current_time('mysql'),
        'target' => 'ERROR',
        'exit_code' => 1,
        'output' => $e->getMessage(),
      ]);
    }

    wp_safe_redirect(admin_url('admin.php?page=wcqa'));
    exit;
  }
}
