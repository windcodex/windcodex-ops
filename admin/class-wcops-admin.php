<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Settings > WindCodex Ops admin screen.
 *
 * A tabbed settings UI (Connection / Tools / General / Activity) built
 * on the shared "elegant" design system – flat neutral surfaces, one accent
 * color, semantic risk color used only for small badges, dense bordered list
 * rows, and a text-only tab bar – while still using native WP form controls
 * underneath for accessibility and familiarity.
 */
class WCOPS_Admin {

	private static $instance = null;

	/** @var WCOPS_Settings */
	private $settings;

	const TABS = array(
		'connection' => array( 'label' => 'Connection' ),
		'tools'      => array( 'label' => 'Tools' ),
		'general'    => array( 'label' => 'General' ),
		'log'        => array( 'label' => 'Activity' ),
	);

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->settings = WCOPS_Settings::instance();

		add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
		add_action( 'admin_post_wcops_save_general', array( $this, 'handle_save_general' ) );
		add_action( 'admin_post_wcops_save_tools', array( $this, 'handle_save_tools' ) );
		add_action( 'admin_post_wcops_rotate_oauth_secret', array( $this, 'handle_rotate_oauth_secret' ) );
		add_action( 'admin_post_wcops_revoke_oauth', array( $this, 'handle_revoke_oauth' ) );
		add_action( 'admin_post_wcops_revoke_client', array( $this, 'handle_revoke_client' ) );
		add_action( 'admin_post_wcops_undo_change', array( $this, 'handle_undo_change' ) );
		add_action( 'admin_post_wcops_onboarding', array( $this, 'handle_onboarding' ) );
		add_action( 'admin_post_wcops_clear_log', array( $this, 'handle_clear_log' ) );
		add_action( 'admin_notices', array( $this, 'maybe_show_notice' ) );
		add_filter( 'plugin_action_links_' . WCOPS_PLUGIN_BASE, array( $this, 'plugin_action_links' ) );
	}

	public function add_settings_page() {
		add_options_page(
			'WindCodex Ops',
			'WindCodex Ops',
			'manage_options',
			'windcodex-ops',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Add "Settings" and "Docs" links to the plugin's row on the Plugins screen.
	 */
	public function plugin_action_links( array $links ): array {
		$settings_link = '<a href="' . esc_url( admin_url( 'options-general.php?page=windcodex-ops' ) ) . '">' . esc_html__( 'Settings', 'windcodex-ops' ) . '</a>';
		$docs_link     = '<a href="' . esc_url( 'https://docs.windcodex.com/docs/ops' ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Docs', 'windcodex-ops' ) . '</a>';
		array_unshift( $links, $settings_link, $docs_link );
		return $links;
	}

	public function maybe_show_notice() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only: these query args only pick which already-saved notice text to display, they never change state (the actual mutations they follow were already nonce-checked in their own admin-post handlers).
		if ( ! isset( $_GET['page'] ) || 'windcodex-ops' !== $_GET['page'] ) {
			return;
		}
		if ( isset( $_GET['wcops_saved'] ) ) {
			echo '<div class="notice notice-success is-dismissible wcops-notice"><p>Settings saved!</p></div>';
		}
		if ( isset( $_GET['wcops_oauth_rotated'] ) ) {
			echo '<div class="notice notice-success is-dismissible wcops-notice"><p>OAuth client secret regenerated. Update it wherever you pasted the old one.</p></div>';
		}
		if ( isset( $_GET['wcops_oauth_revoked'] ) ) {
			echo '<div class="notice notice-success is-dismissible wcops-notice"><p>All OAuth access has been revoked. Anything connected via OAuth will need to reauthorize.</p></div>';
		}
		if ( isset( $_GET['wcops_client_revoked'] ) ) {
			echo '<div class="notice notice-success is-dismissible wcops-notice"><p>Access revoked for that app. It will need to reauthorize to reconnect.</p></div>';
		}
		if ( isset( $_GET['wcops_undo_done'] ) ) {
			echo '<div class="notice notice-success is-dismissible wcops-notice"><p>Change undone.</p></div>';
		}
		if ( isset( $_GET['wcops_undo_failed'] ) ) {
			echo '<div class="notice notice-error is-dismissible wcops-notice"><p>Could not undo that change – it may be outside the undo window or already restored.</p></div>';
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
	}

	private function current_tab() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only: just picks which settings tab to render, no state change.
		$tab = sanitize_key( $_GET['tab'] ?? 'connection' );
		return array_key_exists( $tab, self::TABS ) ? $tab : 'connection';
	}

	private function tab_url( $tab ) {
		return add_query_arg(
			array( 'page' => 'windcodex-ops', 'tab' => $tab ),
			admin_url( 'options-general.php' )
		);
	}

	public function handle_save_general() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not allowed.' );
		}
		check_admin_referer( 'wcops_save_general' );

		// The 72-hour undo window is a guarantee, not a preference – a
		// person can only raise it, never lower it below the floor.
		$undo_hours = max( 72, absint( $_POST['undo_window_hours'] ?? 72 ) );

		$require_preview = ! empty( $_POST['require_preview_on_high_risk'] );

		$this->settings->update(
			array(
				'enabled'                       => ! empty( $_POST['enabled'] ),
				'read_only'                     => ! empty( $_POST['read_only'] ),
				'connect_capability'         => sanitize_key( wp_unslash( $_POST['connect_capability'] ?? 'manage_options' ) ),
				'max_results_limit'             => max( 1, absint( $_POST['max_results_limit'] ?? 50 ) ),
				'requests_per_minute_limit'     => max( 10, min( 600, absint( $_POST['requests_per_minute_limit'] ?? 120 ) ) ),
				'allow_dynamic_registration'    => ! empty( $_POST['allow_dynamic_registration'] ),
				'delete_data_on_uninstall'      => ! empty( $_POST['delete_data_on_uninstall'] ),
				'undo_window_hours'             => $undo_hours,
				'require_preview_on_high_risk'  => $require_preview,
				'email_alerts_on_high_risk'     => ! empty( $_POST['email_alerts_on_high_risk'] ),
				'weekly_activity_summary'       => ! empty( $_POST['weekly_activity_summary'] ),
				'log_ai_activity'               => ! empty( $_POST['log_ai_activity'] ),
				'enable_undo_log'               => ! empty( $_POST['enable_undo_log'] ),
			)
		);

		wp_safe_redirect( add_query_arg( 'wcops_saved', '1', $this->tab_url( 'general' ) ) );
		exit;
	}

	public function handle_revoke_client() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not allowed.' );
		}
		check_admin_referer( 'wcops_revoke_client' );

		$client_id = sanitize_text_field( wp_unslash( $_POST['client_id'] ?? '' ) );
		if ( $client_id ) {
			WCOPS_OAuth::revoke_client_tokens( $client_id );
		}

		wp_safe_redirect( add_query_arg( 'wcops_client_revoked', '1', $this->tab_url( 'connection' ) ) );
		exit;
	}

	public function handle_onboarding() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not allowed.' );
		}
		check_admin_referer( 'wcops_onboarding' );

		if ( ! empty( $_POST['skip'] ) ) {
			$this->settings->update( array( 'onboarding_completed' => true ) );
			wp_safe_redirect( $this->tab_url( 'tools' ) );
			exit;
		}

		$groups = $this->settings->get( 'tool_groups', array() );
		foreach ( WCOPS_Settings::bundle_metadata() as $bundle_key => $bundle ) {
			if ( ! empty( $_POST[ 'bundle_' . $bundle_key ] ) ) {
				foreach ( $bundle['groups'] as $real_group ) {
					$groups[ $real_group ] = true;
				}
			}
		}
		$groups['wp_site'] = true;

		$this->settings->update(
			array(
				'tool_groups'          => $groups,
				'onboarding_completed' => true,
			)
		);

		wp_safe_redirect( $this->tab_url( 'tools' ) );
		exit;
	}

	private function render_onboarding() {
		$this->print_styles();
		$endpoint  = WCOPS_Server::instance()->get_endpoint_url();
		$detected  = WCOPS_Settings::detect_related_plugins();
		$bundles   = WCOPS_Settings::bundle_metadata();

		// Suggested bundles: content management is always recommended;
		// the rest are suggested only when their matching plugin is
		// actually detected – never suggest enabling something with no
		// real reason to.
		$suggested = array( 'content' => true );
		if ( $detected['yoast'] || $detected['rankmath'] || $detected['aioseo'] || $detected['seopress'] ) {
			$suggested['seo'] = true;
		}
		?>
		<div class="wrap wcops-wrap">
			<hr class="wp-header-end" />
			<div class="wcops-onboarding">
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="wcops-onboarding-form" class="wcops-onboarding-card">
					<?php wp_nonce_field( 'wcops_onboarding' ); ?>
					<input type="hidden" name="action" value="wcops_onboarding" />

					<div class="wcops-onboarding-progress">
						<span class="wcops-onboarding-progress-dot" data-progress-dot="1"></span>
						<span class="wcops-onboarding-progress-dot" data-progress-dot="2"></span>
						<span class="wcops-onboarding-progress-dot" data-progress-dot="3"></span>
						<span class="wcops-onboarding-progress-dot" data-progress-dot="4"></span>
					</div>

					<div class="wcops-onboarding-step is-active" data-step="1">
						<?php WCOPS_Icons::render( 'sparkles', 'wcops-onboarding-icon' ); ?>
						<h1>Welcome to WindCodex Ops</h1>
						<p>This connects an AI platform (Claude, ChatGPT, or anything else that speaks MCP) to your site through a set of safe, pre-approved actions – never raw code execution.</p>
						<p>Every content change is undoable for 72 hours, and nothing high-risk runs without a preview first. Let's get you connected.</p>
						<div class="wcops-onboarding-actions-row">
							<button type="button" class="button button-primary wcops-btn-primary" data-onboarding-next>Get started</button>
							<button type="submit" name="skip" value="1" class="button wcops-btn-secondary">Skip setup</button>
						</div>
					</div>

					<div class="wcops-onboarding-step wcops-onboarding-step-wide" data-step="2">
						<?php WCOPS_Icons::render( 'search', 'wcops-onboarding-icon' ); ?>
						<h1>We scanned your site</h1>
						<p>Here's what we found, and what we suggest turning on</p>

						<div class="wcops-onboarding-detected-label">Detected plugins</div>
						<div class="wcops-onboarding-chips">
							<?php if ( $detected['woocommerce'] ) : ?><span class="wcops-chip"><?php WCOPS_Icons::render( 'shopping-cart' ); ?> WooCommerce</span><?php endif; ?>
							<?php if ( $detected['elementor'] ) : ?><span class="wcops-chip"><?php WCOPS_Icons::render( 'stack-2' ); ?> Elementor</span><?php endif; ?>
							<?php if ( $detected['acf'] ) : ?><span class="wcops-chip"><?php WCOPS_Icons::render( 'database' ); ?> Advanced Custom Fields</span><?php endif; ?>
							<?php if ( $detected['yoast'] ) : ?><span class="wcops-chip"><?php WCOPS_Icons::render( 'search' ); ?> Yoast SEO</span><?php endif; ?>
							<?php if ( $detected['rankmath'] ) : ?><span class="wcops-chip"><?php WCOPS_Icons::render( 'search' ); ?> Rank Math</span><?php endif; ?>
							<?php if ( $detected['aioseo'] ) : ?><span class="wcops-chip"><?php WCOPS_Icons::render( 'search' ); ?> All in One SEO</span><?php endif; ?>
							<?php if ( $detected['seopress'] ) : ?><span class="wcops-chip"><?php WCOPS_Icons::render( 'search' ); ?> SEOPress</span><?php endif; ?>
							<?php if ( ! array_filter( $detected ) ) : ?><span class="wcops-chip is-muted">None of the common ones – that's fine, the defaults below still apply.</span><?php endif; ?>
						</div>

						<div class="wcops-onboarding-detected-label">Suggested to enable now</div>
						<div class="wcops-tool-list wcops-tool-list-left">
							<?php foreach ( $suggested as $bundle_key => $on ) : ?>
								<?php $bundle = $bundles[ $bundle_key ]; ?>
								<div class="wcops-tool-row">
									<?php WCOPS_Icons::render( $bundle['icon'], 'wcops-tool-row-icon' ); ?>
									<div class="wcops-tool-row-main">
										<div class="wcops-tool-row-title-line">
											<span class="wcops-tool-row-title"><?php echo esc_html( $bundle['title'] ); ?></span>
											<span class="wcops-risk-badge is-<?php echo esc_attr( $bundle['risk'] ?? 'low' ); ?>"><?php echo esc_html( ucfirst( $bundle['risk'] ?? 'low' ) ); ?> risk</span>
										</div>
										<p class="wcops-tool-row-desc">
											<?php
											if ( 'content' === $bundle_key ) {
												echo 'Always recommended';
											} elseif ( 'seo' === $bundle_key ) {
												echo 'SEO plugin detected';
											}
											?>
										</p>
									</div>
									<label class="wcops-check">
										<input type="checkbox" name="bundle_<?php echo esc_attr( $bundle_key ); ?>" value="1" checked="checked" />
										<span class="wcops-check-box"><?php WCOPS_Icons::render( 'check' ); ?></span>
									</label>
								</div>
							<?php endforeach; ?>
						</div>

						<div class="wcops-onboarding-actions-row">
							<button type="button" class="button button-primary wcops-btn-primary" data-onboarding-next>Enable selected groups</button>
							<button type="button" class="button wcops-btn-secondary" data-onboarding-skip-suggestions>Skip for now</button>
						</div>
						<button type="button" class="wcops-onboarding-back" data-onboarding-back>Back</button>
					</div>

					<div class="wcops-onboarding-step" data-step="3">
						<h1>Connect an AI platform</h1>
						<p>In your AI platform's connector settings, add a new custom connector with this URL. It handles authorization from there.</p>
						<?php $this->render_copy_field( 'wcops-onboarding-endpoint', $endpoint ); ?>
						<p class="wcops-field-desc">Full setup details, including Client ID/Secret if your platform needs manual entry, are always on the Connections tab.</p>
						<div class="wcops-onboarding-actions-row">
							<button type="button" class="button button-primary wcops-btn-primary" data-onboarding-next>Continue</button>
						</div>
						<button type="button" class="wcops-onboarding-back" data-onboarding-back>Back</button>
					</div>

					<div class="wcops-onboarding-step" data-step="4">
						<?php WCOPS_Icons::render( 'circle-check', 'wcops-onboarding-icon is-success' ); ?>
						<h1>You're set up</h1>
						<p>WindCodex Ops is ready. You can adjust enabled tools, review activity, and manage connections any time from the tabs above.</p>
						<div class="wcops-onboarding-actions-row">
							<button type="submit" class="button button-primary wcops-btn-primary">Finish</button>
						</div>
					</div>
				</form>
			</div>
		</div>
		<?php
		wp_register_script( 'wcops-admin-onboarding', false, array(), WCOPS_VERSION, true );
		wp_enqueue_script( 'wcops-admin-onboarding' );
		wp_add_inline_script(
			'wcops-admin-onboarding',
			<<<'JS'
			document.addEventListener('DOMContentLoaded', function () {
				var steps = document.querySelectorAll('.wcops-onboarding-step');
				var progressDots = document.querySelectorAll('[data-progress-dot]');
				function showStep(n) {
					steps.forEach(function (s) {
						s.classList.toggle('is-active', parseInt(s.getAttribute('data-step'), 10) === n);
					});
					progressDots.forEach(function (dot) {
						var step = parseInt(dot.getAttribute('data-progress-dot'), 10);
						dot.classList.toggle('is-active', step === n);
						dot.classList.toggle('is-done', step < n);
					});
				}
				showStep(1);
				document.querySelectorAll('[data-onboarding-next]').forEach(function (btn) {
					btn.addEventListener('click', function () {
						var current = document.querySelector('.wcops-onboarding-step.is-active');
						showStep(parseInt(current.getAttribute('data-step'), 10) + 1);
					});
				});
				document.querySelectorAll('[data-onboarding-back]').forEach(function (btn) {
					btn.addEventListener('click', function () {
						var current = document.querySelector('.wcops-onboarding-step.is-active');
						showStep(parseInt(current.getAttribute('data-step'), 10) - 1);
					});
				});
				document.querySelectorAll('[data-onboarding-skip-suggestions]').forEach(function (btn) {
					btn.addEventListener('click', function () {
						var current = document.querySelector('.wcops-onboarding-step.is-active');
						current.querySelectorAll('input[type="checkbox"]').forEach(function (cb) { cb.checked = false; });
						showStep(parseInt(current.getAttribute('data-step'), 10) + 1);
					});
				});
				document.querySelectorAll('.wcops-copy-btn').forEach(function (btn) {
					btn.addEventListener('click', function () {
						var input = document.getElementById(btn.getAttribute('data-copy-target'));
						if (!input) { return; }
						input.select();
						navigator.clipboard && navigator.clipboard.writeText(input.value);
					});
				});
			});
			JS
		);
	}

	public function handle_undo_change() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not allowed.' );
		}
		check_admin_referer( 'wcops_undo_change' );

		$id     = absint( $_POST['change_id'] ?? 0 );
		$result = WCOPS_Undo_Log::restore( $id, $this->settings->get_undo_window_hours() );

		$query_arg = is_wp_error( $result ) ? 'wcops_undo_failed' : 'wcops_undo_done';
		wp_safe_redirect( add_query_arg( $query_arg, '1', $this->tab_url( 'log' ) ) );
		exit;
	}

	public function handle_save_tools() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not allowed.' );
		}
		check_admin_referer( 'wcops_save_tools' );

		// wp_site (basic site info) always stays on – it's read-only and
		// has no user-facing bundle of its own; every bundle's toggle
		// below sets the real groups it contains.
		$tool_groups = array( 'wp_site' => true );

		foreach ( WCOPS_Settings::bundle_metadata() as $bundle_key => $bundle ) {
			$on = ! empty( $_POST[ 'bundle_' . $bundle_key ] );
			foreach ( $bundle['groups'] as $real_group ) {
				$tool_groups[ $real_group ] = $on;
			}
		}
		// wp_site_health's bundle includes 'wp_site' as a real group too –
		// re-apply after the loop so the always-on rule above wins if the
		// health bundle was left off.
		$tool_groups['wp_site'] = true;

		$this->settings->update( array( 'tool_groups' => $tool_groups ) );

		wp_safe_redirect( add_query_arg( 'wcops_saved', '1', $this->tab_url( 'tools' ) ) );
		exit;
	}

	public function handle_rotate_oauth_secret() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not allowed.' );
		}
		check_admin_referer( 'wcops_rotate_oauth_secret' );

		WCOPS_OAuth::regenerate_static_client_secret();

		wp_safe_redirect( add_query_arg( 'wcops_oauth_rotated', '1', $this->tab_url( 'connection' ) ) );
		exit;
	}

	public function handle_revoke_oauth() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not allowed.' );
		}
		check_admin_referer( 'wcops_revoke_oauth' );

		WCOPS_OAuth::revoke_all_tokens();

		wp_safe_redirect( add_query_arg( 'wcops_oauth_revoked', '1', $this->tab_url( 'connection' ) ) );
		exit;
	}

	public function handle_clear_log() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not allowed.' );
		}
		check_admin_referer( 'wcops_clear_log' );

		WCOPS_Activity_Log::clear();

		wp_safe_redirect( $this->tab_url( 'log' ) );
		exit;
	}

	/**
	 * One shared stylesheet for the whole settings screen, printed once
	 * regardless of which tab is active. Defines the design tokens (color,
	 * radius, spacing, type) every tab's markup builds on – the same token
	 * values as WindCodex Forge, so the two plugins read as one system.
	 */
	private function print_styles() {
		ob_start();
		?>
			.wcops-wrap {
				--wcops-text: #14181F;
				--wcops-text-secondary: #6B6B63;
				--wcops-text-muted: #9C9990;
				--wcops-accent: #185FA5;
				--wcops-accent-dark: #124879;
				--wcops-on-accent: #FFFFFF;
				--wcops-risk-low: #27500A;
				--wcops-risk-low-soft: #EAF3DE;
				--wcops-risk-medium: #633806;
				--wcops-risk-medium-soft: #FAEEDA;
				--wcops-risk-high: #791F1F;
				--wcops-risk-high-soft: #FCEBEB;
				--wcops-success: #27500A;
				--wcops-success-soft: #EAF3DE;
				--wcops-danger: #791F1F;
				--wcops-danger-soft: #FCEBEB;
				--wcops-warning: #633806;
				--wcops-warning-soft: #FAEEDA;
				--wcops-border: #E3E0D8;
				--wcops-surface: #FFFFFF;
				--wcops-surface-muted: #F5F4F0;
				--wcops-radius: 14px;
				--wcops-radius-sm: 10px;
				max-width: none;
				margin-right: 20px;
				border-radius: var(--wcops-radius);
				padding: 0 0 24px;
				color: var(--wcops-text);
				font-size: 13px;
			}
			.wcops-wrap, .wcops-wrap * { box-sizing: border-box; }

			/* Sits above .wcops-wrap so any notice relocated here – ours
			   or another plugin's – renders at the very top of the page,
			   in its own default WordPress styling, instead of wherever
			   it happened to get injected. */

			.wcops-header {
				display: flex;
				align-items: center;
				justify-content: space-between;
				gap: 16px;
				flex-wrap: wrap;
				padding: 22px 26px;
				margin: 20px 0 24px;
				background: var(--wcops-surface);
				border: 0.5px solid var(--wcops-border);
				border-radius: var(--wcops-radius);
				box-shadow: 0 1px 3px rgba(20, 24, 31, 0.05);
			}
			.wcops-header-left { display: flex; align-items: center; gap: 16px; }
			.wcops-header-icon {
				width: 44px; height: 44px; padding: 10px; box-sizing: border-box; flex: none;
				background: rgba(24, 95, 165, 0.08); border: none;
				border-radius: 50%; color: var(--wcops-accent);
			}
			.wcops-header-text { display: flex; flex-direction: column; gap: 6px; }
			.wcops-header-text h1 {
				color: var(--wcops-text);
				margin: 0;
				font-size: 19px;
				font-weight: 600;
				letter-spacing: -0.01em;
				padding: 0;
				line-height: 1.25;
			}
			.wcops-header-rule { width: 28px; height: 2px; background: var(--wcops-accent); border-radius: 1px; }
			.wcops-header-text p { color: var(--wcops-text-secondary); margin: 0; font-size: 13px; font-weight: 400; line-height: 1.5; }

			.wcops-header-right { display: flex; align-items: center; gap: 12px; flex: none; }

			.wcops-help-wrap { position: relative; }
			.wcops-help-btn {
				display: inline-flex; align-items: center; gap: 5px;
				padding: 7px 13px;
				border: 1px solid var(--wcops-accent); border-radius: var(--wcops-radius-sm);
				background: var(--wcops-surface); color: var(--wcops-accent);
				font-size: 13px; font-weight: 600; font-family: inherit; line-height: 1;
				cursor: pointer;
				transition: background .15s ease;
			}
			.wcops-help-btn .dashicons { font-size: 18px; width: 18px; height: 18px; }
			.wcops-help-btn:hover,
			.wcops-help-btn.is-open { background: rgba(24, 95, 165, 0.08); }
			.wcops-help-btn:focus-visible { outline: 2px solid var(--wcops-accent); outline-offset: 2px; }
			.wcops-help-dropdown {
				position: absolute; top: calc(100% + 8px); right: 0; z-index: 9999;
				min-width: 200px; overflow: hidden;
				background: var(--wcops-surface);
				border: 0.5px solid var(--wcops-border); border-radius: var(--wcops-radius-sm);
				box-shadow: 0 4px 20px rgba(20, 24, 31, 0.12);
			}
			.wcops-help-dropdown[hidden] { display: none; }
			.wcops-help-item {
				display: flex; align-items: center; gap: 10px;
				padding: 10px 14px;
				font-size: 13px; font-weight: 500; color: var(--wcops-text);
				text-decoration: none;
				border-bottom: 0.5px solid var(--wcops-border);
				transition: background .12s ease, color .12s ease;
			}
			.wcops-help-item:last-child { border-bottom: none; }
			.wcops-help-item:hover,
			.wcops-help-item:focus { background: var(--wcops-surface-muted); color: var(--wcops-accent); box-shadow: none; }
			.wcops-help-item-icon { font-size: 16px; width: 16px; height: 16px; flex-shrink: 0; color: var(--wcops-text-muted); }
			.wcops-help-item:hover .wcops-help-item-icon,
			.wcops-help-item:focus .wcops-help-item-icon { color: var(--wcops-accent); }

			.wcops-tabs {
				display: flex; gap: 12px;
				border-bottom: 0.5px solid var(--wcops-border);
				margin: 0 0 28px; padding: 0 4px;
			}
			.wcops-tab {
				position: relative;
				display: inline-flex; align-items: center;
				padding: 15px 14px 17px;
				font-size: 13.5px; font-weight: 400; color: var(--wcops-text-secondary);
				letter-spacing: -0.005em;
				text-decoration: none;
				transition: color .15s ease;
			}
			.wcops-tab:hover { color: var(--wcops-text); }
			.wcops-tab.is-active { color: var(--wcops-text); font-weight: 600; }
			.wcops-tab.is-active::after {
				content: ""; position: absolute; left: 0; right: 0; bottom: -0.5px;
				height: 2.5px; background: var(--wcops-accent); border-radius: 2px;
			}
			.wcops-tab:focus { outline: none; box-shadow: none; }
			.wcops-tab:focus-visible {
				outline: 2px solid var(--wcops-accent); outline-offset: 3px; border-radius: 4px;
				box-shadow: none;
			}

			.wcops-lede { color: var(--wcops-text-secondary); font-size: 13px; margin: 0 0 20px; }

			.wcops-stat-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 12px; margin-bottom: 24px; }
			.wcops-stat-card {
				background: var(--wcops-surface); border: 0.5px solid var(--wcops-border); border-radius: var(--wcops-radius-sm);
				padding: 1rem;
			}
			.wcops-stat-card-label { font-size: 13px; color: var(--wcops-text-secondary); font-weight: 400; margin-bottom: 8px; }
			.wcops-stat-card-value { font-size: 28px; font-weight: 500; color: var(--wcops-text); display: flex; align-items: center; gap: 8px; line-height: 1; }
			.wcops-stat-card-sub { font-size: 12px; color: var(--wcops-text-muted); margin-top: 6px; }
			.wcops-dot { width: 8px; height: 8px; border-radius: 50%; display: inline-block; }
			.wcops-dot.is-on { background: var(--wcops-success); }
			.wcops-dot.is-off { background: var(--wcops-danger); }

			.wcops-card {
				background: var(--wcops-surface); border: 0.5px solid var(--wcops-border); border-radius: var(--wcops-radius);
				padding: 20px 24px; margin-bottom: 20px;
			}
			.wcops-card-danger { border-color: var(--wcops-danger); background: var(--wcops-danger-soft); }
			.wcops-section-title { display: flex; align-items: center; gap: 8px; font-size: 14px; font-weight: 500; color: var(--wcops-text); margin: 0 0 4px; }
			.wcops-section-title.is-danger { color: var(--wcops-danger); }
			.wcops-section-title .dashicons { font-size: 16px; width: 16px; height: 16px; color: var(--wcops-text-secondary); }
			.wcops-section-desc { font-size: 13px; color: var(--wcops-text-secondary); margin: 0 0 16px; line-height: 1.6; }

			.wcops-field-row { padding: 14px 0; border-bottom: 0.5px solid var(--wcops-border); }
			.wcops-field-row:last-child { border-bottom: none; padding-bottom: 0; }
			.wcops-field-row:first-child { padding-top: 0; }
			.wcops-field-label { font-weight: 500; font-size: 13px; color: var(--wcops-text); margin-bottom: 6px; display: block; }
			.wcops-field-desc { font-size: 12.5px; color: var(--wcops-text-secondary); margin: 6px 0 0; line-height: 1.6; }
			.wcops-field-desc code { background: var(--wcops-surface-muted); padding: 1px 5px; border-radius: 6px; font-size: 11.5px; }

			.wcops-input-copy { width: 100%; max-width: 460px; font-family: ui-monospace, monospace; font-size: 12.5px; background: var(--wcops-surface-muted); border: 0.5px solid var(--wcops-border); border-radius: var(--wcops-radius-sm); padding: 8px 10px; }
			.wcops-input-text { font-size: 13px; color: var(--wcops-text); background: var(--wcops-surface); border: 0.5px solid var(--wcops-border); border-radius: var(--wcops-radius-sm); padding: 8px 10px; }
			.wcops-input-text:focus { outline: none; border-color: var(--wcops-accent); box-shadow: 0 0 0 3px var(--wcops-accent-soft, rgba(24,95,165,.15)); }
			.wcops-input-text::placeholder { color: var(--wcops-text-muted); }
			.wcops-wrap select { font-size: 13px; color: var(--wcops-text); background: var(--wcops-surface); border: 0.5px solid var(--wcops-border); border-radius: var(--wcops-radius-sm); padding: 6px 8px; }
			.wcops-discovery-box { background: var(--wcops-surface-muted); border: 0.5px solid var(--wcops-border); border-radius: var(--wcops-radius-sm); padding: 10px 12px; display: flex; flex-direction: column; gap: 4px; max-width: 560px; }
			.wcops-discovery-box code { background: transparent; padding: 0; font-size: 12px; color: var(--wcops-text); }

			.wcops-badge { display: inline-flex; align-items: center; gap: 4px; padding: 2px 9px; border-radius: 999px; font-size: 12px; font-weight: 500; }
			.wcops-badge.is-success { background: var(--wcops-success-soft); color: var(--wcops-success); }
			.wcops-badge.is-danger { background: var(--wcops-danger-soft); color: var(--wcops-danger); }
			.wcops-badge.is-neutral { background: var(--wcops-surface-muted); color: var(--wcops-text-secondary); }

			.wcops-range-filter { display: flex; align-items: center; }
			.wcops-range-filter select { font-size: 13px; border-radius: var(--wcops-radius-sm); border-color: var(--wcops-border); }

			.wcops-tool-list { border: 0.5px solid var(--wcops-border); border-radius: var(--wcops-radius); overflow: hidden; margin: 4px 0 26px; background: var(--wcops-surface); }
			.wcops-tool-row { display: flex; align-items: center; gap: 12px; padding: 12px 16px; border-bottom: 0.5px solid var(--wcops-border); }
			.wcops-tool-row:last-child { border-bottom: none; }
			.wcops-tool-row.is-disabled { background: var(--wcops-surface-muted); opacity: .6; }
			.wcops-tool-row-icon { color: var(--wcops-text-secondary); font-size: 18px; width: 18px; height: 18px; flex: none; margin-top: 1px; }
			.wcops-tool-row-icon.is-accent { color: var(--wcops-accent); }
			.wcops-tool-row-main { flex: 1; min-width: 0; }
			.wcops-tool-row-title-line { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; margin-bottom: 2px; }
			.wcops-tool-row-title { font-weight: 500; font-size: 14px; color: var(--wcops-text); }
			.wcops-tool-row-desc { color: var(--wcops-text-secondary); font-size: 13px; line-height: 1.5; margin: 0; }

			.wcops-risk-badge {
				display: inline-flex; align-items: center; font-size: 12px; font-weight: 500;
				padding: 2px 9px; border-radius: 999px;
			}
			.wcops-risk-badge.is-low { background: var(--wcops-risk-low-soft); color: var(--wcops-risk-low); }
			.wcops-risk-badge.is-medium { background: var(--wcops-risk-medium-soft); color: var(--wcops-risk-medium); }
			.wcops-risk-badge.is-high { background: var(--wcops-risk-high-soft); color: var(--wcops-risk-high); }

			.wcops-revoke-link {
				background: none; border: none; padding: 4px 0; margin-top: 1px;
				color: var(--wcops-danger); font-size: 12.5px; font-weight: 500; cursor: pointer;
			}
			.wcops-revoke-link:hover { text-decoration: underline; }
			.wcops-revoke-link:focus { outline: none; box-shadow: none; }
			.wcops-revoke-link:focus-visible { outline: 2px solid var(--wcops-accent); outline-offset: 2px; border-radius: 2px; }
			.wcops-undo-btn {
				background: var(--wcops-surface); border: 0.5px solid var(--wcops-accent); color: var(--wcops-accent);
				border-radius: var(--wcops-radius-sm); padding: 5px 12px; font-size: 12px; font-weight: 500;
				cursor: pointer; flex: none; margin-top: 1px;
			}
			.wcops-undo-btn:hover { background: var(--wcops-surface-muted); }
			.wcops-empty-inline { color: var(--wcops-text-secondary); font-size: 13px; padding: 8px 0; }

			.wcops-undo-footnote {
				display: flex; align-items: center; gap: 8px;
				font-size: 12.5px; color: var(--wcops-text-secondary);
				background: var(--wcops-surface-muted); border: 0.5px solid var(--wcops-border); border-radius: var(--wcops-radius-sm);
				padding: 12px 16px;
			}
			.wcops-undo-footnote .dashicons { color: var(--wcops-risk-low); font-size: 16px; width: 16px; height: 16px; }

			/* Toggle switch – 36x20 track, 16px knob, 2px inset. */
			.wcops-switch { position: relative; display: inline-block; width: 36px; height: 20px; flex: none; }
			.wcops-switch input { opacity: 0; width: 0; height: 0; position: absolute; }
			.wcops-switch .wcops-slider { position: absolute; inset: 0; background: #d8d5cc; border-radius: 999px; cursor: pointer; transition: background .15s ease; }
			.wcops-switch .wcops-slider::before { content: ""; position: absolute; height: 16px; width: 16px; left: 2px; top: 2px; background: var(--wcops-on-accent); border-radius: 50%; transition: transform .15s ease; }
			.wcops-switch input:checked + .wcops-slider { background: var(--wcops-accent); }
			.wcops-switch input:checked + .wcops-slider::before { transform: translateX(16px); }
			.wcops-switch input:focus-visible + .wcops-slider { outline: 2px solid var(--wcops-accent); outline-offset: 2px; }

			/* Checkbox used only in the onboarding suggestion list (a one-time selection, not a toggle). */
			.wcops-check { position: relative; display: inline-flex; flex: none; cursor: pointer; }
			.wcops-check input { position: absolute; opacity: 0; width: 18px; height: 18px; margin: 0; cursor: pointer; }
			.wcops-check-box { width: 18px; height: 18px; border-radius: 6px; border: 0.5px solid var(--wcops-border); background: var(--wcops-surface); display: flex; align-items: center; justify-content: center; }
			.wcops-check-box .dashicons { font-size: 13px; width: 13px; height: 13px; color: var(--wcops-on-accent); display: none; }
			.wcops-check input:checked + .wcops-check-box { background: var(--wcops-accent); border-color: var(--wcops-accent); }
			.wcops-check input:checked + .wcops-check-box .dashicons { display: block; }

			.wcops-toggle-row { display: flex; align-items: center; justify-content: space-between; gap: 16px; }

			.wcops-btn-primary.button,
			.wcops-btn-secondary.button,
			.wcops-btn-danger-outline.button {
				border-radius: var(--wcops-radius-sm);
				padding: 6px 16px;
				height: auto;
				line-height: 1.5;
				font-weight: 500;
				font-size: 13px;
			}
			.wcops-btn-primary.button { background: var(--wcops-accent); border: 0.5px solid var(--wcops-accent); color: var(--wcops-on-accent); box-shadow: none; }
			.wcops-btn-primary.button:hover { background: var(--wcops-accent-dark); color: var(--wcops-on-accent); }
			.wcops-btn-primary.button:focus { box-shadow: 0 0 0 2px #fff, 0 0 0 4px var(--wcops-accent); border-color: var(--wcops-accent); }
			.wcops-btn-secondary.button { background: var(--wcops-surface); border: 0.5px solid var(--wcops-border); color: var(--wcops-text); box-shadow: none; transition: background .15s ease; }
			.wcops-btn-secondary.button:hover { background: var(--wcops-surface-muted); color: var(--wcops-text); }
			.wcops-btn-secondary.button:focus { box-shadow: 0 0 0 2px #fff, 0 0 0 4px var(--wcops-border); }
			.wcops-btn-danger-outline.button { background: var(--wcops-surface); border: 0.5px solid var(--wcops-danger); color: var(--wcops-danger); box-shadow: none; transition: background .15s ease; }
			.wcops-btn-danger-outline.button:hover { background: var(--wcops-danger-soft); color: var(--wcops-danger); }
			.wcops-btn-danger-outline.button:focus { box-shadow: 0 0 0 2px #fff, 0 0 0 4px var(--wcops-danger-soft); }

			.wcops-copy-row { display: flex; gap: 8px; align-items: stretch; max-width: 460px; }
			.wcops-copy-row .wcops-input-copy { max-width: none; flex: 1; margin: 0; }
			.wcops-copy-btn {
				flex: none; display: flex; align-items: center; justify-content: center;
				width: 34px; border-radius: var(--wcops-radius-sm); border: 0.5px solid var(--wcops-border);
				background: var(--wcops-surface); cursor: pointer; color: var(--wcops-text-secondary);
				transition: background .15s ease, color .15s ease;
			}
			.wcops-copy-btn:hover { background: var(--wcops-surface-muted); color: var(--wcops-accent); }
			.wcops-copy-btn .dashicons { font-size: 15px; width: 15px; height: 15px; }
			.wcops-copy-btn.is-copied { background: var(--wcops-success-soft); color: var(--wcops-success); }
			.wcops-copy-icon-copied { display: none; }
			.wcops-copy-btn.is-copied .wcops-copy-icon-default { display: none; }
			.wcops-copy-btn.is-copied .wcops-copy-icon-copied { display: inline-block; }

			.wcops-empty-state { text-align: center; padding: 40px 20px; color: var(--wcops-text-secondary); }
			.wcops-empty-state .dashicons { font-size: 32px; width: 32px; height: 32px; color: var(--wcops-text-muted); margin-bottom: 8px; display: block; margin-left: auto; margin-right: auto; }
			.wcops-empty-state p { margin: 0; font-size: 13px; }

			.wcops-onboarding {
				display: flex; align-items: center; justify-content: center;
				min-height: 70vh; padding: 40px 20px;
			}
			.wcops-onboarding-card {
				background: var(--wcops-surface); border: 0.5px solid var(--wcops-border);
				border-radius: var(--wcops-radius); padding: 40px 44px;
				width: 100%; max-width: 560px; margin: 0 auto;
			}

			.wcops-onboarding-progress { display: flex; align-items: center; justify-content: center; gap: 6px; margin: 0 0 28px; }
			.wcops-onboarding-progress-dot { width: 6px; height: 6px; border-radius: 999px; background: var(--wcops-border); transition: background .15s ease, width .15s ease; }
			.wcops-onboarding-progress-dot.is-done { background: var(--wcops-accent); opacity: .45; }
			.wcops-onboarding-progress-dot.is-active { background: var(--wcops-accent); width: 18px; }

			.wcops-onboarding-step { display: none; text-align: center; }
			.wcops-onboarding-step.is-active { display: block; }
			.wcops-onboarding-step .wcops-tool-list { text-align: left; margin: 16px 0 4px; }
			.wcops-onboarding-step .wcops-copy-row { margin: 16px auto; }
			.wcops-onboarding-icon { display: block; margin: 0 auto 14px; font-size: 32px; width: 32px; height: 32px; color: var(--wcops-accent); text-align: center; }
			.wcops-onboarding-icon.is-success { color: var(--wcops-risk-low); }
			.wcops-onboarding-step > h1 { font-size: 20px; font-weight: 500; margin: 0 0 8px; color: var(--wcops-text); text-align: center; }
			.wcops-onboarding-step > p { color: var(--wcops-text-secondary); font-size: 14px; line-height: 1.6; margin: 0 0 8px; text-align: center; }
			.wcops-onboarding-back {
				display: block; margin: 16px auto 0; background: none; border: none;
				color: var(--wcops-text-secondary); font-size: 12.5px; cursor: pointer; text-decoration: underline;
			}

			.wcops-onboarding-step-wide { text-align: left; }
			.wcops-onboarding-step-wide > p { margin-bottom: 24px; }
			.wcops-onboarding-detected-label { font-size: 12px; font-weight: 500; color: var(--wcops-text-muted); margin: 24px 0 10px; text-align: left; }
			.wcops-onboarding-detected-label:first-of-type { margin-top: 0; }
			.wcops-onboarding-chips { display: flex; flex-wrap: wrap; gap: 8px; }
			.wcops-chip {
				display: inline-flex; align-items: center; gap: 6px; background: var(--wcops-surface-muted);
				border: 0.5px solid var(--wcops-border); border-radius: 999px; padding: 6px 14px;
				font-size: 13px; color: var(--wcops-text);
			}
			.wcops-chip .dashicons { font-size: 14px; width: 14px; height: 14px; color: var(--wcops-text-secondary); }
			.wcops-chip.is-muted { color: var(--wcops-text-secondary); font-style: italic; border-style: dashed; }
			.wcops-tool-list-left { text-align: left; }
			.wcops-onboarding-actions-row { display: flex; gap: 10px; justify-content: center; margin-top: 24px; }
			.wcops-onboarding-actions-row .button { margin: 0; flex: 1; text-align: center; }
		<?php
		$css = ob_get_clean();
		wp_register_style( 'wcops-admin', false, array(), WCOPS_VERSION );
		wp_enqueue_style( 'wcops-admin' );
		wp_add_inline_style( 'wcops-admin', $css );

		ob_start();
		?>
		document.addEventListener('DOMContentLoaded', function () {
			document.querySelectorAll('.wcops-copy-btn').forEach(function (btn) {
				btn.addEventListener('click', function () {
					var input = document.getElementById(btn.getAttribute('data-copy-target'));
					if (!input) { return; }
					input.select();
					navigator.clipboard && navigator.clipboard.writeText(input.value).then(function () {
						btn.classList.add('is-copied');
						setTimeout(function () {
							btn.classList.remove('is-copied');
						}, 1500);
					});
				});
			});

			var helpBtn = document.getElementById('wcops-help-btn');
			var helpDropdown = document.getElementById('wcops-help-dropdown');
			if (helpBtn && helpDropdown) {
				var setHelpOpen = function (open) {
					helpDropdown.hidden = !open;
					helpBtn.classList.toggle('is-open', open);
					helpBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
				};
				helpBtn.addEventListener('click', function (e) {
					e.stopPropagation();
					setHelpOpen(helpDropdown.hidden);
				});
				helpDropdown.addEventListener('click', function (e) {
					e.stopPropagation();
				});
				document.addEventListener('click', function () {
					setHelpOpen(false);
				});
				document.addEventListener('keydown', function (e) {
					if ('Escape' === e.key && !helpDropdown.hidden) {
						setHelpOpen(false);
						helpBtn.focus();
					}
				});
			}
		});
		<?php
		$js = ob_get_clean();
		wp_register_script( 'wcops-admin-shared', false, array(), WCOPS_VERSION, true );
		wp_enqueue_script( 'wcops-admin-shared' );
		wp_add_inline_script( 'wcops-admin-shared', $js );
	}

	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$settings = $this->settings->get_all();

		if ( empty( $settings['onboarding_completed'] ) && ! isset( $_GET['tab'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only: just decides whether to show onboarding vs. the tab, no state change.
			$this->render_onboarding();
			return;
		}

		$tab = $this->current_tab();
		$this->print_styles();
		?>
		<div class="wrap wcops-wrap">
			<hr class="wp-header-end" />
			<div class="wcops-header">
				<div class="wcops-header-left">
					<?php WCOPS_Icons::render( 'sparkles', 'wcops-header-icon' ); ?>
					<div class="wcops-header-text">
						<h1>WindCodex Ops</h1>
						<span class="wcops-header-rule"></span>
						<p>Control what your AI assistant can do on this site</p>
					</div>
				</div>
				<div class="wcops-header-right">
					<div class="wcops-help-wrap">
						<button type="button" class="wcops-help-btn" id="wcops-help-btn" aria-expanded="false" aria-haspopup="true" aria-controls="wcops-help-dropdown">
							<span class="dashicons dashicons-editor-help"></span>
							<?php esc_html_e( 'Help', 'windcodex-ops' ); ?>
						</button>
						<div class="wcops-help-dropdown" id="wcops-help-dropdown" hidden>
							<a href="https://docs.windcodex.com/docs/ops" target="_blank" rel="noopener" class="wcops-help-item">
								<span class="wcops-help-item-icon dashicons dashicons-media-document"></span>
								<?php esc_html_e( 'Documentation', 'windcodex-ops' ); ?>
							</a>
							<a href="https://wordpress.org/support/plugin/windcodex-ops/" target="_blank" rel="noopener" class="wcops-help-item">
								<span class="wcops-help-item-icon dashicons dashicons-sos"></span>
								<?php esc_html_e( 'Support Forum', 'windcodex-ops' ); ?>
							</a>
							<a href="https://wordpress.org/support/plugin/windcodex-ops/reviews/#new-post" target="_blank" rel="noopener" class="wcops-help-item">
								<span class="wcops-help-item-icon dashicons dashicons-star-filled"></span>
								<?php esc_html_e( 'Submit a Review', 'windcodex-ops' ); ?>
							</a>
						</div>
					</div>
				</div>
			</div>

			<nav class="wcops-tabs">
				<?php foreach ( self::TABS as $slug => $tab_data ) : ?>
					<a href="<?php echo esc_url( $this->tab_url( $slug ) ); ?>" class="wcops-tab <?php echo esc_attr( $tab === $slug ? 'is-active' : '' ); ?>">
						<?php echo esc_html( $tab_data['label'] ); ?>
					</a>
				<?php endforeach; ?>
			</nav>

			<?php
			switch ( $tab ) {
				case 'tools':
					$this->render_tab_tools();
					break;
				case 'general':
					$this->render_tab_general();
					break;
				case 'log':
					$this->render_tab_log();
					break;
				default:
					$this->render_tab_connection();
					break;
			}
			?>
		</div>
		<?php
	}

	/* -----------------------------------------------------------------
	 * Tab: Tools
	 * ------------------------------------------------------------- */

	private function render_tab_tools() {
		$settings = $this->settings->get_all();
		$groups   = $settings['tool_groups'];
		$bundles  = WCOPS_Settings::bundle_metadata();

		// A bundle only counts toward "Groups enabled" if it's actually
		// reachable right now – on in storage, and (for Commerce
		// specifically) WooCommerce is really active.
		$enabled_bundle_count = 0;
		foreach ( $bundles as $bundle_key => $bundle ) {
			if ( ! $this->bundle_is_on( $bundle, $groups ) ) {
				continue;
			}
			if ( 'commerce' === $bundle_key && ! class_exists( 'WooCommerce' ) ) {
				continue;
			}
			$enabled_bundle_count++;
		}
		$actions_today = WCOPS_Undo_Log::get_today_count();
		?>
		<?php $this->render_suggestions_card( $bundles, $groups ); ?>

		<div class="wcops-stat-row">
			<div class="wcops-stat-card">
				<div class="wcops-stat-card-label">Groups enabled</div>
				<div class="wcops-stat-card-value"><?php echo (int) $enabled_bundle_count; ?> of <?php echo count( $bundles ); ?></div>
			</div>
			<div class="wcops-stat-card">
				<div class="wcops-stat-card-label">Actions today</div>
				<div class="wcops-stat-card-value"><?php echo (int) $actions_today; ?></div>
			</div>
			<div class="wcops-stat-card">
				<div class="wcops-stat-card-label">Undo window</div>
				<div class="wcops-stat-card-value">72h</div>
			</div>
		</div>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'wcops_save_tools' ); ?>
			<input type="hidden" name="action" value="wcops_save_tools" />

			<div class="wcops-tool-list">
				<?php foreach ( $bundles as $bundle_key => $bundle ) : ?>
					<?php $this->render_bundle_row( $bundle_key, $bundle, $groups ); ?>
				<?php endforeach; ?>
			</div>

			<?php submit_button( 'Save Settings', 'primary wcops-btn-primary' ); ?>
		</form>

		<p class="wcops-undo-footnote">
			<?php WCOPS_Icons::render( 'rotate' ); ?>
			Every change your AI makes can be undone within <?php echo (int) $this->settings->get_undo_window_hours(); ?> hours from the activity log.
		</p>
		<?php
	}

	/**
	 * A bundle counts as "on" if every real group it contains is enabled.
	 */
	private function bundle_is_on( $bundle, $groups ) {
		foreach ( $bundle['groups'] as $real_group ) {
			if ( empty( $groups[ $real_group ] ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Render a readonly value with a one-click copy button next to it.
	 */
	private function render_copy_field( $id, $value ) {
		?>
		<div class="wcops-copy-row">
			<input type="text" id="<?php echo esc_attr( $id ); ?>" readonly="readonly" class="wcops-input-copy" value="<?php echo esc_attr( $value ); ?>" onclick="this.select();" />
			<button type="button" class="wcops-copy-btn" data-copy-target="<?php echo esc_attr( $id ); ?>" title="Copy to clipboard">
				<?php WCOPS_Icons::render( 'clipboard', 'wcops-copy-icon-default' ); ?>
				<?php WCOPS_Icons::render( 'check', 'wcops-copy-icon-copied' ); ?>
			</button>
		</div>
		<?php
	}

	/**
	 * One dense list row per bundle: icon, name, risk badge, one-line
	 * description, toggle.
	 */
	private function render_bundle_row( $key, $bundle, $groups ) {
		$checked   = $this->bundle_is_on( $bundle, $groups );
		$risk      = $bundle['risk'] ?? 'low';
		$available = ( 'commerce' !== $key ) || class_exists( 'WooCommerce' );
		?>
		<div class="wcops-tool-row <?php echo esc_attr( $available ? '' : 'is-disabled' ); ?>">
			<?php WCOPS_Icons::render( $bundle['icon'], 'wcops-tool-row-icon' ); ?>
			<div class="wcops-tool-row-main">
				<div class="wcops-tool-row-title-line">
					<span class="wcops-tool-row-title"><?php echo esc_html( $bundle['title'] ); ?></span>
					<span class="wcops-risk-badge is-<?php echo esc_attr( $risk ); ?>"><?php echo esc_html( ucfirst( $risk ) ); ?> risk</span>
				</div>
				<p class="wcops-tool-row-desc"><?php echo esc_html( $bundle['desc'] ); ?><?php echo ! $available ? ' – WooCommerce isn\'t active on this site' : ''; ?></p>
			</div>
			<label class="wcops-switch">
				<input type="checkbox" name="bundle_<?php echo esc_attr( $key ); ?>" value="1" <?php checked( $checked ); ?> <?php disabled( ! $available ); ?> />
				<span class="wcops-slider"></span>
			</label>
		</div>
		<?php
	}

	/**
	 * Shown at the top of the Settings tab only when this site has a
	 * detected plugin whose matching bundle isn't enabled yet. Unlike the
	 * old standalone Onboarding tab, a bundle drops off this list the
	 * moment it's turned on – it never re-suggests something already
	 * enabled, so it disappears entirely once there's nothing left to act on.
	 */
	private function render_suggestions_card( $bundles, $groups ) {
		$detected = WCOPS_Settings::detect_related_plugins();

		$candidates = array( 'content' => 'Recommended for every site' );
		if ( $detected['yoast'] ) {
			$candidates['seo'] = 'Yoast SEO detected';
		} elseif ( $detected['rankmath'] ) {
			$candidates['seo'] = 'Rank Math detected';
		} elseif ( $detected['aioseo'] ) {
			$candidates['seo'] = 'All in One SEO detected';
		} elseif ( $detected['seopress'] ) {
			$candidates['seo'] = 'SEOPress detected';
		}

		$suggested = array();
		foreach ( $candidates as $bundle_key => $reason ) {
			if ( ! $this->bundle_is_on( $bundles[ $bundle_key ], $groups ) ) {
				$suggested[ $bundle_key ] = $reason;
			}
		}

		if ( empty( $suggested ) ) {
			return;
		}
		?>
		<div class="wcops-card">
			<div class="wcops-section-title"><?php WCOPS_Icons::render( 'sparkles' ); ?> Suggested for you</div>
			<p class="wcops-section-desc">Based on the plugins detected on this site, these groups aren't enabled yet.</p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'wcops_onboarding' ); ?>
				<input type="hidden" name="action" value="wcops_onboarding" />

				<div class="wcops-tool-list" style="margin-bottom:14px;">
					<?php foreach ( $suggested as $bundle_key => $reason ) : ?>
						<?php $bundle = $bundles[ $bundle_key ]; ?>
						<div class="wcops-tool-row">
							<?php WCOPS_Icons::render( $bundle['icon'], 'wcops-tool-row-icon' ); ?>
							<div class="wcops-tool-row-main">
								<div class="wcops-tool-row-title-line">
									<span class="wcops-tool-row-title"><?php echo esc_html( $bundle['title'] ); ?></span>
								</div>
								<p class="wcops-tool-row-desc"><?php echo esc_html( $reason ); ?></p>
							</div>
							<label class="wcops-check">
								<input type="checkbox" name="bundle_<?php echo esc_attr( $bundle_key ); ?>" value="1" checked="checked" />
								<span class="wcops-check-box"><?php WCOPS_Icons::render( 'check' ); ?></span>
							</label>
						</div>
					<?php endforeach; ?>
				</div>

				<button type="submit" class="button button-primary wcops-btn-primary">Enable selected</button>
				<button type="submit" name="skip" value="1" class="button wcops-btn-secondary">Not now</button>
			</form>
		</div>
		<?php
	}

	/* -----------------------------------------------------------------
	 * Tab: Connections
	 * ------------------------------------------------------------- */

	private function render_tab_connection() {
		$settings   = $this->settings->get_all();
		$endpoint   = WCOPS_Server::instance()->get_endpoint_url();
		$client     = WCOPS_OAuth::get_static_client();
		$issuer_url = untrailingslashit( home_url() );
		$clients    = WCOPS_OAuth::list_all_clients_with_activity();
		?>
		<p class="wcops-lede">Connect Claude, ChatGPT, or any other MCP-compatible AI platform to this site using the details below.</p>

		<div class="wcops-card">
			<div class="wcops-section-title"><?php WCOPS_Icons::render( 'link' ); ?> Connector URL</div>
			<p class="wcops-section-desc">Paste this into the platform's "Add custom connector" form. Set Transport to <strong>Streamable HTTP</strong> where asked.</p>
			<?php $this->render_copy_field( 'wcops-connector-url', $endpoint ); ?>
		</div>

		<div class="wcops-card">
			<div class="wcops-section-title"><?php WCOPS_Icons::render( 'key' ); ?> OAuth</div>
			<p class="wcops-section-desc">Most platforms register themselves automatically. If auto-registration fails, paste this Client ID/Secret into that platform's Advanced/OAuth settings instead.</p>

			<div class="wcops-field-row">
				<span class="wcops-field-label">Client ID</span>
				<?php $this->render_copy_field( 'wcops-client-id', $client->client_id ?? '' ); ?>
			</div>
			<div class="wcops-field-row">
				<span class="wcops-field-label">Client Secret</span>
				<?php $this->render_copy_field( 'wcops-client-secret', $client->client_secret ?? '' ); ?>
				<p class="wcops-field-desc">
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('This breaks anything currently using the old secret. Continue?');" style="margin-top:8px;">
						<?php wp_nonce_field( 'wcops_rotate_oauth_secret' ); ?>
						<input type="hidden" name="action" value="wcops_rotate_oauth_secret" />
						<button type="submit" class="button wcops-btn-secondary">Regenerate Client Secret</button>
					</form>
				</p>
			</div>
			<div class="wcops-field-row">
				<span class="wcops-field-label">Discovery URLs</span>
				<div class="wcops-discovery-box">
					<code><?php echo esc_html( $issuer_url . '/.well-known/oauth-authorization-server/wcops' ); ?></code>
					<code><?php echo esc_html( $issuer_url . '/.well-known/oauth-protected-resource/wcops' ); ?></code>
				</div>
				<p class="wcops-field-desc">
					<span class="wcops-badge is-neutral">Auto-discovered</span>&nbsp;Found automatically by MCP clients via a 401 challenge – you shouldn't need to paste these anywhere. These are scoped specifically to WindCodex Ops so they won't collide with another WindCodex plugin active on the same site.
				</p>
			</div>
			<div class="wcops-field-row">
				<span class="wcops-field-label">Auto-registration</span>
				<p class="wcops-field-desc">
					Currently <span class="wcops-badge <?php echo esc_attr( $settings['allow_dynamic_registration'] ? 'is-success' : 'is-neutral' ); ?>"><?php echo esc_html( $settings['allow_dynamic_registration'] ? 'Allowed' : 'Disabled' ); ?></span>
					&nbsp;– change this under the <a href="<?php echo esc_url( $this->tab_url( 'general' ) ); ?>">General</a> tab.
				</p>
			</div>
			<div class="wcops-field-row">
				<span class="wcops-field-label">Revoke access</span>
				<p class="wcops-field-desc" style="margin-top:8px;">
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('This signs out every app connected via OAuth. Continue?');">
						<?php wp_nonce_field( 'wcops_revoke_oauth' ); ?>
						<input type="hidden" name="action" value="wcops_revoke_oauth" />
						<button type="submit" class="button wcops-btn-danger-outline">Revoke All OAuth Access</button>
					</form>
				</p>
			</div>
		</div>

		<div class="wcops-card">
			<div class="wcops-section-title"><?php WCOPS_Icons::render( 'plug' ); ?> Connected apps</div>
			<p class="wcops-section-desc">Every AI platform currently authorized to reach this site through WindCodex Ops.</p>

			<?php if ( empty( $clients ) ) : ?>
				<p class="wcops-empty-inline">No apps have connected yet.</p>
			<?php endif; ?>

			<div class="wcops-tool-list"<?php echo empty( $clients ) ? ' style="margin-top:12px;"' : ''; ?>>
				<?php foreach ( $clients as $client_row ) : ?>
					<?php
					$client_label     = $client_row->client_name ?: $client_row->client_id;
					$client_icon_name = ( false !== stripos( $client_label, 'gpt' ) || false !== stripos( $client_label, 'openai' ) ) ? 'brand-openai' : 'message-circle-2';
					?>
					<div class="wcops-tool-row">
						<?php WCOPS_Icons::render( $client_icon_name, 'wcops-tool-row-icon' ); ?>
						<div class="wcops-tool-row-main">
							<div class="wcops-tool-row-title-line">
								<span class="wcops-tool-row-title"><?php echo esc_html( $client_row->client_name ?: $client_row->client_id ); ?></span>
							</div>
							<p class="wcops-tool-row-desc">
								Connected <?php echo esc_html( human_time_diff( strtotime( $client_row->created_at . ' UTC' ) ) ); ?> ago
								· last used <?php echo $client_row->last_used ? esc_html( human_time_diff( strtotime( $client_row->last_used . ' UTC' ) ) ) . ' ago' : 'never'; ?>

							<?php
							$acting_user = $client_row->acting_user_id ? get_userdata( $client_row->acting_user_id ) : false;
							if ( $acting_user ) :
								$role_label = ! empty( $acting_user->roles ) ? ucfirst( $acting_user->roles[0] ) : 'No role';
								?>
								· acting as <?php echo esc_html( $acting_user->display_name ); ?> (<?php echo esc_html( $role_label ); ?>)
							<?php else : ?>
								· registered, but never signed in
							<?php endif; ?></p>
						</div>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('Revoke access for \'<?php echo esc_js( $client_row->client_name ?: $client_row->client_id ); ?>\'? It will need to reauthorize to reconnect.');">
							<?php wp_nonce_field( 'wcops_revoke_client' ); ?>
							<input type="hidden" name="action" value="wcops_revoke_client" />
							<input type="hidden" name="client_id" value="<?php echo esc_attr( $client_row->client_id ); ?>" />
							<button type="submit" class="wcops-revoke-link">Revoke</button>
						</form>
					</div>
				<?php endforeach; ?>
				<div class="wcops-tool-row">
					<?php WCOPS_Icons::render( 'plus', 'wcops-tool-row-icon is-accent' ); ?>
					<div class="wcops-tool-row-main">
						<span class="wcops-tool-row-title" style="color:var(--wcops-accent);">Add a new connection</span>
						<p class="wcops-tool-row-desc">This plugin doesn't start connections itself – in your AI platform's own settings, add a custom connector pointing at the Connector URL above; the platform initiates authorization from its side.</p>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	/* -----------------------------------------------------------------
	 * Tab: General
	 * ------------------------------------------------------------- */

	private function render_tab_general() {
		$settings = $this->settings->get_all();
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'wcops_save_general' ); ?>
			<input type="hidden" name="action" value="wcops_save_general" />

			<div class="wcops-card">
				<div class="wcops-section-title"><?php WCOPS_Icons::render( 'server-2' ); ?> Connector</div>

				<div class="wcops-field-row wcops-toggle-row">
					<div>
						<span class="wcops-field-label">Connector status</span>
						<p class="wcops-field-desc">Turn off to immediately stop every connected AI platform from reaching this site, without deleting your configuration.</p>
					</div>
					<label class="wcops-switch"><input type="checkbox" name="enabled" value="1" <?php checked( $settings['enabled'] ); ?> /><span class="wcops-slider"></span></label>
				</div>

				<div class="wcops-field-row wcops-toggle-row">
					<div>
						<span class="wcops-field-label">Read-only mode</span>
						<p class="wcops-field-desc">Only expose read tools – no create/update/delete tools of any kind.</p>
					</div>
					<label class="wcops-switch"><input type="checkbox" name="read_only" value="1" <?php checked( $settings['read_only'] ); ?> /><span class="wcops-slider"></span></label>
				</div>

				<div class="wcops-field-row">
					<span class="wcops-field-label">Who can connect</span>
					<select name="connect_capability">
						<?php foreach ( WCOPS_Settings::connect_capability_choices() as $capability => $label ) : ?>
							<option value="<?php echo esc_attr( $capability ); ?>" <?php selected( $this->settings->get_connect_capability(), $capability ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
					<p class="wcops-field-desc">Who may approve an AI connection on the consent screen. Each connection acts with that person's own permissions, and stops working as soon as they no longer have this role. Revoke individual connections on the Connection tab.</p>
				</div>

				<div class="wcops-field-row">
					<span class="wcops-field-label">Max results per list call</span>
					<input type="number" min="1" max="200" name="max_results_limit" value="<?php echo esc_attr( $settings['max_results_limit'] ); ?>" class="wcops-input-text" style="width:80px;" />
				</div>

				<div class="wcops-field-row">
					<span class="wcops-field-label">Requests per minute</span>
					<input type="number" min="10" max="600" name="requests_per_minute_limit" value="<?php echo esc_attr( $settings['requests_per_minute_limit'] ?? 120 ); ?>" class="wcops-input-text" style="width:80px;" />
					<p class="wcops-field-desc">Per-user cap on tool calls through the MCP connector. Default 120 - allowed range 10 to 600.</p>
				</div>

				<div class="wcops-field-row wcops-toggle-row">
					<div>
						<span class="wcops-field-label">OAuth auto-registration</span>
						<p class="wcops-field-desc">Allow platforms to register themselves automatically. Turn off to only ever use the static Client ID/Secret on the Connection tab.</p>
					</div>
					<label class="wcops-switch"><input type="checkbox" name="allow_dynamic_registration" value="1" <?php checked( $settings['allow_dynamic_registration'] ); ?> /><span class="wcops-slider"></span></label>
				</div>
			</div>

			<div class="wcops-card">
				<div class="wcops-section-title"><?php WCOPS_Icons::render( 'shield-check' ); ?> Preferences</div>

				<div class="wcops-field-row wcops-toggle-row">
					<div>
						<span class="wcops-field-label">Require preview before high-risk actions</span>
						<p class="wcops-field-desc">Actions that can't be undone – permanently deleting media, recompressing an image, deleting a category or tag, removing a menu item, navigation item or widget – first return a preview, and only run when the AI calls again to confirm. Bulk tools always preview first, whatever this is set to.</p>
					</div>
					<label class="wcops-switch"><input type="checkbox" name="require_preview_on_high_risk" value="1" <?php checked( $settings['require_preview_on_high_risk'] ?? true ); ?> /><span class="wcops-slider"></span></label>
				</div>

				<div class="wcops-field-row wcops-toggle-row">
					<div>
						<span class="wcops-field-label">Show activity feed</span>
						<p class="wcops-field-desc">List every AI action – what ran, when, and whether it succeeded – on the Activity tab so you can see at a glance what the AI has been doing. Turning this off stops recording new calls; it doesn't touch the undo guarantee below.</p>
					</div>
					<label class="wcops-switch"><input type="checkbox" name="log_ai_activity" value="1" <?php checked( $settings['log_ai_activity'] ?? true ); ?> /><span class="wcops-slider"></span></label>
				</div>

				<div class="wcops-field-row wcops-toggle-row">
					<div>
						<span class="wcops-field-label">Keep undo history</span>
						<p class="wcops-field-desc">Save a before/after snapshot of every post, page, category, and tag change the AI makes, so any of them can be undone within the undo window below. Turning this off is permanent for that period: changes made while it's off can't be recovered later.</p>
					</div>
					<label class="wcops-switch"><input type="checkbox" name="enable_undo_log" value="1" <?php checked( $settings['enable_undo_log'] ?? true ); ?> /><span class="wcops-slider"></span></label>
				</div>

				<div class="wcops-field-row">
					<span class="wcops-field-label">Undo window</span>
					<select name="undo_window_hours">
						<?php foreach ( array( 72, 96, 168 ) as $hours ) : ?>
							<option value="<?php echo (int) $hours; ?>" <?php selected( $settings['undo_window_hours'] ?? 72, $hours ); ?>><?php echo (int) $hours; ?> hours</option>
						<?php endforeach; ?>
					</select>
					<p class="wcops-field-desc">How long changes stay reversible, as long as "Keep undo history" above is on. 72 hours is the floor – you can extend it, never shorten it below that.</p>
				</div>

				<div class="wcops-field-row wcops-toggle-row">
					<div>
						<span class="wcops-field-label">Email me on high-risk actions</span>
						<p class="wcops-field-desc">Email the site admin address (<?php echo esc_html( get_option( 'admin_email' ) ); ?>) each time a high-risk action actually runs – the tools above plus confirmed bulk changes. Limited to 10 emails an hour.</p>
					</div>
					<label class="wcops-switch"><input type="checkbox" name="email_alerts_on_high_risk" value="1" <?php checked( $settings['email_alerts_on_high_risk'] ?? false ); ?> /><span class="wcops-slider"></span></label>
				</div>

				<div class="wcops-field-row wcops-toggle-row">
					<div>
						<span class="wcops-field-label">Weekly activity summary</span>
						<p class="wcops-field-desc">Once a week, email the site admin address a summary of tool calls, changes and high-risk actions from the last 7 days. Skipped in weeks with no activity.</p>
					</div>
					<label class="wcops-switch"><input type="checkbox" name="weekly_activity_summary" value="1" <?php checked( $settings['weekly_activity_summary'] ?? true ); ?> /><span class="wcops-slider"></span></label>
				</div>
			</div>

			<div class="wcops-card">
				<div class="wcops-section-title"><?php WCOPS_Icons::render( 'shield' ); ?> Data &amp; Privacy</div>
				<div class="wcops-field-row wcops-toggle-row">
					<div>
						<span class="wcops-field-label">Delete all data on uninstall</span>
						<p class="wcops-field-desc">When off (default), deleting this plugin from the Plugins screen leaves your settings, OAuth clients/tokens, activity log, and undo history in place – reinstalling later restores everything as it was. Turn this on if you want a full cleanup (settings, database tables, and all) the next time the plugin is deleted.</p>
					</div>
					<label class="wcops-switch"><input type="checkbox" name="delete_data_on_uninstall" value="1" <?php checked( $settings['delete_data_on_uninstall'] ?? false ); ?> /><span class="wcops-slider"></span></label>
				</div>
			</div>

			<?php submit_button( 'Save Settings', 'primary wcops-btn-primary' ); ?>
		</form>

		<div class="wcops-card">
			<div class="wcops-section-title"><?php WCOPS_Icons::render( 'key' ); ?> Authentication</div>

			<div class="wcops-field-row">
				<span class="wcops-field-label">Method</span>
				<p class="wcops-field-desc">OAuth 2.1 with PKCE. Every connected app authenticates as a real WordPress user – there's no separate always-on API key granting blanket access.</p>
			</div>
		</div>

		<div class="wcops-card">
			<div class="wcops-section-title"><?php WCOPS_Icons::render( 'info-circle' ); ?> About</div>
			<div class="wcops-field-row">
				<span class="wcops-field-label">Site name</span>
				<p class="wcops-field-desc" style="margin:0;"><?php echo esc_html( get_bloginfo( 'name' ) ); ?></p>
			</div>
			<div class="wcops-field-row">
				<span class="wcops-field-label">Plugin version</span>
				<p class="wcops-field-desc" style="margin:0;"><?php echo esc_html( WCOPS_VERSION ); ?></p>
			</div>
		</div>
		<?php
	}

	/* -----------------------------------------------------------------
	 * Tab: Activity
	 * ------------------------------------------------------------- */

	private function render_tab_log() {
		$ranges = array(
			'24h' => 'Last 24 hours',
			'7d'  => 'Last 7 days',
			'30d' => 'Last 30 days',
			'all' => 'All time',
		);
		$range = sanitize_key( $_GET['range'] ?? '24h' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only: just filters which log rows to display, no state change.
		if ( ! isset( $ranges[ $range ] ) ) {
			$range = '24h';
		}
		$cutoff_map = array(
			'24h' => '-24 hours',
			'7d'  => '-7 days',
			'30d' => '-30 days',
			'all' => null,
		);
		$cutoff_ts = $cutoff_map[ $range ] ? strtotime( $cutoff_map[ $range ] ) : null;

		$changes      = WCOPS_Undo_Log::get_recent( 200 );
		$calls        = WCOPS_Activity_Log::get_recent( 200 );
		$window_hours = $this->settings->get_undo_window_hours();

		if ( null !== $cutoff_ts ) {
			$changes = array_filter( $changes, function ( $c ) use ( $cutoff_ts ) {
				return strtotime( $c->created_at . ' UTC' ) >= $cutoff_ts;
			} );
			$calls = array_filter( $calls, function ( $c ) use ( $cutoff_ts ) {
				return strtotime( $c->created_at . ' UTC' ) >= $cutoff_ts;
			} );
		}
		?>
		<p class="wcops-lede">Every action any connected AI takes is recorded here, whether it succeeded or failed. Changes to content can be undone directly from this page – no need to ask the AI to do it.</p>

		<div class="wcops-card">
			<div class="wcops-toggle-row" style="margin-bottom:16px;">
				<div class="wcops-section-title" style="margin:0;">
					<?php WCOPS_Icons::render( 'rotate' ); ?> Changes
					<span class="wcops-badge is-neutral"><?php echo (int) $window_hours; ?>h undo window</span>
				</div>
				<form method="get" class="wcops-range-filter">
					<input type="hidden" name="page" value="windcodex-ops" />
					<input type="hidden" name="tab" value="log" />
					<select name="range" onchange="this.form.submit()">
						<?php foreach ( $ranges as $value => $label ) : ?>
							<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $range, $value ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</form>
			</div>

			<?php if ( empty( $changes ) ) : ?>
				<div class="wcops-empty-state">
					<?php WCOPS_Icons::render( 'rotate' ); ?>
					<p>No changes recorded yet. Content edits made through any connected AI will show up here, undoable individually.</p>
				</div>
			<?php else : ?>
				<div class="wcops-tool-list" style="margin-bottom:0; max-height:799px; overflow-y:auto;">
					<?php foreach ( $changes as $change ) : ?>
						<?php
						$user       = get_userdata( $change->user_id );
						$expires_at = strtotime( $change->created_at . ' UTC' ) + ( $window_hours * HOUR_IN_SECONDS );
						$can_undo   = ! $change->restored && time() <= $expires_at;
						?>
						<div class="wcops-tool-row">
							<?php WCOPS_Icons::render( 'delete' === $change->action ? 'trash' : 'file-text', 'wcops-tool-row-icon' ); ?>
							<div class="wcops-tool-row-main">
								<div class="wcops-tool-row-title-line">
									<span class="wcops-tool-row-title"><?php echo esc_html( $change->summary ); ?></span>
									<?php if ( $change->restored ) : ?>
										<span class="wcops-badge is-neutral">Undone</span>
									<?php elseif ( ! $can_undo ) : ?>
										<span class="wcops-badge is-neutral">Expired</span>
									<?php endif; ?>
								</div>
								<p class="wcops-tool-row-desc"><?php echo esc_html( $change->created_at ); ?> UTC – <?php echo esc_html( $user ? $user->user_login : "user #{$change->user_id}" ); ?></p>
							</div>
							<?php if ( $can_undo ) : ?>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('Undo this change? It will be restored to how it looked before.');">
									<?php wp_nonce_field( 'wcops_undo_change' ); ?>
									<input type="hidden" name="action" value="wcops_undo_change" />
									<input type="hidden" name="change_id" value="<?php echo (int) $change->id; ?>" />
									<button type="submit" class="wcops-undo-btn">Undo</button>
								</form>
							<?php else : ?>
								<?php if ( ! $change->restored ) : ?><span class="wcops-field-desc" style="margin:0;">Expired</span><?php endif; ?>
							<?php endif; ?>
						</div>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</div>

		<div class="wcops-card">
			<div class="wcops-toggle-row" style="margin-bottom:16px;">
				<div class="wcops-section-title" style="margin:0;">
					<?php WCOPS_Icons::render( 'clipboard' ); ?> All calls
				</div>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('Delete the entire activity log?');">
					<?php wp_nonce_field( 'wcops_clear_log' ); ?>
					<input type="hidden" name="action" value="wcops_clear_log" />
					<button type="submit" class="button wcops-btn-secondary">Clear Log</button>
				</form>
			</div>

			<?php if ( empty( $calls ) ) : ?>
				<div class="wcops-empty-state">
					<?php WCOPS_Icons::render( 'clipboard' ); ?>
					<p>No calls recorded yet.</p>
				</div>
			<?php else : ?>
				<div class="wcops-tool-list" style="margin-bottom:0; max-height:799px; overflow-y:auto;">
					<?php foreach ( $calls as $entry ) : ?>
						<?php $user = get_userdata( $entry->user_id ); ?>
						<div class="wcops-tool-row">
							<?php WCOPS_Icons::render( $entry->success ? 'circle-check' : 'alert-triangle', 'wcops-tool-row-icon' ); ?>
							<div class="wcops-tool-row-main">
								<div class="wcops-tool-row-title-line">
									<span class="wcops-tool-row-title" style="font-family:ui-monospace, monospace; font-size:13px;"><?php echo esc_html( $entry->tool_name ); ?></span>
									<span class="wcops-badge <?php echo esc_attr( $entry->success ? 'is-success' : 'is-danger' ); ?>"><?php echo esc_html( $entry->success ? 'OK' : 'Failed' ); ?></span>
								</div>
								<p class="wcops-tool-row-desc"><?php echo esc_html( $entry->created_at ); ?> UTC – <?php echo esc_html( $user ? $user->user_login : "user #{$entry->user_id}" ); ?></p>
							</div>
						</div>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}
}
