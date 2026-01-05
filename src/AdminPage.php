<?php

declare(strict_types=1);

namespace WCQA;

final class AdminPage
{

	public const CAP          = 'manage_options';
	public const NONCE_SAVE   = 'wcqa_save_sources';
	public const NONCE_FETCH  = 'wcqa_fetch_report';
	public const OPT_SOURCES  = 'wcqa_sources';        // array of repo sources
	public const OPT_HISTORY  = 'wcqa_report_history'; // array keyed by source id
	private const TYPES = array(
		'theme'    => 'Theme',
		'plugin'   => 'Plugin',
		'mu'       => 'MU Plugin',
	);


	public function register(): void
	{
		add_action('admin_menu', array($this, 'menu'));
		add_action('admin_init', array($this, 'register_settings'));

		add_action('admin_post_wcqa_save_sources', array($this, 'handle_save_sources'));
		add_action('admin_post_wcqa_fetch_report', array($this, 'handle_fetch_report'));
	}

	public function menu(): void
	{
		add_menu_page(
			'Code Quality Analyzer',
			'Code Quality',
			self::CAP,
			'wcqa',
			array($this, 'render'),
			'dashicons-search',
			80
		);
	}

	public function register_settings(): void
	{
		// Stored as array:
		// [
		//   ['id'=>'abc123','name'=>'Theme - MyTheme','url'=>'https://raw.../reports/wcqa-report.json','token'=>''],
		//   ...
		// ]
		register_setting('wcqa_settings', self::OPT_SOURCES, array(
			'type'              => 'array',
			'sanitize_callback' => array($this, 'sanitize_sources'),
			'default'           => array(),
		));

		// History stored as array keyed by source id:
		// ['abc123' => [ ['fetched_at'=>'...','score'=>..,'summary'=>..,'report'=>..], ... ], ...]
		register_setting('wcqa_settings', self::OPT_HISTORY, array(
			'type'              => 'array',
			'sanitize_callback' => array($this, 'sanitize_history'),
			'default'           => array(),
		));
	}

	/** Sanitize sources array. */
	public function sanitize_sources($value): array
	{
		$value = is_array($value) ? $value : array();
		$out   = array();

		foreach ($value as $row) {
			if (!is_array($row)) {
				continue;
			}

			$id    = isset($row['id']) ? sanitize_key((string) $row['id']) : '';
			$name  = isset($row['name']) ? sanitize_text_field((string) $row['name']) : '';
			$url   = isset($row['url']) ? esc_url_raw((string) $row['url']) : '';
			$token = isset($row['token']) ? $this->sanitize_token((string) $row['token']) : '';

			$type  = isset($row['type']) ? sanitize_key((string) $row['type']) : 'plugin';
			if (!isset(self::TYPES[$type])) {
				$type = 'plugin';
			}

			if (empty($id)) {
				$id = $this->new_id();
			}

			// Require name + url to keep.
			if (empty($name) || empty($url)) {
				continue;
			}

			$out[] = array(
				'id'    => $id,
				'type'  => $type,
				'name'  => $name,
				'url'   => $url,
				'token' => $token,
			);
		}

		return $out;
	}
	/** Sanitize history array. */

	public function sanitize_history($value): array
	{
		// Keep as-is but ensure array.
		return is_array($value) ? $value : array();
	}

	private function sanitize_token(string $value): string
	{
		$value = trim($value);
		$value = (string) preg_replace('/^Bearer\s+/i', '', $value);
		return $value;
	}

	private function new_id(): string
	{
		// short random-ish id
		return substr(wp_hash((string) microtime(true) . wp_rand()), 0, 10);
	}

	/** Save sources from admin form. */
	public function handle_save_sources(): void
	{
		if (!current_user_can(self::CAP)) {
			wp_die('Not allowed.');
		}
		check_admin_referer(self::NONCE_SAVE);

		// We save through options.php normally, but this handler allows
		// "Add row" / delete behavior via POST.
		$sources = isset($_POST[self::OPT_SOURCES]) ? (array) $_POST[self::OPT_SOURCES] : array();
		update_option(self::OPT_SOURCES, $this->sanitize_sources($sources), false);

		wp_safe_redirect(admin_url('admin.php?page=wcqa'));
		exit;
	}

	/** Fetch report for a specific source id and push into history. */
	public function handle_fetch_report(): void
	{
		if (!current_user_can(self::CAP)) {
			wp_die('Not allowed.');
		}
		check_admin_referer(self::NONCE_FETCH);

		$source_id = isset($_POST['source_id']) ? sanitize_key((string) $_POST['source_id']) : '';
		if (empty($source_id)) {
			wp_die('Missing source.');
		}

		$sources = get_option(self::OPT_SOURCES, array());
		$sources = is_array($sources) ? $sources : array();

		$source = null;
		foreach ($sources as $s) {
			if (is_array($s) && isset($s['id']) && (string) $s['id'] === $source_id) {
				$source = $s;
				break;
			}
		}

		if (!$source || empty($source['url'])) {
			wp_die('Source not found.');
		}

		$url   = (string) $source['url'];
		$token = isset($source['token']) ? (string) $source['token'] : '';

		$headers = array('Accept' => 'application/json');
		if (!empty($token)) {
			$headers['Authorization'] = 'Bearer ' . $token;
		}

		$res = wp_remote_get($url, array(
			'timeout' => 25,
			'headers' => $headers,
		));

		if (is_wp_error($res)) {
			wp_die('Fetch failed: ' . esc_html($res->get_error_message()));
		}

		$code = (int) wp_remote_retrieve_response_code($res);
		$body = (string) wp_remote_retrieve_body($res);

		if ($code !== 200) {
			wp_die(
				'Fetch failed. HTTP ' . esc_html((string) $code) .
					'<br><br><strong>Tip:</strong> If your repo is private, add a GitHub token for this source.' .
					'<br><br><strong>Response:</strong><br><pre>' . esc_html(substr($body, 0, 400)) . '</pre>'
			);
		}

		$json = json_decode($body, true);
		if (!is_array($json)) {
			wp_die('Invalid JSON. Make sure you used the RAW URL of reports/wcqa-report.json.');
		}

		$summary = $this->build_summary($json);
		$score   = $this->compute_quality_score($summary);

		$history = get_option(self::OPT_HISTORY, array());
		$history = is_array($history) ? $history : array();

		if (!isset($history[$source_id]) || !is_array($history[$source_id])) {
			$history[$source_id] = array();
		}

		array_unshift($history[$source_id], array(
			'fetched_at' => current_time('mysql'),
			'source'     => $url,
			'score'      => $score,
			'summary'    => $summary,
			'report'     => $json,
		));

		// Keep last 10 scans per source
		$history[$source_id] = array_slice($history[$source_id], 0, 10);

		update_option(self::OPT_HISTORY, $history, false);

		wp_safe_redirect(admin_url('admin.php?page=wcqa'));
		exit;
	}

	public function render(): void
	{
		if (!current_user_can(self::CAP)) {
			return;
		}

		$sources = get_option(self::OPT_SOURCES, array());
		$sources = is_array($sources) ? $sources : array();

		$history = get_option(self::OPT_HISTORY, array());
		$history = is_array($history) ? $history : array();

		// Overall aggregation (latest entry per source)
		$overall          = array(
			'errors'            => 0,
			'warnings'          => 0,
			'files_with_issues' => 0,
		);
		$overallScoreParts = array();
		$latestBySource    = array();

		foreach ($sources as $s) {
			if (!is_array($s) || empty($s['id'])) {
				continue;
			}
			$sid = (string) $s['id'];

			$latest = isset($history[$sid][0]) && is_array($history[$sid][0]) ? $history[$sid][0] : null;
			if (!$latest) {
				continue;
			}

			$sum = isset($latest['summary']) && is_array($latest['summary']) ? $latest['summary'] : array();
			$overall['errors']            += (int) ($sum['errors'] ?? 0);
			$overall['warnings']          += (int) ($sum['warnings'] ?? 0);
			$overall['files_with_issues'] += (int) ($sum['files_with_issues'] ?? 0);

			$overallScoreParts[]      = (int) ($latest['score'] ?? 0);
			$latestBySource[$sid] = $latest;
		}

		$overallScore = !empty($overallScoreParts)
			? (int) round(array_sum($overallScoreParts) / count($overallScoreParts))
			: 0;

?>
		<div class="wrap">
			<h1>WP Code Quality Analyzer</h1>

			<?php echo $this->render_overall_dashboard($overallScore, $overall); ?>

			<hr>

			<h2>Repo Sources (Themes / Plugins)</h2>
			<p class="description">
				Add one entry per repo. Each repo should generate <code>reports/wcqa-report.json</code> via GitHub Actions.
			</p>

			<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
				<input type="hidden" name="action" value="wcqa_save_sources">
				<?php wp_nonce_field(self::NONCE_SAVE); ?>

				<table class="widefat striped" style="margin-top:12px;">
					<thead>
						<tr>
							<th style="width:12%;">Type</th>
							<th style="width:18%;">Name</th>
							<th>RAW JSON URL</th>
							<th style="width:18%;">Token (optional)</th>
							<th style="width:12%;">Actions</th>
						</tr>
					</thead>

					<tbody>
						<?php if (empty($sources)) : ?>
							<tr>
								<td colspan="5">No sources added yet.</td>
							</tr>
						<?php endif; ?>

						<?php foreach ($sources as $i => $row) :
							if (!is_array($row)) {
								continue;
							}

							$id    = isset($row['id']) ? (string) $row['id'] : $this->new_id();
							$type  = isset($row['type']) ? (string) $row['type'] : 'plugin';
							$name  = isset($row['name']) ? (string) $row['name'] : '';
							$url   = isset($row['url']) ? (string) $row['url'] : '';
							$token = isset($row['token']) ? (string) $row['token'] : '';

							if (!isset(self::TYPES[$type])) {
								$type = 'plugin';
							}
						?>
							<tr>
								<td>
									<select name="<?php echo esc_attr(self::OPT_SOURCES); ?>[<?php echo esc_attr((string) $i); ?>][type]">
										<?php foreach (self::TYPES as $key => $label) : ?>
											<option value="<?php echo esc_attr($key); ?>" <?php selected($type, $key); ?>>
												<?php echo esc_html($label); ?>
											</option>
										<?php endforeach; ?>
									</select>
								</td>

								<td>
									<input type="hidden"
										name="<?php echo esc_attr(self::OPT_SOURCES); ?>[<?php echo esc_attr((string) $i); ?>][id]"
										value="<?php echo esc_attr($id); ?>">

									<input type="text" class="regular-text"
										name="<?php echo esc_attr(self::OPT_SOURCES); ?>[<?php echo esc_attr((string) $i); ?>][name]"
										value="<?php echo esc_attr($name); ?>"
										placeholder="e.g. Soluzione Theme">
								</td>

								<td>
									<input type="url" class="large-text"
										name="<?php echo esc_attr(self::OPT_SOURCES); ?>[<?php echo esc_attr((string) $i); ?>][url]"
										value="<?php echo esc_attr($url); ?>"
										placeholder="https://raw.githubusercontent.com/USER/REPO/main/reports/wcqa-report.json">
								</td>

								<td>
									<input type="password" class="regular-text"
										name="<?php echo esc_attr(self::OPT_SOURCES); ?>[<?php echo esc_attr((string) $i); ?>][token]"
										value="<?php echo esc_attr($token); ?>"
										placeholder="Only for private repos">
								</td>

								<td>
									<span class="description">Save to apply</span>
								</td>
							</tr>
						<?php endforeach; ?>

						<?php $newIndex = count($sources); ?>
						<tr>
							<td>
								<select name="<?php echo esc_attr(self::OPT_SOURCES); ?>[<?php echo esc_attr((string) $newIndex); ?>][type]">
									<?php foreach (self::TYPES as $key => $label) : ?>
										<option value="<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></option>
									<?php endforeach; ?>
								</select>
							</td>

							<td>
								<input type="hidden"
									name="<?php echo esc_attr(self::OPT_SOURCES); ?>[<?php echo esc_attr((string) $newIndex); ?>][id]"
									value="">

								<input type="text" class="regular-text"
									name="<?php echo esc_attr(self::OPT_SOURCES); ?>[<?php echo esc_attr((string) $newIndex); ?>][name]"
									value=""
									placeholder="Add new repo name">
							</td>

							<td>
								<input type="url" class="large-text"
									name="<?php echo esc_attr(self::OPT_SOURCES); ?>[<?php echo esc_attr((string) $newIndex); ?>][url]"
									value=""
									placeholder="Add new RAW JSON URL">
							</td>

							<td>
								<input type="password" class="regular-text"
									name="<?php echo esc_attr(self::OPT_SOURCES); ?>[<?php echo esc_attr((string) $newIndex); ?>][token]"
									value=""
									placeholder="Optional token">
							</td>

							<td><span class="description">Add &amp; Save</span></td>
						</tr>
					</tbody>
				</table>

				<?php submit_button('Save Sources'); ?>
			</form>

			<hr>

			<h2>Fetch Latest Reports</h2>
			<p class="description">Fetch each repo report (stores history).</p>

			<?php if (empty($sources)) : ?>
				<p>Add at least one repo source first.</p>
			<?php else : ?>
				<div style="display:flex; gap:12px; flex-wrap:wrap; margin-top:10px;">
					<?php foreach ($sources as $s) :
						if (!is_array($s) || empty($s['id'])) {
							continue;
						}

						$sid   = (string) $s['id'];
						$label = method_exists($this, 'label_with_type')
							? $this->label_with_type($s)
							: ((self::TYPES[(string) ($s['type'] ?? 'plugin')] ?? 'Plugin') . ' — ' . (string) ($s['name'] ?? $sid));
					?>
						<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin:0;">
							<input type="hidden" name="action" value="wcqa_fetch_report">
							<input type="hidden" name="source_id" value="<?php echo esc_attr($sid); ?>">
							<?php wp_nonce_field(self::NONCE_FETCH); ?>
							<?php submit_button('Fetch: ' . $label, 'secondary', 'submit', false); ?>
						</form>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>

			<hr>

			<h2>Latest Summary (Per Repo)</h2>
			<?php echo $this->render_repo_cards($sources, $latestBySource); ?>

			<hr>

			<h2>Scan History (Last 10 per Repo)</h2>
			<?php echo $this->render_history($sources, $history); ?>

		</div>
	<?php
	}

	/** Build summary from PHPCS JSON report. */
	private function build_summary(array $report): array
	{
		$totalErrors     = 0;
		$totalWarnings   = 0;
		$filesWithIssues = 0;

		$files = isset($report['files']) && is_array($report['files']) ? $report['files'] : array();

		foreach ($files as $fileData) {
			if (!is_array($fileData)) continue;

			$messages = isset($fileData['messages']) && is_array($fileData['messages']) ? $fileData['messages'] : array();
			if (empty($messages)) continue;

			$filesWithIssues++;

			foreach ($messages as $m) {
				if (!is_array($m)) continue;
				$type = strtoupper((string) ($m['type'] ?? ''));
				if ($type === 'ERROR') {
					$totalErrors++;
				} elseif ($type === 'WARNING') {
					$totalWarnings++;
				}
			}
		}

		return array(
			'errors'           => $totalErrors,
			'warnings'         => $totalWarnings,
			'files_with_issues' => $filesWithIssues,
		);
	}

	/**
	 * Quality Score heuristic (0-100).
	 * You can tune weights later. This is simple & stable.
	 */
	private function compute_quality_score(array $summary): int
	{
		$errors   = (int) ($summary['errors'] ?? 0);
		$warnings = (int) ($summary['warnings'] ?? 0);

		// Weighted penalty.
		$penalty = ($errors * 4) + ($warnings * 1);

		// Map penalty to score. Clamp to 0..100.
		$score = 100 - (int) min(100, round($penalty / 5));
		if ($score < 0) $score = 0;
		if ($score > 100) $score = 100;

		return $score;
	}

	private function render_overall_dashboard(int $score, array $summary): string
	{
		$errors   = (int) ($summary['errors'] ?? 0);
		$warnings = (int) ($summary['warnings'] ?? 0);
		$files    = (int) ($summary['files_with_issues'] ?? 0);

		ob_start();
	?>
		<style>
			.wcqa-cards {
				display: flex;
				gap: 12px;
				margin: 16px 0;
				flex-wrap: wrap;
			}

			.wcqa-card {
				background: #fff;
				border: 1px solid #ccd0d4;
				border-radius: 12px;
				padding: 14px 16px;
				min-width: 180px;
			}

			.wcqa-card .label {
				font-size: 12px;
				opacity: .75;
				margin-bottom: 6px;
			}

			.wcqa-card .value {
				font-size: 24px;
				font-weight: 700;
			}
		</style>

		<h2>Overall Website Code Quality (Repos Added)</h2>

		<div class="wcqa-cards">
			<div class="wcqa-card">
				<div class="label">Quality Score</div>
				<div class="value"><?php echo esc_html((string) $score); ?>/100</div>
			</div>
			<div class="wcqa-card">
				<div class="label">Errors</div>
				<div class="value"><?php echo esc_html((string) $errors); ?></div>
			</div>
			<div class="wcqa-card">
				<div class="label">Warnings</div>
				<div class="value"><?php echo esc_html((string) $warnings); ?></div>
			</div>
			<div class="wcqa-card">
				<div class="label">Files with issues</div>
				<div class="value"><?php echo esc_html((string) $files); ?></div>
			</div>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	private function render_repo_cards(array $sources, array $latestBySource): string
	{
		ob_start();

		if (empty($sources)) {
			echo '<p>No sources configured.</p>';
			return (string) ob_get_clean();
		}

		echo '<div class="wcqa-cards">';

		foreach ($sources as $s) {
			if (!is_array($s) || empty($s['id'])) continue;

			$sid  = (string) $s['id'];
			$name = (string) ($s['name'] ?? $sid);

			$latest = $latestBySource[$sid] ?? null;
			$sum    = is_array($latest) && isset($latest['summary']) && is_array($latest['summary']) ? $latest['summary'] : array();

			$score   = is_array($latest) ? (int) ($latest['score'] ?? 0) : 0;
			$errors  = (int) ($sum['errors'] ?? 0);
			$warns   = (int) ($sum['warnings'] ?? 0);
			$fetched = is_array($latest) ? (string) ($latest['fetched_at'] ?? '') : '';

		?>
			<div class="wcqa-card" style="min-width:260px;">
				<div class="label"><?php echo esc_html($name); ?></div>
				<div class="value"><?php echo esc_html((string) $score); ?>/100</div>
				<div class="label" style="margin-top:8px;">
					Errors: <?php echo esc_html((string) $errors); ?> · Warnings: <?php echo esc_html((string) $warns); ?>
				</div>
				<?php if (!empty($fetched)) : ?>
					<div class="label">Last fetched: <?php echo esc_html($fetched); ?></div>
				<?php else : ?>
					<div class="label">Not fetched yet</div>
				<?php endif; ?>
			</div>
<?php
		}

		echo '</div>';

		return (string) ob_get_clean();
	}

	private function render_history(array $sources, array $history): string
	{
		ob_start();

		if (empty($sources)) {
			echo '<p>No sources configured.</p>';
			return (string) ob_get_clean();
		}

		foreach ($sources as $s) {
			if (!is_array($s) || empty($s['id'])) continue;

			$sid  = (string) $s['id'];
			$name = (string) ($s['name'] ?? $sid);

			$items = isset($history[$sid]) && is_array($history[$sid]) ? $history[$sid] : array();

			echo '<h3>' . esc_html($name) . '</h3>';

			if (empty($items)) {
				echo '<p>No scans yet. Click fetch.</p>';
				continue;
			}

			echo '<table class="widefat striped" style="margin:8px 0 20px;">';
			echo '<thead><tr><th style="width:18%;">Fetched</th><th style="width:12%;">Score</th><th style="width:12%;">Errors</th><th style="width:12%;">Warnings</th><th>Source</th></tr></thead>';
			echo '<tbody>';

			foreach ($items as $row) {
				if (!is_array($row)) continue;
				$fetched = (string) ($row['fetched_at'] ?? '');
				$score   = (int) ($row['score'] ?? 0);
				$sum     = isset($row['summary']) && is_array($row['summary']) ? $row['summary'] : array();
				$errors  = (int) ($sum['errors'] ?? 0);
				$warns   = (int) ($sum['warnings'] ?? 0);
				$src     = (string) ($row['source'] ?? '');

				echo '<tr>';
				echo '<td>' . esc_html($fetched) . '</td>';
				echo '<td><strong>' . esc_html((string) $score) . '/100</strong></td>';
				echo '<td>' . esc_html((string) $errors) . '</td>';
				echo '<td>' . esc_html((string) $warns) . '</td>';
				echo '<td>' . esc_html($src) . '</td>';
				echo '</tr>';
			}

			echo '</tbody></table>';
		}

		return (string) ob_get_clean();
	}
}
