<?php
/**
 * Plugin settings.
 *
 * @package WPArtifacts
 */

declare( strict_types = 1 );

namespace WPArtifacts;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes the single `wp_artifacts_settings` option.
 */
final class Settings {

	public const OPTION = 'wp_artifacts_settings';

	private const MB = 1048576;

	/**
	 * Runtime cache of the merged settings.
	 *
	 * @var array<string,mixed>|null
	 */
	private static ?array $cache = null;

	/**
	 * Default settings.
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults(): array {
		return array(
			'prefix'                   => 'a',
			'archive_slug'             => 'artifacts',
			'csp_mode'                 => 'strict',
			'csp_custom'               => '',
			'cookieless_host'          => '',
			'screenshot_provider'      => 'none',
			'screenshot_binary'        => '',
			'screenshot_url_template'  => '',
			'max_entry_bytes'          => 2 * self::MB,
			'max_asset_bytes'          => 10 * self::MB,
			'max_bundle_bytes'         => 50 * self::MB,
			'max_files'                => 200,
			'include_in_feeds'         => false,
			'allow_nonadmin_publish'   => true,
			'delete_data_on_uninstall' => false,
		);
	}

	/**
	 * All settings, merged over defaults.
	 *
	 * @return array<string,mixed>
	 */
	public static function all(): array {
		if ( null === self::$cache ) {
			$stored = get_option( self::OPTION, array() );
			$stored = is_array( $stored ) ? $stored : array();

			/**
			 * Filters the effective Artifacts settings.
			 *
			 * @param array<string,mixed> $settings Settings merged over defaults.
			 */
			self::$cache = (array) apply_filters( 'wp_artifacts_settings', array_merge( self::defaults(), $stored ) );
		}

		return self::$cache;
	}

	/**
	 * Reads a single setting.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default_value Fallback when the key is unknown.
	 * @return mixed
	 */
	public static function get( string $key, $default_value = null ) {
		$all = self::all();

		return array_key_exists( $key, $all ) ? $all[ $key ] : $default_value;
	}

	/**
	 * Persists a full settings array (sanitized).
	 *
	 * @param array<string,mixed> $values Raw values.
	 * @return array<string,mixed> Sanitized values that were stored.
	 */
	public static function save( array $values ): array {
		$clean = self::sanitize( $values );
		update_option( self::OPTION, $clean, false );
		self::flush();

		return $clean;
	}

	/**
	 * Writes the defaults on activation without clobbering existing values.
	 *
	 * @return void
	 */
	public static function install_defaults(): void {
		$stored = get_option( self::OPTION, null );
		if ( ! is_array( $stored ) ) {
			add_option( self::OPTION, self::defaults(), '', false );
		}
		self::flush();
	}

	/**
	 * Clears the runtime cache.
	 *
	 * @return void
	 */
	public static function flush(): void {
		self::$cache = null;
	}

	/**
	 * Sanitizes a settings payload.
	 *
	 * @param array<string,mixed> $values Raw values.
	 * @return array<string,mixed>
	 */
	public static function sanitize( array $values ): array {
		$defaults = self::defaults();
		$clean    = array();

		$prefix          = isset( $values['prefix'] ) ? sanitize_title( (string) $values['prefix'] ) : '';
		$clean['prefix'] = '' !== $prefix ? $prefix : $defaults['prefix'];

		$archive               = isset( $values['archive_slug'] ) ? sanitize_title( (string) $values['archive_slug'] ) : '';
		$clean['archive_slug'] = '' !== $archive ? $archive : $defaults['archive_slug'];

		$csp_mode          = isset( $values['csp_mode'] ) ? (string) $values['csp_mode'] : $defaults['csp_mode'];
		$clean['csp_mode'] = in_array( $csp_mode, array( 'strict', 'off', 'custom' ), true ) ? $csp_mode : $defaults['csp_mode'];

		$clean['csp_custom']      = isset( $values['csp_custom'] ) ? self::sanitize_header_value( (string) $values['csp_custom'] ) : '';
		$clean['cookieless_host'] = isset( $values['cookieless_host'] ) ? self::sanitize_host( (string) $values['cookieless_host'] ) : '';

		$provider                         = isset( $values['screenshot_provider'] ) ? (string) $values['screenshot_provider'] : 'none';
		$clean['screenshot_provider']     = in_array( $provider, array( 'none', 'headless', 'external' ), true ) ? $provider : 'none';
		$clean['screenshot_binary']       = isset( $values['screenshot_binary'] ) ? trim( (string) $values['screenshot_binary'] ) : '';
		$clean['screenshot_url_template'] = isset( $values['screenshot_url_template'] ) ? esc_url_raw( trim( (string) $values['screenshot_url_template'] ), array( 'http', 'https' ) ) : '';

		foreach ( array( 'max_entry_bytes', 'max_asset_bytes', 'max_bundle_bytes', 'max_files' ) as $numeric ) {
			$value             = isset( $values[ $numeric ] ) ? (int) $values[ $numeric ] : (int) $defaults[ $numeric ];
			$clean[ $numeric ] = $value > 0 ? $value : (int) $defaults[ $numeric ];
		}

		$clean['include_in_feeds']         = ! empty( $values['include_in_feeds'] );
		$clean['allow_nonadmin_publish']   = ! empty( $values['allow_nonadmin_publish'] );
		$clean['delete_data_on_uninstall'] = ! empty( $values['delete_data_on_uninstall'] );

		return $clean;
	}

	/**
	 * Strips CR/LF so a stored value can never inject extra headers.
	 *
	 * @param string $value Raw header value.
	 * @return string
	 */
	public static function sanitize_header_value( string $value ): string {
		return trim( str_replace( array( "\r", "\n", "\0" ), '', $value ) );
	}

	/**
	 * Sanitizes a bare host name (optionally with a scheme, which is dropped).
	 *
	 * @param string $value Raw host.
	 * @return string
	 */
	private static function sanitize_host( string $value ): string {
		$value = trim( $value );
		if ( '' === $value ) {
			return '';
		}

		if ( false !== strpos( $value, '//' ) ) {
			$value = (string) wp_parse_url( $value, PHP_URL_HOST );
		}

		$value = strtolower( preg_replace( '/[^A-Za-z0-9\.\-:]/', '', $value ) ?? '' );

		return $value;
	}

	/**
	 * The URL prefix used for artifact permalinks.
	 *
	 * @return string
	 */
	public static function prefix(): string {
		/**
		 * Filters the artifact URL prefix.
		 *
		 * @param string $prefix Prefix without slashes.
		 */
		$prefix = (string) apply_filters( 'wp_artifacts_url_prefix', (string) self::get( 'prefix', 'a' ) );

		return trim( $prefix, '/' );
	}

	/**
	 * The archive slug.
	 *
	 * @return string
	 */
	public static function archive_slug(): string {
		/**
		 * Filters the artifact archive slug.
		 *
		 * @param string $slug Archive slug.
		 */
		return trim( (string) apply_filters( 'wp_artifacts_archive_slug', (string) self::get( 'archive_slug', 'artifacts' ) ), '/' );
	}

	/**
	 * MIME types accepted for bundle assets.
	 *
	 * @return array<int,string> Exact types and `type/*` wildcards.
	 */
	public static function allowed_mimes(): array {
		$allowed = array(
			'text/css',
			'text/html',
			'text/plain',
			'text/javascript',
			'application/javascript',
			'application/json',
			'application/wasm',
			'application/manifest+json',
			'image/*',
			'font/*',
			'video/mp4',
			'audio/mpeg',
		);

		/**
		 * Filters the MIME types allowed for bundle assets.
		 *
		 * @param array<int,string> $allowed Allowed MIME types; `type/*` wildcards supported.
		 */
		return (array) apply_filters( 'wp_artifacts_allowed_mimes', $allowed );
	}
}
