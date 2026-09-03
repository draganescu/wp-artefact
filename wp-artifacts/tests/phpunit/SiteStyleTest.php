<?php
/**
 * The site.style document.
 *
 * @package WPArtifacts
 */

namespace WPArtifacts\Tests;

use WPArtifacts\Abilities\Guide;
use WPArtifacts\Style\ThemeAnalyzer;

/**
 * Criterion 9 from the build spec.
 */
final class SiteStyleTest extends ArtifactTestCase {

	/**
	 * The document has every documented section and a usable palette.
	 *
	 * @return void
	 */
	public function test_style_document_shape(): void {
		$style = ThemeAnalyzer::instance()->style( true );

		foreach ( array( 'theme', 'colors', 'typography', 'spacing', 'shape', 'logo', 'site', 'chrome', 'css_variables', 'guidance' ) as $key ) {
			$this->assertArrayHasKey( $key, $style );
		}

		$this->assertNotEmpty( $style['colors']['palette'] );
		$this->assertArrayHasKey( 'slug', $style['colors']['palette'][0] );
		$this->assertArrayHasKey( 'color', $style['colors']['palette'][0] );

		$this->assertNotEmpty( $style['colors']['background'] );
		$this->assertNotEmpty( $style['colors']['text'] );
		$this->assertNotEmpty( $style['spacing']['content_width'] );
		$this->assertNotEmpty( $style['typography']['font_families'] );
		$this->assertNotEmpty( $style['guidance'] );

		$this->assertIsString( wp_json_encode( $style ) );
	}

	/**
	 * The palette never resolves to a bare CSS variable reference.
	 *
	 * @return void
	 */
	public function test_colors_are_literal(): void {
		$style = ThemeAnalyzer::instance()->style( true );

		foreach ( array( 'background', 'text', 'accent', 'link' ) as $role ) {
			$this->assertStringNotContainsString( '--wp--preset--color--', (string) $style['colors'][ $role ], "The {$role} color is a literal value." );
		}
	}

	/**
	 * The document is cached and the cache can be cleared.
	 *
	 * @return void
	 */
	public function test_style_is_cached(): void {
		ThemeAnalyzer::instance()->flush();
		$this->assertFalse( get_transient( ThemeAnalyzer::TRANSIENT ) );

		ThemeAnalyzer::instance()->style();
		$this->assertIsArray( get_transient( ThemeAnalyzer::TRANSIENT ) );

		ThemeAnalyzer::instance()->flush();
		$this->assertFalse( get_transient( ThemeAnalyzer::TRANSIENT ) );
	}

	/**
	 * The guide names this site's real limits.
	 *
	 * @return void
	 */
	public function test_guide_is_specific(): void {
		$guide = Guide::markdown();

		$this->assertStringContainsString( 'wp-artifacts/site-style', $guide );
		$this->assertStringContainsString( 'unfiltered_html', $guide );
		$this->assertStringContainsString( home_url( '/' ), $guide );
	}
}
