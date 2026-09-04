<?php
/**
 * Minimal WP-CLI stubs for static analysis.
 *
 * php-stubs/wp-cli-stubs cannot be installed alongside the WordPress stubs this
 * project pins, so the handful of symbols the plugin uses are declared here. This
 * file is only ever read by PHPStan; it is never loaded at runtime.
 *
 * @package WPArtifacts
 */

// phpcs:ignoreFile

namespace {
	if ( ! class_exists( 'WP_CLI' ) ) {
		class WP_CLI {

			/**
			 * Registers a command.
			 *
			 * @param string               $name     Command name.
			 * @param callable|string      $callable Command implementation.
			 * @param array<string,mixed>  $args     Command arguments.
			 * @return bool
			 */
			public static function add_command( $name, $callable, $args = array() ) {
				return true;
			}

			/**
			 * Writes a line to STDOUT.
			 *
			 * @param string $message Message.
			 * @return void
			 */
			public static function line( $message = '' ) {}

			/**
			 * Writes a success message.
			 *
			 * @param string $message Message.
			 * @return void
			 */
			public static function success( $message ) {}

			/**
			 * Writes a warning.
			 *
			 * @param string $message Message.
			 * @return void
			 */
			public static function warning( $message ) {}

			/**
			 * Writes an error and halts.
			 *
			 * @param string $message Message.
			 * @param bool   $exit    Whether to halt.
			 * @return never
			 */
			public static function error( $message, $exit = true ) {
				exit( 1 );
			}
		}
	}
}

namespace WP_CLI\Utils {
	if ( ! function_exists( 'WP_CLI\\Utils\\format_items' ) ) {
		/**
		 * Renders a table, CSV, JSON or YAML view of a list.
		 *
		 * @param string            $format Output format.
		 * @param array<int,mixed>  $items  Items to render.
		 * @param array<int,string> $fields Fields to show.
		 * @return void
		 */
		function format_items( $format, $items, $fields ) {}
	}
}
