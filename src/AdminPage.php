<?php
/**
 * Admin page for WP Code Quality Analyzer.
 *
 * @package WCQA
 */

declare(strict_types=1);

namespace WCQA;

class AdminPage {

	public const CAP          = 'manage_options';
	public const NONCE_FETCH  = 'wcqa_fetch_report';
	public const OPT_URL      = 'wcqa_report_url';
	public const OPT_TOKEN    = 'wcqa_github_token';
	public const OPT_LAST     = 'wcqa_last_report';
	public const OPT_META     = 'wcqa_last_meta';
	public const OPT_HISTORY  = 'wcqa_scan_history';
	public const HISTORY_MAX  = 10;

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_post_wcqa_fetch_report', array( $this, 'handle_fetch_report' ) );
	}

	/**
	 * Add admin menu.
	 *
	 * @return void
	 */
	public function menu(): void {
		add_menu_page(
			'Code Quality Analyzer',
			'Code Quality',
			self::CAP,
			'wcqa',
			array( $this, 'render' ),
			'dashicons-search',
			80
		);
	}

	/**
	 * Register settings.
	 *
	 * @return void
	 */
	public function register_settings(): void {
		register_setting(
			'wcqa_settings',
			self::OPT_URL,
			array(
				'type'              => 'string',
				'sanitize_callback' => 'esc_url_raw',
				'default'           => '',
			)
		);

		register_setting(
			'wcqa_settings',
			self::OPT_TOKEN,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_token' ),
				'default'           => '',
			)
		);
	}

	/**
	 * Sanitize GitHub token.
	 *
	 * @param string $value Token.
	 * @return string
	 */
	public function sanitize_token( string $value ): string {
		$value = trim( $value );
		$value = (string) preg_replace( '/^Bearer\s+/i', '', $value );
		return $value;
	}

	/**
	 * Fetch report from GitHub RAW URL.
	 *
	 * @return void
	 */
	public function handle_fetch_report(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( 'Not allowed.' );
		}

		check_admin_referer( self::NONCE_FETCH );

		$url = (string) get_option( self::OPT_URL, '' );
		if ( empty( $url ) ) {
			wp_die( 'Report URL is not set. Save the RAW URL first.' );
		}

		$token   = (string) get_option( self::OPT_TOKEN, '' );
		$headers = array(
			'Accept' => 'application/json',
		);

		if ( ! empty( $token ) ) {
			$headers['Authorization'] = 'Bearer ' . $token;
		}

		$res = wp_remote_get(
			$url,
			array(
				'timeout' => 25,
				'headers' => $headers,
			)
		);

		if ( is_wp_error( $res ) ) {
			wp_die( 'Fetch failed: ' . esc_html( $res->get_error_message() ) );
		}

		$code = (int) wp_remote_retrieve_response_code( $res );
		$body = (string) wp_remote_retrieve_body( $res );

		if ( 200 !== $code ) {
			wp_die(
				'Fetch failed. HTTP ' . esc_html( (string) $code ) .
				'<br><br><strong>Response:</strong><br><pre>' . esc_html( substr( $body, 0, 400 ) ) . '</pre>'
			);
		}

		$json = json_decode( $body, true );

		// If the RAW file contains plain text (like PHPCS error output), JSON decode fails.
		if ( ! is_array( $json ) || empty( $json['files'] ) || ! is_array( $json['files'] ) ) {
			wp_die(
				'Invalid PHPCS JSON report. Make sure your workflow writes a JSON report using:' .
				'<br><code>--report=json --report-file=reports/wcqa-report.json</code><br><br>' .
				'Tip: Open the RAW URL in browser. It must start with <code>{</code> and contain <code>"files"</code>.'
			);
		}

		$meta = array(
			'fetched_at' => current_time( 'mysql' ),
			'source'     => $url,
		);

		update_option( self::OPT_LAST, $json, false );
		update_option( self::OPT_META, $meta, false );

		$summary = $this->build_summary( $json );
		$this->append_history( $summary, $meta );

		wp_safe_redirect( admin_url( 'admin.php?page=wcqa' ) );
		exit;
	}

	/**
	 * Render admin page.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}

		$report_url = (string) get_option( self::OPT_URL, '' );
		$token      = (string) get_option( self::OPT_TOKEN, '' );
		$meta       = get_option( self::OPT_META, array() );
		$report     = get_option( self::OPT_LAST, array() );
		$history    = get_option( self::OPT_HISTORY, array() );

		if ( ! is_array( $meta ) ) {
			$meta = array();
		}

		if ( ! is_array( $report ) ) {
			$report = array();
		}

		if ( ! is_array( $history ) ) {
			$history = array();
		}

		$summary = $this->build_summary( $report );
		?>
		<div class="wrap">
			<h1>WP Code Quality Analyzer</h1>

			<h2>Report Source (GitHub Actions)</h2>

			<form method="post" action="<?php echo esc_url( admin_url( 'options.php' ) ); ?>">
				<?php settings_fields( 'wcqa_settings' ); ?>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<label for="<?php echo esc_attr( self::OPT_URL ); ?>">PHPCS Report RAW URL</label>
						</th>
						<td>
							<input
								type="url"
								id="<?php echo esc_attr( self::OPT_URL ); ?>"
								name="<?php echo esc_attr( self::OPT_URL ); ?>"
								value="<?php echo esc_attr( $report_url ); ?>"
								class="regular-text"
								placeholder="https://raw.githubusercontent.com/USER/REPO/main/reports/wcqa-report.json"
								required
							/>
							<p class="description">Paste RAW URL of <code>reports/wcqa-report.json</code>.</p>
						</td>
					</tr>

					<tr>
						<th scope="row">
							<label for="<?php echo esc_attr( self::OPT_TOKEN ); ?>">GitHub Token (optional)</label>
						</th>
						<td>
							<input
								type="password"
								id="<?php echo esc_attr( self::OPT_TOKEN ); ?>"
								name="<?php echo esc_attr( self::OPT_TOKEN ); ?>"
								value="<?php echo esc_attr( $token ); ?>"
								class="regular-text"
								placeholder="Only for private repos"
							/>
						</td>
					</tr>
				</table>

				<?php submit_button( 'Save Settings' ); ?>
			</form>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="wcqa_fetch_report" />
				<?php wp_nonce_field( self::NONCE_FETCH ); ?>
				<?php submit_button( 'Fetch Latest Report', 'primary' ); ?>
			</form>

			<?php echo $this->render_dashboard( $summary, $report, $meta ); ?>
			<?php echo $this->render_history( $history ); ?>
		</div>
		<?php
	}

	/**
	 * Build summary from PHPCS JSON report.
	 *
	 * @param array $report Report array.
	 * @return array
	 */
	private function build_summary( array $report ): array {
		$total_errors   = 0;
		$total_warnings = 0;
		$files_issues   = 0;

		$files = isset( $report['files'] ) && is_array( $report['files'] ) ? $report['files'] : array();

		foreach ( $files as $file_data ) {
			if ( ! is_array( $file_data ) || empty( $file_data['messages'] ) || ! is_array( $file_data['messages'] ) ) {
				continue;
			}

			$files_issues++;

			foreach ( $file_data['messages'] as $m ) {
				if ( ! is_array( $m ) ) {
					continue;
				}

				$type = strtoupper( (string) ( $m['type'] ?? '' ) );

				if ( 'ERROR' === $type ) {
					$total_errors++;
				}

				if ( 'WARNING' === $type ) {
					$total_warnings++;
				}
			}
		}

		$score = $this->compute_score( $total_errors, $total_warnings, $files_issues );

		return array(
			'errors'           => $total_errors,
			'warnings'         => $total_warnings,
			'files_with_issues'=> $files_issues,
			'score'            => $score,
		);
	}

	/**
	 * Compute Quality Score (0-100).
	 *
	 * @param int $errors Errors.
	 * @param int $warnings Warnings.
	 * @param int $files Files with issues.
	 * @return int
	 */
	private function compute_score( int $errors, int $warnings, int $files ): int {
		// Weighted penalty: errors hit harder than warnings; more files = small extra penalty.
		$penalty = ( $errors * 0.05 ) + ( $warnings * 0.02 ) + ( $files * 0.5 );

		$score = (int) round( 100 - min( 100, $penalty ) );

		if ( $score < 0 ) {
			return 0;
		}

		if ( $score > 100 ) {
			return 100;
		}

		return $score;
	}

	/**
	 * Store last 10 summaries as history.
	 *
	 * @param array $summary Summary.
	 * @param array $meta Meta.
	 * @return void
	 */
	private function append_history( array $summary, array $meta ): void {
		$history = get_option( self::OPT_HISTORY, array() );

		if ( ! is_array( $history ) ) {
			$history = array();
		}

		array_unshift(
			$history,
			array(
				'fetched_at' => (string) ( $meta['fetched_at'] ?? '' ),
				'source'     => (string) ( $meta['source'] ?? '' ),
				'score'      => (int) ( $summary['score'] ?? 0 ),
				'errors'     => (int) ( $summary['errors'] ?? 0 ),
				'warnings'   => (int) ( $summary['warnings'] ?? 0 ),
				'files'      => (int) ( $summary['files_with_issues'] ?? 0 ),
			)
		);

		$history = array_slice( $history, 0, self::HISTORY_MAX );

		update_option( self::OPT_HISTORY, $history, false );
	}

	/**
	 * Render dashboard UI.
	 *
	 * @param array $summary Summary.
	 * @param array $report Report.
	 * @param array $meta Meta.
	 * @return string
	 */
	private function render_dashboard( array $summary, array $report, array $meta ): string {
		$errors    = (int) ( $summary['errors'] ?? 0 );
		$warnings  = (int) ( $summary['warnings'] ?? 0 );
		$files     = (int) ( $summary['files_with_issues'] ?? 0 );
		$score     = (int) ( $summary['score'] ?? 0 );
		$files_map = isset( $report['files'] ) && is_array( $report['files'] ) ? $report['files'] : array();

		ob_start();
		?>
		<style>
			.wcqa-cards{display:flex;gap:12px;margin:16px 0;flex-wrap:wrap}
			.wcqa-card{background:#fff;border:1px solid #ccd0d4;border-radius:12px;padding:14px 16px;min-width:180px}
			.wcqa-card .label{font-size:12px;opacity:.75;margin-bottom:6px}
			.wcqa-card .value{font-size:24px;font-weight:700}
			.wcqa-meta{margin:10px 0 18px;opacity:.85}
			.wcqa-file{background:#fff;border:1px solid #ccd0d4;border-radius:12px;margin:10px 0;padding:10px 12px}
			.wcqa-issue{border-top:1px solid #eee;padding:8px 0;font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,"Liberation Mono","Courier New",monospace}
			.wcqa-tag{display:inline-block;padding:2px 8px;border-radius:999px;font-size:12px;border:1px solid #ccd0d4;margin-right:8px}
			.wcqa-error{border-color:#d63638;color:#8a1f1f}
			.wcqa-warning{border-color:#dba617;color:#7a5a00}
			details summary{cursor:pointer}
			.wcqa-muted{opacity:.75}
		</style>

		<h2>Latest Scan Summary</h2>

		<div class="wcqa-cards">
			<div class="wcqa-card">
				<div class="label">Quality Score</div>
				<div class="value"><?php echo esc_html( (string) $score ); ?>/100</div>
			</div>
			<div class="wcqa-card">
				<div class="label">Errors</div>
				<div class="value"><?php echo esc_html( (string) $errors ); ?></div>
			</div>
			<div class="wcqa-card">
				<div class="label">Warnings</div>
				<div class="value"><?php echo esc_html( (string) $warnings ); ?></div>
			</div>
			<div class="wcqa-card">
				<div class="label">Files with issues</div>
				<div class="value"><?php echo esc_html( (string) $files ); ?></div>
			</div>
		</div>

		<?php if ( ! empty( $meta['fetched_at'] ) || ! empty( $meta['source'] ) ) : ?>
			<div class="wcqa-meta">
				<?php if ( ! empty( $meta['fetched_at'] ) ) : ?>
					<div><strong>Last updated:</strong> <?php echo esc_html( (string) $meta['fetched_at'] ); ?></div>
				<?php endif; ?>
				<?php if ( ! empty( $meta['source'] ) ) : ?>
					<div class="wcqa-muted"><strong>Source:</strong> <?php echo esc_html( (string) $meta['source'] ); ?></div>
				<?php endif; ?>
			</div>
		<?php endif; ?>

		<h2>Issues by File</h2>

		<?php
		$any = false;

		foreach ( $files_map as $file_path => $file_data ) {
			if ( ! is_array( $file_data ) || empty( $file_data['messages'] ) || ! is_array( $file_data['messages'] ) ) {
				continue;
			}

			$any = true;

			$file_errors   = 0;
			$file_warnings = 0;

			foreach ( $file_data['messages'] as $m ) {
				if ( ! is_array( $m ) ) {
					continue;
				}

				$type = strtoupper( (string) ( $m['type'] ?? '' ) );

				if ( 'ERROR' === $type ) {
					$file_errors++;
				}
				if ( 'WARNING' === $type ) {
					$file_warnings++;
				}
			}
			?>
			<div class="wcqa-file">
				<details>
					<summary>
						<strong><?php echo esc_html( (string) $file_path ); ?></strong>
						— <?php echo esc_html( 'Errors: ' . (string) $file_errors . ', Warnings: ' . (string) $file_warnings ); ?>
					</summary>

					<?php foreach ( $file_data['messages'] as $m ) : ?>
						<?php
						if ( ! is_array( $m ) ) {
							continue;
						}

						$type  = strtoupper( (string) ( $m['type'] ?? '' ) );
						$line  = (int) ( $m['line'] ?? 0 );
						$col   = (int) ( $m['column'] ?? 0 );
						$msg   = (string) ( $m['message'] ?? '' );
						$sniff = (string) ( $m['source'] ?? '' );

						$tag_class = ( 'ERROR' === $type ) ? 'wcqa-tag wcqa-error' : 'wcqa-tag wcqa-warning';
						?>
						<div class="wcqa-issue">
							<span class="<?php echo esc_attr( $tag_class ); ?>"><?php echo esc_html( $type ); ?></span>
							<strong>Line <?php echo esc_html( (string) $line ); ?></strong><?php echo ( $col > 0 ) ? esc_html( ' : ' . (string) $col ) : ''; ?>
							— <?php echo esc_html( $msg ); ?>
							<?php if ( ! empty( $sniff ) ) : ?>
								<div class="wcqa-muted" style="margin-top:4px;">Sniff: <?php echo esc_html( $sniff ); ?></div>
							<?php endif; ?>
						</div>
					<?php endforeach; ?>
				</details>
			</div>
			<?php
		}

		if ( ! $any ) :
			?>
			<p>No issues found (or no report fetched yet). Click <strong>Fetch Latest Report</strong>.</p>
		<?php endif; ?>

		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Render scan history table.
	 *
	 * @param array $history History.
	 * @return string
	 */
	private function render_history( array $history ): string {
		ob_start();
		?>
		<hr>
		<h2>Scan History (Last <?php echo esc_html( (string) self::HISTORY_MAX ); ?>)</h2>

		<?php if ( empty( $history ) ) : ?>
			<p>No history yet. Click <strong>Fetch Latest Report</strong>.</p>
		<?php else : ?>
			<table class="widefat striped">
				<thead>
					<tr>
						<th>Date</th>
						<th>Score</th>
						<th>Errors</th>
						<th>Warnings</th>
						<th>Files</th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $history as $row ) : ?>
						<tr>
							<td><?php echo esc_html( (string) ( $row['fetched_at'] ?? '' ) ); ?></td>
							<td><strong><?php echo esc_html( (string) ( (int) ( $row['score'] ?? 0 ) ) ); ?>/100</strong></td>
							<td><?php echo esc_html( (string) ( (int) ( $row['errors'] ?? 0 ) ) ); ?></td>
							<td><?php echo esc_html( (string) ( (int) ( $row['warnings'] ?? 0 ) ) ); ?></td>
							<td><?php echo esc_html( (string) ( (int) ( $row['files'] ?? 0 ) ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
		<?php
		return (string) ob_get_clean();
	}
}
