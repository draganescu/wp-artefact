<?php
/**
 * The contract every artifact ability follows.
 *
 * @package WPArtifacts
 */

declare( strict_types = 1 );

namespace WPArtifacts\Abilities;

defined( 'ABSPATH' ) || exit;

/**
 * One ability, described the way `wp_register_ability()` wants it.
 */
interface Ability {

	/**
	 * The arguments passed to wp_register_ability().
	 *
	 * @return array<string,mixed>
	 */
	public static function definition(): array;
}
