<?php
/**
 * Admin UI for WP Code Quality Analyzer.
 *
 * Stores multiple repo sources (Theme/Plugin/MU Plugin),
 * fetches PHPCS JSON reports from GitHub RAW URLs,
 * keeps scan history, and renders dashboards + issue drill-down.
 *
 * @package WCQA
 */

declare(strict_types=1);

namespace WCQA;

final class AdminPage {

	public const CAP         = 'manage_options';
	public const NONCE_SAVE  = 'wcqa_save_sources';
	public const NONCE_FETCH = 'wcqa_fetch_report';

	public const OPT_SOURCES = 'wcqa_sources';        // array of repo sources.
	public const OPT_HISTORY = 'wcqa_report_history'; // array keyed by source id.

	/**
	 * Allowed types.
	 *
	 * @var array<string,string>
	 */
	private const TYPES = array(
		'theme'  => 'Theme',
		'plugin' => 'Plugin',
		'mu'     => 'MU Plugin',
	);

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );

		add_action( 'admin_post_wcqa_save_sources', array( $this, 'handle_save_sources' ) );
		add_action( 'admin_post_wcqa_fetch_report', array( $this, 'handle_fetch_report' ) );
	}

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

	public function register_settings(): void {
		register_setting(
			'wcqa_settings',
			self::OPT_SOURCES,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_sources' ),
				'default'           => array(),
			)
		);

		register_setting(
			'wcqa_settings',
			self::OPT_HISTORY,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_history' ),
				'default'           => array(),
			)
		);
	}

	/**
	 * Sanitize sources array.
	 *
	 * @param mixed $value Value from request.
	 * @return array<int,array<string,string>>
	 */
	public function sanitize_sources( $value ): array {
		$value = is_array( $value ) ? $value : array();
		$out   = array();

		foreach ( $value as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$id    = isset( $row['id'] ) ? sanitize_key( (string) $row['id'] ) : '';
			$type  = isset( $row['type'] ) ? sanitize_key( (string) $row['type'] ) : 'plugin';
			$name  = isset( $row['name'] ) ? sanitize_text_field( (string) $row['name'] ) : '';
			$url   = isset( $row['url'] ) ? esc_url_raw( (string) $row['url'] ) : '';
			$token = isset( $row['token'] ) ? $this->sanitize_token( (string) $row['token'] ) : '';

			if ( ! isset( self::TYPES[ $type ] ) ) {
				$type = 'plugin';
			}

			if ( '' === $id ) {
				$id = $this->new_id();
			}

			// Require name + url to keep.
			if ( '' === $name || '' === $url ) {
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

	/**
	 * Sanitize history.
	 *
	 * @param mixed $value Value from DB/request.
	 * @return array
	 */
	public function sanitize_history( $value ): array {
		return is_array( $value ) ? $value : array();
	}

	private function sanitize_token( string $value ): string {
		$value = trim( $value );
		$value = (string) preg_replace( '/^Bearer\s+/i', '', $value );
		return $value;
	}

	private function new_id(): string {
		return substr( wp_hash( (string) microtime( true ) . wp_rand() ), 0, 10 );
	}

	/**
	 * Save sources from admin form.
	 */
	public function handle_save_sources(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( 'Not allowed.' );
		}

		check_admin_referer( self::NONCE_SAVE );

		$sources = isset( $_POST[ self::OPT_SOURCES ] ) ? (array) $_POST[ self::OPT_SOURCES ] : array();
		update_option( self::OPT_SOURCES, $this->sanitize_sources( $sources ), false );

		wp_safe_redirect( admin_url( 'admin.php?page=wcqa' ) );
		exit;
	}

	/**
	 * Fetch report for a specific source id and push into history.
	 */
	public function handle_fetch_report(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( 'Not allowed.' );
		}

		check_admin_referer( self::NONCE_FETCH );

		$source_id = isset( $_POST['source_id'] ) ? sanitize_key( (string) $_POST['source_id'] ) : '';
		if ( '' === $source_id ) {
			wp_die( 'Missing source.' );
		}

		$sources = get_option( self::OPT_SOURCES, array() );
		$sources = is_array( $sources ) ? $sources : array();

		$source = null;
		foreach ( $sources as $s ) {
			if ( is_array( $s ) && isset( $s['id'] ) && (string) $s['id'] === $source_id ) {
				$source = $s;
				break;
			}
		}

		if ( ! $source || empty( $source['url'] ) ) {
			wp_die( 'Source not found.' );
		}

		$url   = (string) $source['url'];
		$token = isset( $source['token'] ) ? (string) $source['token'] : '';

		$headers = array( 'Accept' => 'application/json' );
		if ( '' !== $token ) {
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
				'<br><br><strong>Tip:</strong> If your repo is private, add a GitHub token for this source.' .
				'<br><br><strong>Response:</strong><br><pre>' . esc_html( substr( $body, 0, 400 ) ) . '</pre>'
			);
		}

		$json = json_decode( $body, true );
		if ( ! is_array( $json ) ) {
			wp_die( 'Invalid JSON. Make sure you used the RAW URL of reports/wcqa-report.json.' );
		}

		$summary = $this->build_summary( $json );
		$score   = $this->compute_quality_score( $summary );

		$history = get_option( self::OPT_HISTORY, array() );
		$history = is_array( $history ) ? $history : array();

		if ( ! isset( $history[ $source_id ] ) || ! is_array( $history[ $source_id ] ) ) {
			$history[ $source_id ] = array();
		}

		array_unshift(
			$history[ $source_id ],
			array(
				'fetched_at' => current_time( 'mysql' ),
				'source'     => $url,
				'score'      => $score,
				'summary'    => $summary,
				'report'     => $json,
			)
		);

		// Keep last 10 scans per source.
		$history[ $source_id ] = array_slice( $history[ $source_id ], 0, 10 );

		update_option( self::OPT_HISTORY, $history, false );

		wp_safe_redirect( admin_url( 'admin.php?page=wcqa' ) );
		exit;
	}

	/**
	 * Render admin page.
	 */
	public function render(): void {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}

		$sources = get_option( self::OPT_SOURCES, array() );
		$sources = is_array( $sources ) ? $sources : array();

		$history = get_option( self::OPT_HISTORY, array() );
		$history = is_array( $history ) ? $history : array();

		// Build latestBySource + overall stats.
		$overall           = array( 'errors' => 0, 'warnings' => 0, 'files_with_issues' => 0 );
		$overall_score_set = array();
		$latest_by_source  = array();

		foreach ( $sources as $s ) {
			if ( ! is_array( $s ) || empty( $s['id'] ) ) {
				continue;
			}
			$sid = (string) $s['id'];

			$latest = ( isset( $history[ $sid ][0] ) && is_array( $history[ $sid ][0] ) ) ? $history[ $sid ][0] : null;
			if ( ! $latest || ! is_array( $latest ) ) {
				continue;
			}

			$sum = ( isset( $latest['summary'] ) && is_array( $latest['summary'] ) ) ? $latest['summary'] : array();

			$overall['errors']            += (int) ( $sum['errors'] ?? 0 );
			$overall['warnings']          += (int) ( $sum['warnings'] ?? 0 );
			$overall['files_with_issues'] += (int) ( $sum['files_with_issues'] ?? 0 );

			$overall_score_set[]     = (int) ( $latest['score'] ?? 0 );
			$latest_by_source[ $sid ] = $latest;
		}

		$overall_score = ! empty( $overall_score_set )
			? (int) round( array_sum( $overall_score_set ) / count( $overall_score_set ) )
			: 0;

		?>
		<div class="wrap">
			<h1>WP Code Quality Analyzer</h1>

			<?php echo $this->render_overall_dashboard( $overall_score, $overall ); ?>

			<hr>

			<h2>Repo Sources (Themes / Plugins)</h2>
			<p class="description">
				Add one entry per repo. Each repo should generate <code>reports/wcqa-report.json</code> via GitHub Actions.
				<br><strong>Important:</strong> Use the <em>RAW JSON URL</em> (raw.githubusercontent.com), not the repo URL.
			</p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="wcqa_save_sources">
				<?php wp_nonce_field( self::NONCE_SAVE ); ?>

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
					<?php foreach ( $sources as $i => $row ) : ?>
						<?php
						if ( ! is_array( $row ) ) {
							continue;
						}
						$id    = isset( $row['id'] ) ? (string) $row['id'] : $this->new_id();
						$type  = isset( $row['type'] ) ? sanitize_key( (string) $row['type'] ) : 'plugin';
						$name  = isset( $row['name'] ) ? (string) $row['name'] : '';
						$url   = isset( $row['url'] ) ? (string) $row['url'] : '';
						$token = isset( $row['token'] ) ? (string) $row['token'] : '';

						if ( ! isset( self::TYPES[ $type ] ) ) {
							$type = 'plugin';
						}
						?>
						<tr>
							<td>
								<input type="hidden"
									name="<?php echo esc_attr( self::OPT_SOURCES ); ?>[<?php echo esc_attr( (string) $i ); ?>][id]"
									value="<?php echo esc_attr( $id ); ?>">

								<select name="<?php echo esc_attr( self::OPT_SOURCES ); ?>[<?php echo esc_attr( (string) $i ); ?>][type]">
									<?php foreach ( self::TYPES as $key => $label ) : ?>
										<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $type, $key ); ?>>
											<?php echo esc_html( $label ); ?>
										</option>
									<?php endforeach; ?>
								</select>
							</td>

							<td>
								<input type="text" class="regular-text"
									name="<?php echo esc_attr( self::OPT_SOURCES ); ?>[<?php echo esc_attr( (string) $i ); ?>][name]"
									value="<?php echo esc_attr( $name ); ?>"
									placeholder="e.g. Theme - MyTheme">
							</td>

							<td>
								<input type="url" class="large-text"
									name="<?php echo esc_attr( self::OPT_SOURCES ); ?>[<?php echo esc_attr( (string) $i ); ?>][url]"
									value="<?php echo esc_attr( $url ); ?>"
									placeholder="https://raw.githubusercontent.com/USER/REPO/main/reports/wcqa-report.json">
							</td>

							<td>
								<input type="password" class="regular-text"
									name="<?php echo esc_attr( self::OPT_SOURCES ); ?>[<?php echo esc_attr( (string) $i ); ?>][token]"
									value="<?php echo esc_attr( $token ); ?>"
									placeholder="Only for private repos">
							</td>

							<td><span class="description">Save to apply</span></td>
						</tr>
					<?php endforeach; ?>

					<?php $new_index = count( $sources ); ?>
						<tr>
							<td>
								<input type="hidden"
									name="<?php echo esc_attr( self::OPT_SOURCES ); ?>[<?php echo esc_attr( (string) $new_index ); ?>][id]"
									value="">

								<select name="<?php echo esc_attr( self::OPT_SOURCES ); ?>[<?php echo esc_attr( (string) $new_index ); ?>][type]">
									<?php foreach ( self::TYPES as $key => $label ) : ?>
										<option value="<?php echo esc_attr( $key ); ?>">
											<?php echo esc_html( $label ); ?>
										</option>
									<?php endforeach; ?>
								</select>
							</td>

							<td>
								<input type="text" class="regular-text"
									name="<?php echo esc_attr( self::OPT_SOURCES ); ?>[<?php echo esc_attr( (string) $new_index ); ?>][name]"
									value=""
									placeholder="Add new repo name">
							</td>

							<td>
								<input type="url" class="large-text"
									name="<?php echo esc_attr( self::OPT_SOURCES ); ?>[<?php echo esc_attr( (string) $new_index ); ?>][url]"
									value=""
									placeholder="Add new RAW JSON URL">
							</td>

							<td>
								<input type="password" class="regular-text"
									name="<?php echo esc_attr( self::OPT_SOURCES ); ?>[<?php echo esc_attr( (string) $new_index ); ?>][token]"
									value=""
									placeholder="Optional token">
							</td>

							<td><span class="description">Add &amp; Save</span></td>
						</tr>
					</tbody>
				</table>

				<?php submit_button( 'Save Sources' ); ?>
			</form>

			<hr>

			<h2>Fetch Latest Reports</h2>
			<p class="description">Fetch each repo report (stores history).</p>

			<?php if ( empty( $sources ) ) : ?>
				<p>Add at least one repo source first.</p>
			<?php else : ?>
				<div style="display:flex; gap:12px; flex-wrap:wrap; margin-top:10px;">
					<?php foreach ( $sources as $s ) : ?>
						<?php
						if ( ! is_array( $s ) || empty( $s['id'] ) ) {
							continue;
						}
						$sid       = (string) $s['id'];
						$label     = $this->label_with_type( $s );
						?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:0;">
							<input type="hidden" name="action" value="wcqa_fetch_report">
							<input type="hidden" name="source_id" value="<?php echo esc_attr( $sid ); ?>">
							<?php wp_nonce_field( self::NONCE_FETCH ); ?>
							<?php submit_button( 'Fetch: ' . $label, 'secondary', 'submit', false ); ?>
						</form>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>

			<hr>

			<h2>Latest Summary (Per Repo)</h2>
			<p class="description">Each card shows score + latest errors/warnings.</p>
			<?php echo $this->render_repo_cards( $sources, $latest_by_source ); ?>

			<hr>

			<h2>Latest Issues (Click to Expand)</h2>
			<p class="description">This shows actual errors/warnings from the latest fetched report.</p>
			<?php echo $this->render_latest_issues( $sources, $latest_by_source ); ?>

			<hr>

			<h2>Scan History (Last 10 per Repo)</h2>
			<?php echo $this->render_history( $sources, $history ); ?>
		</div>
		<?php
	}

	/**
	 * Build summary from PHPCS JSON report.
	 *
	 * @param array $report Report JSON decoded.
	 * @return array<string,int>
	 */
	private function build_summary( array $report ): array {
		$total_errors     = 0;
		$total_warnings   = 0;
		$files_with_issues = 0;

		$files = ( isset( $report['files'] ) && is_array( $report['files'] ) ) ? $report['files'] : array();

		foreach ( $files as $file_data ) {
			if ( ! is_array( $file_data ) ) {
				continue;
			}

			$messages = ( isset( $file_data['messages'] ) && is_array( $file_data['messages'] ) ) ? $file_data['messages'] : array();
			if ( empty( $messages ) ) {
				continue;
			}

			$files_with_issues++;

			foreach ( $messages as $m ) {
				if ( ! is_array( $m ) ) {
					continue;
				}
				$type = strtoupper( (string) ( $m['type'] ?? '' ) );
				if ( 'ERROR' === $type ) {
					$total_errors++;
				} elseif ( 'WARNING' === $type ) {
					$total_warnings++;
				}
			}
		}

		return array(
			'errors'            => $total_errors,
			'warnings'          => $total_warnings,
			'files_with_issues' => $files_with_issues,
		);
	}

	/**
	 * Quality Score heuristic (0-100).
	 *
	 * @param array $summary Summary array.
	 * @return int
	 */
	private function compute_quality_score( array $summary ): int {
		$errors   = (int) ( $summary['errors'] ?? 0 );
		$warnings = (int) ( $summary['warnings'] ?? 0 );

		$penalty = ( $errors * 4 ) + ( $warnings * 1 );

		$score = 100 - (int) min( 100, round( $penalty / 5 ) );
		$score = max( 0, min( 100, $score ) );

		return $score;
	}

	private function label_with_type( array $source ): string {
		$type      = isset( $source['type'] ) ? (string) $source['type'] : 'plugin';
		$type_label = self::TYPES[ $type ] ?? 'Plugin';
		$name      = isset( $source['name'] ) ? (string) $source['name'] : '';
		return $type_label . ' — ' . $name;
	}

	private function render_overall_dashboard( int $score, array $summary ): string {
		$errors   = (int) ( $summary['errors'] ?? 0 );
		$warnings = (int) ( $summary['warnings'] ?? 0 );
		$files    = (int) ( $summary['files_with_issues'] ?? 0 );

		ob_start();
		?>
		<style>
			.wcqa-cards { display:flex; gap:12px; margin:16px 0; flex-wrap:wrap; }
			.wcqa-card { background:#fff; border:1px solid #ccd0d4; border-radius:12px; padding:14px 16px; min-width:180px; }
			.wcqa-card .label { font-size:12px; opacity:.75; margin-bottom:6px; }
			.wcqa-card .value { font-size:24px; font-weight:700; }
		</style>

		<h2>Overall Website Code Quality (Repos Added)</h2>

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
		<?php
		return (string) ob_get_clean();
	}

	private function render_repo_cards( array $sources, array $latest_by_source ): string {
		ob_start();

		if ( empty( $sources ) ) {
			echo '<p>No sources configured.</p>';
			return (string) ob_get_clean();
		}

		echo '<div class="wcqa-cards">';

		foreach ( $sources as $s ) {
			if ( ! is_array( $s ) || empty( $s['id'] ) ) {
				continue;
			}

			$sid   = (string) $s['id'];
			$label = $this->label_with_type( $s );

			$latest  = $latest_by_source[ $sid ] ?? null;
			$sum     = ( is_array( $latest ) && isset( $latest['summary'] ) && is_array( $latest['summary'] ) ) ? $latest['summary'] : array();
			$score   = is_array( $latest ) ? (int) ( $latest['score'] ?? 0 ) : 0;
			$errors  = (int) ( $sum['errors'] ?? 0 );
			$warns   = (int) ( $sum['warnings'] ?? 0 );
			$fetched = is_array( $latest ) ? (string) ( $latest['fetched_at'] ?? '' ) : '';

			?>
			<div class="wcqa-card" style="min-width:260px;">
				<div class="label"><?php echo esc_html( $label ); ?></div>
				<div class="value"><?php echo esc_html( (string) $score ); ?>/100</div>
				<div class="label" style="margin-top:8px;">
					Errors: <?php echo esc_html( (string) $errors ); ?> · Warnings: <?php echo esc_html( (string) $warns ); ?>
				</div>
				<?php if ( '' !== $fetched ) : ?>
					<div class="label">Last fetched: <?php echo esc_html( $fetched ); ?></div>
				<?php else : ?>
					<div class="label">Not fetched yet</div>
				<?php endif; ?>
			</div>
			<?php
		}

		echo '</div>';

		return (string) ob_get_clean();
	}

	private function render_history( array $sources, array $history ): string {
		ob_start();

		if ( empty( $sources ) ) {
			echo '<p>No sources configured.</p>';
			return (string) ob_get_clean();
		}

		foreach ( $sources as $s ) {
			if ( ! is_array( $s ) || empty( $s['id'] ) ) {
				continue;
			}

			$sid   = (string) $s['id'];
			$label = $this->label_with_type( $s );

			$items = ( isset( $history[ $sid ] ) && is_array( $history[ $sid ] ) ) ? $history[ $sid ] : array();

			echo '<h3>' . esc_html( $label ) . '</h3>';

			if ( empty( $items ) ) {
				echo '<p>No scans yet. Click fetch.</p>';
				continue;
			}

			echo '<table class="widefat striped" style="margin:8px 0 20px;">';
			echo '<thead><tr><th style="width:18%;">Fetched</th><th style="width:12%;">Score</th><th style="width:12%;">Errors</th><th style="width:12%;">Warnings</th><th>Source</th></tr></thead>';
			echo '<tbody>';

			foreach ( $items as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				$fetched = (string) ( $row['fetched_at'] ?? '' );
				$score   = (int) ( $row['score'] ?? 0 );
				$sum     = ( isset( $row['summary'] ) && is_array( $row['summary'] ) ) ? $row['summary'] : array();
				$errors  = (int) ( $sum['errors'] ?? 0 );
				$warns   = (int) ( $sum['warnings'] ?? 0 );
				$src     = (string) ( $row['source'] ?? '' );

				echo '<tr>';
				echo '<td>' . esc_html( $fetched ) . '</td>';
				echo '<td><strong>' . esc_html( (string) $score ) . '/100</strong></td>';
				echo '<td>' . esc_html( (string) $errors ) . '</td>';
				echo '<td>' . esc_html( (string) $warns ) . '</td>';
				echo '<td>' . esc_html( $src ) . '</td>';
				echo '</tr>';
			}

			echo '</tbody></table>';
		}

		return (string) ob_get_clean();
	}

	private function render_latest_issues( array $sources, array $latest_by_source ): string {
		ob_start();

		if ( empty( $sources ) ) {
			echo '<p>No sources configured.</p>';
			return (string) ob_get_clean();
		}

		foreach ( $sources as $s ) {
			if ( ! is_array( $s ) || empty( $s['id'] ) ) {
				continue;
			}

			$sid   = (string) $s['id'];
			$label = $this->label_with_type( $s );

			$latest = $latest_by_source[ $sid ] ?? null;
			$report = ( is_array( $latest ) && isset( $latest['report'] ) && is_array( $latest['report'] ) )
				? $latest['report']
				: array();

			$fetched = ( is_array( $latest ) && ! empty( $latest['fetched_at'] ) ) ? (string) $latest['fetched_at'] : '';

			echo '<div style="background:#fff;border:1px solid #ccd0d4;border-radius:12px;padding:12px;margin:12px 0;">';
			echo '<details>';
			echo '<summary style="cursor:pointer;">';
			echo '<strong>' . esc_html( $label ) . '</strong>';
			if ( '' !== $fetched ) {
				echo ' <span style="opacity:.7;">(Last fetched: ' . esc_html( $fetched ) . ')</span>';
			}
			echo '</summary>';

			echo $this->render_report_issues( $report );

			echo '</details>';
			echo '</div>';
		}

		return (string) ob_get_clean();
	}

	private function render_report_issues( array $report ): string {
		$files = ( isset( $report['files'] ) && is_array( $report['files'] ) ) ? $report['files'] : array();

		if ( empty( $files ) ) {
			return '<p style="margin:10px 0;">No report data found for this repo. Click Fetch first, or confirm RAW JSON URL.</p>';
		}

		$file_stats = array();
		foreach ( $files as $file_path => $file_data ) {
			if ( ! is_array( $file_data ) ) {
				continue;
			}
			$messages = ( isset( $file_data['messages'] ) && is_array( $file_data['messages'] ) ) ? $file_data['messages'] : array();
			$file_stats[ (string) $file_path ] = count( $messages );
		}
		arsort( $file_stats );

		$max_files   = 15;
		$max_perfile = 50;

		ob_start();

		echo '<div style="margin-top:12px;">';
		echo '<p class="description">Showing top ' . esc_html( (string) $max_files ) . ' files by issue count (limit ' . esc_html( (string) $max_perfile ) . ' issues per file).</p>';

		$shown = 0;

		foreach ( $file_stats as $file_path => $count ) {
			if ( $shown >= $max_files ) {
				break;
			}

			$file_data = $files[ $file_path ] ?? array();
			$messages  = ( isset( $file_data['messages'] ) && is_array( $file_data['messages'] ) ) ? $file_data['messages'] : array();
			if ( empty( $messages ) ) {
				continue;
			}

			$errors = 0;
			$warns  = 0;
			foreach ( $messages as $m ) {
				if ( ! is_array( $m ) ) {
					continue;
				}
				$t = strtoupper( (string) ( $m['type'] ?? '' ) );
				if ( 'ERROR' === $t ) {
					$errors++;
				}
				if ( 'WARNING' === $t ) {
					$warns++;
				}
			}

			echo '<div style="border-top:1px solid #eee;padding-top:10px;margin-top:10px;">';
			echo '<details>';
			echo '<summary style="cursor:pointer;">';
			echo '<strong>' . esc_html( $file_path ) . '</strong>';
			echo ' <span style="opacity:.75;">— Errors: ' . esc_html( (string) $errors ) . ', Warnings: ' . esc_html( (string) $warns ) . '</span>';
			echo '</summary>';

			echo '<div style="margin-top:10px;font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, \'Liberation Mono\', \'Courier New\', monospace;">';

			$i = 0;
			foreach ( $messages as $m ) {
				if ( $i >= $max_perfile ) {
					break;
				}
				if ( ! is_array( $m ) ) {
					continue;
				}

				$type  = strtoupper( (string) ( $m['type'] ?? '' ) );
				$line  = (int) ( $m['line'] ?? 0 );
				$col   = (int) ( $m['column'] ?? 0 );
				$msg   = (string) ( $m['message'] ?? '' );
				$sniff = (string) ( $m['source'] ?? '' );

				$badge_style = ( 'ERROR' === $type )
					? 'display:inline-block;border:1px solid #d63638;color:#8a1f1f;border-radius:999px;padding:2px 8px;font-size:12px;margin-right:8px;'
					: 'display:inline-block;border:1px solid #dba617;color:#7a5a00;border-radius:999px;padding:2px 8px;font-size:12px;margin-right:8px;';

				echo '<div style="padding:8px 0;border-top:1px solid #f3f3f3;">';
				echo '<span style="' . esc_attr( $badge_style ) . '">' . esc_html( $type ) . '</span>';
				echo '<strong>Line ' . esc_html( (string) $line ) . '</strong>';
				if ( $col > 0 ) {
					echo esc_html( ':' . (string) $col );
				}
				echo ' — ' . esc_html( $msg );

				if ( '' !== $sniff ) {
					echo '<div style="opacity:.75;margin-top:4px;">Rule: ' . esc_html( $sniff ) . '</div>';
				}

				echo '</div>';

				$i++;
			}

			if ( count( $messages ) > $max_perfile ) {
				echo '<p style="opacity:.75;margin-top:8px;">Showing first ' . esc_html( (string) $max_perfile ) . ' issues only.</p>';
			}

			echo '</div>';
			echo '</details>';
			echo '</div>';

			$shown++;
		}

		echo '</div>';

		return (string) ob_get_clean();
	}
}
