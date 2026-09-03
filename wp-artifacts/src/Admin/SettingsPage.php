<?php
/**
 * Settings → Artifacts.
 *
 * @package WPArtifacts
 */

declare( strict_types = 1 );

namespace WPArtifacts\Admin;

use WPArtifacts\Serving\Responder;
use WPArtifacts\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * One options page, one option.
 */
final class SettingsPage {

	public const SLUG = 'wp-artifacts';

	/**
	 * Singleton instance.
	 *
	 * @var SettingsPage|null
	 */
	private static ?SettingsPage $instance = null;

	/**
	 * Singleton accessor.
	 *
	 * @return SettingsPage
	 */
	public static function instance(): SettingsPage {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Wires hooks.
	 *
	 * @return void
	 */
	public function hooks(): void {
		add_action( 'admin_menu', array( $this, 'add_page' ) );
		add_action( 'admin_init', array( $this, 'register' ) );
		add_filter( 'plugin_action_links_' . WP_ARTIFACTS_BASENAME, array( $this, 'action_links' ) );
	}

	/**
	 * Adds the page under Settings.
	 *
	 * @return void
	 */
	public function add_page(): void {
		add_options_page(
			__( 'Artifacts', 'wp-artifacts' ),
			__( 'Artifacts', 'wp-artifacts' ),
			'manage_options',
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Registers the option with the Settings API.
	 *
	 * @return void
	 */
	public function register(): void {
		register_setting(
			'wp_artifacts_settings_group',
			Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
				'default'           => Settings::defaults(),
			)
		);
	}

	/**
	 * Sanitizes and clears the runtime cache.
	 *
	 * @param mixed $values Raw values.
	 * @return array<string,mixed>
	 */
	public function sanitize( $values ): array {
		$clean = Settings::sanitize( is_array( $values ) ? $values : array() );
		Settings::flush();

		return $clean;
	}

	/**
	 * Adds a settings link on the plugins screen.
	 *
	 * @param array<int,string> $links Existing links.
	 * @return array<int,string>
	 */
	public function action_links( $links ) {
		array_unshift(
			$links,
			sprintf(
				'<a href="%1$s">%2$s</a>',
				esc_url( admin_url( 'options-general.php?page=' . self::SLUG ) ),
				esc_html__( 'Settings', 'wp-artifacts' )
			)
		);

		return $links;
	}

	/**
	 * Renders the page.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$settings = Settings::all();

		echo '<div class="wrap"><h1>' . esc_html__( 'Artifacts', 'wp-artifacts' ) . '</h1>';

		echo '<form method="post" action="options.php">';
		settings_fields( 'wp_artifacts_settings_group' );

		echo '<h2>' . esc_html__( 'URLs', 'wp-artifacts' ) . '</h2>';
		echo '<table class="form-table" role="presentation"><tbody>';

		$this->text_row(
			'prefix',
			__( 'URL prefix', 'wp-artifacts' ),
			(string) $settings['prefix'],
			sprintf(
				/* translators: %s: example URL. */
				__( 'Artifacts live at %s. Changing this flushes the rewrite rules.', 'wp-artifacts' ),
				'<code>' . esc_html( home_url( '/' . $settings['prefix'] . '/my-artifact/' ) ) . '</code>'
			)
		);

		$this->text_row(
			'archive_slug',
			__( 'Archive slug', 'wp-artifacts' ),
			(string) $settings['archive_slug'],
			sprintf(
				/* translators: %s: archive URL. */
				__( 'The list of every published artifact, at %s.', 'wp-artifacts' ),
				'<code>' . esc_html( home_url( '/' . $settings['archive_slug'] . '/' ) ) . '</code>'
			)
		);

		$this->text_row(
			'cookieless_host',
			__( 'Serve artifacts from a separate host', 'wp-artifacts' ),
			(string) $settings['cookieless_host'],
			__( 'An artifact\'s JavaScript runs on the origin that serves it, so on a multi-author site it can read a logged-in visitor\'s REST nonce. Point a second host name at this same install (for example assets.example.com) and enter it here: artifact URLs then use that host and requests on the main host are redirected. Set up the DNS and the virtual host yourself; this plugin does not touch them.', 'wp-artifacts' )
		);

		echo '</tbody></table>';

		echo '<h2>' . esc_html__( 'Headers', 'wp-artifacts' ) . '</h2>';
		echo '<table class="form-table" role="presentation"><tbody>';

		echo '<tr><th scope="row"><label for="wp_artifacts_csp_mode">' . esc_html__( 'Default Content Security Policy', 'wp-artifacts' ) . '</label></th><td>';
		echo '<select name="' . esc_attr( Settings::OPTION ) . '[csp_mode]" id="wp_artifacts_csp_mode">';
		foreach ( array(
			'strict' => __( 'Strict', 'wp-artifacts' ),
			'off'    => __( 'None', 'wp-artifacts' ),
			'custom' => __( 'Custom header value', 'wp-artifacts' ),
		) as $value => $label ) {
			printf(
				'<option value="%1$s" %2$s>%3$s</option>',
				esc_attr( $value ),
				selected( (string) $settings['csp_mode'], $value, false ),
				esc_html( $label )
			);
		}
		echo '</select>';
		printf(
			'<p><input type="text" class="large-text code" name="%1$s[csp_custom]" value="%2$s" placeholder="%3$s"></p>',
			esc_attr( Settings::OPTION ),
			esc_attr( (string) $settings['csp_custom'] ),
			esc_attr( Responder::strict_csp() )
		);
		printf(
			'<p class="description">%s</p>',
			esc_html(
				sprintf(
					/* translators: %s: the strict policy value. */
					__( 'Strict sends: %s. Individual artifacts can override this.', 'wp-artifacts' ),
					Responder::strict_csp()
				)
			)
		);
		echo '</td></tr>';

		$this->checkbox_row(
			'include_in_feeds',
			__( 'Feeds', 'wp-artifacts' ),
			__( 'Include artifacts in the site feeds', 'wp-artifacts' ),
			(bool) $settings['include_in_feeds']
		);

		echo '</tbody></table>';

		echo '<h2>' . esc_html__( 'Limits', 'wp-artifacts' ) . '</h2>';
		echo '<table class="form-table" role="presentation"><tbody>';

		$this->number_row( 'max_entry_bytes', __( 'Entry document (bytes)', 'wp-artifacts' ), (int) $settings['max_entry_bytes'] );
		$this->number_row( 'max_asset_bytes', __( 'Single asset (bytes)', 'wp-artifacts' ), (int) $settings['max_asset_bytes'] );
		$this->number_row( 'max_bundle_bytes', __( 'Whole bundle (bytes)', 'wp-artifacts' ), (int) $settings['max_bundle_bytes'] );
		$this->number_row( 'max_files', __( 'Files per bundle', 'wp-artifacts' ), (int) $settings['max_files'], false );

		echo '</tbody></table>';

		echo '<h2>' . esc_html__( 'Publishing', 'wp-artifacts' ) . '</h2>';
		echo '<table class="form-table" role="presentation"><tbody>';

		$this->checkbox_row(
			'allow_nonadmin_publish',
			__( 'Non-administrators', 'wp-artifacts' ),
			__( 'Let users without unfiltered_html publish artifacts that contain no scripts', 'wp-artifacts' ),
			(bool) $settings['allow_nonadmin_publish']
		);

		echo '<tr><th scope="row"><label for="wp_artifacts_screenshot_provider">' . esc_html__( 'Screenshots', 'wp-artifacts' ) . '</label></th><td>';
		echo '<select name="' . esc_attr( Settings::OPTION ) . '[screenshot_provider]" id="wp_artifacts_screenshot_provider">';
		foreach ( array(
			'none'     => __( 'None', 'wp-artifacts' ),
			'headless' => __( 'Local Chromium binary', 'wp-artifacts' ),
			'external' => __( 'External service', 'wp-artifacts' ),
		) as $value => $label ) {
			printf(
				'<option value="%1$s" %2$s>%3$s</option>',
				esc_attr( $value ),
				selected( (string) $settings['screenshot_provider'], $value, false ),
				esc_html( $label )
			);
		}
		echo '</select>';
		printf(
			'<p><label>%1$s<br><input type="text" class="large-text code" name="%2$s[screenshot_binary]" value="%3$s" placeholder="/usr/bin/chromium"></label></p>',
			esc_html__( 'Path to the Chromium binary', 'wp-artifacts' ),
			esc_attr( Settings::OPTION ),
			esc_attr( (string) $settings['screenshot_binary'] )
		);
		printf(
			'<p><label>%1$s<br><input type="url" class="large-text code" name="%2$s[screenshot_url_template]" value="%3$s" placeholder="https://shots.example.com/?url={url_encoded}"></label></p>',
			esc_html__( 'External service URL template', 'wp-artifacts' ),
			esc_attr( Settings::OPTION ),
			esc_attr( (string) $settings['screenshot_url_template'] )
		);
		printf(
			'<p class="description">%s</p>',
			esc_html__( 'The template must return a PNG. {url} and {url_encoded} are replaced with the artifact URL.', 'wp-artifacts' )
		);
		echo '</td></tr>';

		$this->checkbox_row(
			'delete_data_on_uninstall',
			__( 'Uninstall', 'wp-artifacts' ),
			__( 'Delete artifacts, stored bundles and settings when the plugin is deleted', 'wp-artifacts' ),
			(bool) $settings['delete_data_on_uninstall']
		);

		echo '</tbody></table>';

		submit_button();
		echo '</form>';

		$this->render_agent_notes();

		echo '</div>';
	}

	/**
	 * Shows the connection details an agent needs.
	 *
	 * @return void
	 */
	private function render_agent_notes(): void {
		echo '<h2>' . esc_html__( 'Connecting an agent', 'wp-artifacts' ) . '</h2>';

		$has_abilities = function_exists( 'wp_register_ability' );
		$has_adapter   = defined( 'MCP_ADAPTER_VERSION' ) || class_exists( '\\WP\\MCP\\Core\\McpAdapter' );

		echo '<ul class="ul-disc">';

		printf(
			'<li>%s</li>',
			$has_abilities
				? esc_html__( 'Abilities API: available. The artifact tools are callable over REST and WP-CLI.', 'wp-artifacts' )
				: esc_html__( 'Abilities API: missing. It ships with WordPress 6.9; without it the agent tools are unavailable, though artifacts still work.', 'wp-artifacts' )
		);

		printf(
			'<li>%s</li>',
			$has_adapter
				? esc_html(
					sprintf(
						/* translators: %s: MCP endpoint URL. */
						__( 'MCP Adapter: active. Point your client at %s.', 'wp-artifacts' ),
						rest_url( 'wp-artifacts/mcp' )
					)
				)
				: esc_html__( 'MCP Adapter: not active. Install the WordPress MCP Adapter plugin to expose these tools over MCP. Everything else keeps working without it.', 'wp-artifacts' )
		);

		printf(
			'<li>%s</li>',
			esc_html__( 'Authentication is the adapter\'s concern: application passwords by default, or OAuth through whichever plugin you install. This plugin stores no credentials.', 'wp-artifacts' )
		);

		echo '</ul>';
	}

	/*
	---------------------------------------------------------------------
	 * Field helpers
	 */

	/**
	 * Renders a text field row.
	 *
	 * @param string $key         Setting key.
	 * @param string $label       Field label.
	 * @param string $value       Current value.
	 * @param string $description Help text; may contain a <code> element.
	 * @return void
	 */
	private function text_row( string $key, string $label, string $value, string $description = '' ): void {
		printf(
			'<tr><th scope="row"><label for="wp_artifacts_%1$s">%2$s</label></th><td><input type="text" class="regular-text" id="wp_artifacts_%1$s" name="%3$s[%1$s]" value="%4$s">',
			esc_attr( $key ),
			esc_html( $label ),
			esc_attr( Settings::OPTION ),
			esc_attr( $value )
		);

		if ( '' !== $description ) {
			printf( '<p class="description">%s</p>', wp_kses( $description, array( 'code' => array() ) ) );
		}

		echo '</td></tr>';
	}

	/**
	 * Renders a number field row.
	 *
	 * @param string $key   Setting key.
	 * @param string $label Field label.
	 * @param int    $value    Current value.
	 * @param bool   $as_bytes Whether to show the value formatted as a file size.
	 * @return void
	 */
	private function number_row( string $key, string $label, int $value, bool $as_bytes = true ): void {
		$hint = $as_bytes ? (string) size_format( $value ) : '';

		printf(
			'<tr><th scope="row"><label for="wp_artifacts_%1$s">%2$s</label></th><td><input type="number" min="1" step="1" class="regular-text" id="wp_artifacts_%1$s" name="%3$s[%1$s]" value="%4$s"> <span class="description">%5$s</span></td></tr>',
			esc_attr( $key ),
			esc_html( $label ),
			esc_attr( Settings::OPTION ),
			esc_attr( (string) $value ),
			esc_html( $hint )
		);
	}

	/**
	 * Renders a checkbox row.
	 *
	 * @param string $key     Setting key.
	 * @param string $label   Row label.
	 * @param string $caption Checkbox caption.
	 * @param bool   $checked Whether it is on.
	 * @return void
	 */
	private function checkbox_row( string $key, string $label, string $caption, bool $checked ): void {
		printf(
			'<tr><th scope="row">%1$s</th><td><label><input type="checkbox" name="%2$s[%3$s]" value="1" %4$s> %5$s</label></td></tr>',
			esc_html( $label ),
			esc_attr( Settings::OPTION ),
			esc_attr( $key ),
			checked( $checked, true, false ),
			esc_html( $caption )
		);
	}
}
