<?php
/**
 * Describes how the site looks so agents can match it.
 *
 * @package WPArtifacts
 */

declare( strict_types = 1 );

namespace WPArtifacts\Style;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the `site.style` document.
 *
 * Everything here is best effort: a missing theme feature yields an empty value,
 * never a fatal.
 */
final class ThemeAnalyzer {

	public const TRANSIENT = 'wp_artifacts_site_style';

	/**
	 * Singleton instance.
	 *
	 * @var ThemeAnalyzer|null
	 */
	private static ?ThemeAnalyzer $instance = null;

	/**
	 * Singleton accessor.
	 *
	 * @return ThemeAnalyzer
	 */
	public static function instance(): ThemeAnalyzer {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Wires cache invalidation.
	 *
	 * @return void
	 */
	public function hooks(): void {
		add_action( 'switch_theme', array( $this, 'flush' ) );
		add_action( 'customize_save_after', array( $this, 'flush' ) );
		add_action( 'save_post_wp_global_styles', array( $this, 'flush' ) );
		add_action( 'rest_after_insert_wp_global_styles', array( $this, 'flush' ) );
		add_action( 'update_option_blogname', array( $this, 'flush' ) );
		add_action( 'update_option_blogdescription', array( $this, 'flush' ) );
	}

	/**
	 * Clears the cached document.
	 *
	 * @return void
	 */
	public function flush(): void {
		delete_transient( self::TRANSIENT );
	}

	/**
	 * The full style document.
	 *
	 * @param bool $refresh Whether to bypass the cache.
	 * @return array<string,mixed>
	 */
	public function style( bool $refresh = false ): array {
		if ( ! $refresh ) {
			$cached = get_transient( self::TRANSIENT );
			if ( is_array( $cached ) && ! empty( $cached ) ) {
				return $cached;
			}
		}

		$theme      = wp_get_theme();
		$is_block   = wp_is_block_theme();
		$colors     = $this->colors( $is_block );
		$typography = $this->typography( $is_block );
		$spacing    = $this->spacing( $is_block );

		$style = array(
			'theme'         => array(
				'name'           => (string) $theme->get( 'Name' ),
				'slug'           => (string) $theme->get_stylesheet(),
				'is_block_theme' => $is_block,
			),
			'colors'        => $colors,
			'typography'    => $typography,
			'spacing'       => $spacing,
			'shape'         => $this->shape( $is_block ),
			'logo'          => $this->logo(),
			'site'          => array(
				'title'     => (string) get_bloginfo( 'name' ),
				'tagline'   => (string) get_bloginfo( 'description' ),
				'url'       => (string) home_url( '/' ),
				'language'  => (string) get_bloginfo( 'language' ),
				'direction' => is_rtl() ? 'rtl' : 'ltr',
			),
			'chrome'        => $this->chrome(),
			'css_variables' => $this->css_variables( $is_block ),
		);

		$style['guidance'] = $this->guidance( $style );

		/**
		 * Filters the site style document handed to agents.
		 *
		 * @param array<string,mixed> $style Style document.
		 */
		$style = (array) apply_filters( 'wp_artifacts_site_style', $style );

		set_transient( self::TRANSIENT, $style, DAY_IN_SECONDS );

		return $style;
	}

	/*
	---------------------------------------------------------------------
	 * Pieces
	 */

	/**
	 * Palette and the handful of colors that matter most.
	 *
	 * @param bool $is_block Whether the active theme is a block theme.
	 * @return array<string,mixed>
	 */
	private function colors( bool $is_block ): array {
		$palette = array();

		if ( $is_block && function_exists( 'wp_get_global_settings' ) ) {
			$settings = wp_get_global_settings( array( 'color', 'palette' ) );
			foreach ( array( 'theme', 'custom', 'default' ) as $origin ) {
				if ( empty( $settings[ $origin ] ) || ! is_array( $settings[ $origin ] ) ) {
					continue;
				}
				foreach ( $settings[ $origin ] as $entry ) {
					if ( empty( $entry['slug'] ) || empty( $entry['color'] ) ) {
						continue;
					}
					$palette[ (string) $entry['slug'] ] = array(
						'slug'  => (string) $entry['slug'],
						'name'  => isset( $entry['name'] ) ? (string) $entry['name'] : (string) $entry['slug'],
						'color' => (string) $entry['color'],
					);
				}
			}
		}

		if ( empty( $palette ) ) {
			$support = get_theme_support( 'editor-color-palette' );
			if ( is_array( $support ) && isset( $support[0] ) && is_array( $support[0] ) ) {
				foreach ( $support[0] as $entry ) {
					if ( empty( $entry['slug'] ) || empty( $entry['color'] ) ) {
						continue;
					}
					$palette[ (string) $entry['slug'] ] = array(
						'slug'  => (string) $entry['slug'],
						'name'  => isset( $entry['name'] ) ? (string) $entry['name'] : (string) $entry['slug'],
						'color' => (string) $entry['color'],
					);
				}
			}
		}

		$mods = get_theme_mods();
		$mods = is_array( $mods ) ? $mods : array();

		foreach ( array(
			'background_color' => 'background',
			'header_textcolor' => 'header-text',
		) as $mod => $slug ) {
			if ( empty( $mods[ $mod ] ) || ! is_string( $mods[ $mod ] ) ) {
				continue;
			}
			$value = $mods[ $mod ];
			if ( 'blank' === $value ) {
				continue;
			}
			$value = str_starts_with( $value, '#' ) ? $value : '#' . $value;
			if ( ! isset( $palette[ $slug ] ) ) {
				$palette[ $slug ] = array(
					'slug'  => $slug,
					'name'  => ucfirst( str_replace( '-', ' ', $slug ) ),
					'color' => $value,
				);
			}
		}

		if ( empty( $palette ) ) {
			$palette = array(
				'base'     => array(
					'slug'  => 'base',
					'name'  => __( 'Base', 'wp-artifacts' ),
					'color' => '#ffffff',
				),
				'contrast' => array(
					'slug'  => 'contrast',
					'name'  => __( 'Contrast', 'wp-artifacts' ),
					'color' => '#111111',
				),
			);
		}

		$styles     = $this->global_styles();
		$background = $this->first_non_empty(
			array(
				$this->dig( $styles, array( 'color', 'background' ) ),
				$this->palette_color( $palette, array( 'base', 'background', 'white' ) ),
				'#ffffff',
			)
		);
		$text       = $this->first_non_empty(
			array(
				$this->dig( $styles, array( 'color', 'text' ) ),
				$this->palette_color( $palette, array( 'contrast', 'foreground', 'black' ) ),
				'#111111',
			)
		);
		$link       = $this->first_non_empty(
			array(
				$this->dig( $styles, array( 'elements', 'link', 'color', 'text' ) ),
				$this->palette_color( $palette, array( 'primary', 'accent', 'link' ) ),
				$text,
			)
		);
		$accent     = $this->first_non_empty(
			array(
				$this->palette_color( $palette, array( 'accent', 'primary', 'accent-1' ) ),
				$link,
			)
		);

		return array(
			'palette'    => array_values( $palette ),
			'background' => $this->resolve_preset( $background, $palette ),
			'text'       => $this->resolve_preset( $text, $palette ),
			'accent'     => $this->resolve_preset( $accent, $palette ),
			'link'       => $this->resolve_preset( $link, $palette ),
		);
	}

	/**
	 * Font families, sizes and the body/heading defaults.
	 *
	 * @param bool $is_block Whether the active theme is a block theme.
	 * @return array<string,mixed>
	 */
	private function typography( bool $is_block ): array {
		$families = array();
		$sizes    = array();

		if ( $is_block && function_exists( 'wp_get_global_settings' ) ) {
			$settings = wp_get_global_settings( array( 'typography' ) );

			$family_groups = isset( $settings['fontFamilies'] ) && is_array( $settings['fontFamilies'] ) ? $settings['fontFamilies'] : array();
			foreach ( array( 'theme', 'custom', 'default' ) as $origin ) {
				if ( empty( $family_groups[ $origin ] ) || ! is_array( $family_groups[ $origin ] ) ) {
					continue;
				}
				foreach ( $family_groups[ $origin ] as $family ) {
					if ( empty( $family['slug'] ) ) {
						continue;
					}
					$families[ (string) $family['slug'] ] = array(
						'slug'       => (string) $family['slug'],
						'name'       => isset( $family['name'] ) ? (string) $family['name'] : (string) $family['slug'],
						'fontFamily' => isset( $family['fontFamily'] ) ? (string) $family['fontFamily'] : '',
						'src'        => $this->font_sources( $family ),
					);
				}
			}

			$size_groups = isset( $settings['fontSizes'] ) && is_array( $settings['fontSizes'] ) ? $settings['fontSizes'] : array();
			foreach ( array( 'theme', 'custom', 'default' ) as $origin ) {
				if ( empty( $size_groups[ $origin ] ) || ! is_array( $size_groups[ $origin ] ) ) {
					continue;
				}
				foreach ( $size_groups[ $origin ] as $size ) {
					if ( empty( $size['slug'] ) ) {
						continue;
					}
					$sizes[ (string) $size['slug'] ] = array(
						'slug' => (string) $size['slug'],
						'name' => isset( $size['name'] ) ? (string) $size['name'] : (string) $size['slug'],
						'size' => isset( $size['size'] ) ? (string) $size['size'] : '',
					);
				}
			}
		}

		$styles = $this->global_styles();

		$body = array(
			'fontFamily' => (string) $this->dig( $styles, array( 'typography', 'fontFamily' ) ),
			'fontSize'   => (string) $this->dig( $styles, array( 'typography', 'fontSize' ) ),
			'lineHeight' => (string) $this->dig( $styles, array( 'typography', 'lineHeight' ) ),
		);

		$heading = array(
			'fontFamily' => (string) $this->dig( $styles, array( 'elements', 'heading', 'typography', 'fontFamily' ) ),
			'fontSize'   => (string) $this->dig( $styles, array( 'elements', 'h2', 'typography', 'fontSize' ) ),
			'lineHeight' => (string) $this->dig( $styles, array( 'elements', 'heading', 'typography', 'lineHeight' ) ),
			'fontWeight' => (string) $this->dig( $styles, array( 'elements', 'heading', 'typography', 'fontWeight' ) ),
		);

		$resolve = function ( array $group ) use ( $families ): array {
			foreach ( $group as $key => $value ) {
				$group[ $key ] = $this->resolve_font_preset( (string) $value, $families );
			}

			return $group;
		};

		$body    = $resolve( $body );
		$heading = $resolve( $heading );

		if ( empty( $families ) ) {
			$families['system'] = array(
				'slug'       => 'system',
				'name'       => __( 'System', 'wp-artifacts' ),
				'fontFamily' => '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif',
				'src'        => array(),
			);
		}

		return array(
			'font_families' => array_values( $families ),
			'font_sizes'    => array_values( $sizes ),
			'body'          => $body,
			'heading'       => $heading,
		);
	}

	/**
	 * Spacing scale and layout widths.
	 *
	 * @param bool $is_block Whether the active theme is a block theme.
	 * @return array<string,mixed>
	 */
	private function spacing( bool $is_block ): array {
		$scale         = array();
		$content_width = '';
		$wide_width    = '';

		if ( $is_block && function_exists( 'wp_get_global_settings' ) ) {
			$settings = wp_get_global_settings();

			$sizes = $this->dig( $settings, array( 'spacing', 'spacingSizes' ) );
			if ( is_array( $sizes ) ) {
				foreach ( array( 'theme', 'custom', 'default' ) as $origin ) {
					if ( empty( $sizes[ $origin ] ) || ! is_array( $sizes[ $origin ] ) ) {
						continue;
					}
					foreach ( $sizes[ $origin ] as $size ) {
						if ( empty( $size['slug'] ) ) {
							continue;
						}
						$scale[ (string) $size['slug'] ] = array(
							'slug' => (string) $size['slug'],
							'name' => isset( $size['name'] ) ? (string) $size['name'] : (string) $size['slug'],
							'size' => isset( $size['size'] ) ? (string) $size['size'] : '',
						);
					}
				}
			}

			$content_width = (string) $this->dig( $settings, array( 'layout', 'contentSize' ) );
			$wide_width    = (string) $this->dig( $settings, array( 'layout', 'wideSize' ) );
		}

		if ( '' === $content_width ) {
			$width         = (int) apply_filters( 'wp_artifacts_default_content_width', isset( $GLOBALS['content_width'] ) ? (int) $GLOBALS['content_width'] : 640 );
			$content_width = ( $width > 0 ? $width : 640 ) . 'px';
		}

		if ( '' === $wide_width ) {
			$wide_width = '1200px';
		}

		$padding = $this->dig( $this->global_styles(), array( 'spacing', 'padding' ) );

		return array(
			'scale'         => array_values( $scale ),
			'content_width' => $content_width,
			'wide_width'    => $wide_width,
			'padding'       => is_array( $padding ) ? $padding : array(),
		);
	}

	/**
	 * Border radius, when the theme states one.
	 *
	 * @param bool $is_block Whether the active theme is a block theme.
	 * @return array<string,string>
	 */
	private function shape( bool $is_block ): array {
		$radius = '';

		if ( $is_block ) {
			$radius = (string) $this->dig( $this->global_styles(), array( 'elements', 'button', 'border', 'radius' ) );
			if ( '' === $radius ) {
				$radius = (string) $this->dig( $this->global_styles(), array( 'border', 'radius' ) );
			}
		}

		return array( 'border_radius' => $radius );
	}

	/**
	 * The site logo, if any.
	 *
	 * @return array<string,mixed>
	 */
	private function logo(): array {
		$logo_id = (int) get_theme_mod( 'custom_logo' );

		if ( $logo_id <= 0 ) {
			$logo_id = (int) get_option( 'site_logo' );
		}

		if ( $logo_id <= 0 ) {
			return array(
				'url'    => '',
				'width'  => 0,
				'height' => 0,
			);
		}

		$image = wp_get_attachment_image_src( $logo_id, 'full' );
		if ( ! is_array( $image ) ) {
			return array(
				'url'    => '',
				'width'  => 0,
				'height' => 0,
			);
		}

		return array(
			'url'    => (string) $image[0],
			'width'  => (int) $image[1],
			'height' => (int) $image[2],
		);
	}

	/**
	 * Rendered header and footer plus the CSS needed to display them.
	 *
	 * @return array<string,string>
	 */
	public function chrome(): array {
		$chrome = array(
			'header_html' => '',
			'footer_html' => '',
			'css'         => '',
			'captured_at' => gmdate( 'c' ),
		);

		try {
			if ( wp_is_block_theme() ) {
				$chrome['header_html'] = $this->render_template_part( 'header' );
				$chrome['footer_html'] = $this->render_template_part( 'footer' );
			} else {
				$fetched               = $this->fetch_home_chrome();
				$chrome['header_html'] = $fetched['header_html'];
				$chrome['footer_html'] = $fetched['footer_html'];
			}

			$chrome['css'] = $this->chrome_css();
		} catch ( \Throwable $error ) {
			unset( $error );
		}

		/**
		 * Filters the captured site chrome.
		 *
		 * @param array<string,string> $chrome Header HTML, footer HTML and CSS.
		 */
		return (array) apply_filters( 'wp_artifacts_chrome', $chrome );
	}

	/**
	 * Renders a block theme template part.
	 *
	 * @param string $slug Template part slug.
	 * @return string
	 */
	private function render_template_part( string $slug ): string {
		if ( ! function_exists( 'get_block_template' ) ) {
			return '';
		}

		$template = get_block_template( get_stylesheet() . '//' . $slug, 'wp_template_part' );
		if ( null === $template && get_stylesheet() !== get_template() ) {
			$template = get_block_template( get_template() . '//' . $slug, 'wp_template_part' );
		}

		if ( null === $template || empty( $template->content ) ) {
			return '';
		}

		return trim( (string) do_blocks( (string) $template->content ) );
	}

	/**
	 * Extracts header and footer markup from the home page of a classic theme.
	 *
	 * @return array<string,string>
	 */
	private function fetch_home_chrome(): array {
		$result = array(
			'header_html' => '',
			'footer_html' => '',
		);

		$response = wp_remote_get(
			home_url( '/' ),
			array(
				'timeout'   => 10,
				'sslverify' => false,
				'headers'   => array( 'X-Artifacts-Capture' => '1' ),
			)
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return $result;
		}

		$body = (string) wp_remote_retrieve_body( $response );

		if ( preg_match( '#<header\b[^>]*>.*?</header>#is', $body, $matches ) ) {
			$result['header_html'] = (string) $matches[0];
		}

		if ( preg_match( '#<footer\b[^>]*>.*?</footer>#is', $body, $matches ) ) {
			$result['footer_html'] = (string) $matches[0];
		}

		return $result;
	}

	/**
	 * CSS needed to render the captured chrome.
	 *
	 * @return string
	 */
	private function chrome_css(): string {
		$css = '';

		if ( function_exists( 'wp_get_global_stylesheet' ) ) {
			$css .= (string) wp_get_global_stylesheet();
		}

		if ( class_exists( '\WP_Style_Engine_CSS_Rules_Store' ) && function_exists( 'wp_style_engine_get_stylesheet_from_context' ) ) {
			$css .= (string) wp_style_engine_get_stylesheet_from_context( 'block-supports' );
		}

		return trim( $css );
	}

	/**
	 * The `:root` custom properties for the active theme.
	 *
	 * @param bool $is_block Whether the active theme is a block theme.
	 * @return string
	 */
	private function css_variables( bool $is_block ): string {
		if ( $is_block && function_exists( 'wp_get_global_stylesheet' ) ) {
			return trim( (string) wp_get_global_stylesheet( array( 'variables' ) ) );
		}

		$declarations = array();
		foreach ( (array) get_theme_support( 'editor-color-palette' ) as $group ) {
			if ( ! is_array( $group ) ) {
				continue;
			}
			foreach ( $group as $entry ) {
				if ( empty( $entry['slug'] ) || empty( $entry['color'] ) ) {
					continue;
				}
				$declarations[] = '--wp--preset--color--' . sanitize_key( (string) $entry['slug'] ) . ':' . $entry['color'];
			}
		}

		return empty( $declarations ) ? '' : ':root{' . implode( ';', $declarations ) . ';}';
	}

	/**
	 * Human readable instructions derived from the collected values.
	 *
	 * @param array<string,mixed> $style Style document without guidance.
	 * @return string
	 */
	private function guidance( array $style ): string {
		$lines = array();

		$lines[] = sprintf(
			/* translators: 1: site title, 2: theme name. */
			__( 'Build artifacts that look at home on "%1$s" (theme: %2$s).', 'wp-artifacts' ),
			$style['site']['title'],
			$style['theme']['name']
		);

		$swatches = array();
		foreach ( array_slice( (array) $style['colors']['palette'], 0, 8 ) as $entry ) {
			$swatches[] = $entry['slug'] . ' ' . $entry['color'];
		}

		if ( ! empty( $swatches ) ) {
			$lines[] = sprintf(
				/* translators: %s: comma separated list of palette entries. */
				__( 'Palette: %s. Prefer these over invented colors.', 'wp-artifacts' ),
				implode( ', ', $swatches )
			);
		}

		$lines[] = sprintf(
			/* translators: 1: background color, 2: text color, 3: link color. */
			__( 'Page background %1$s, body text %2$s, links %3$s.', 'wp-artifacts' ),
			$style['colors']['background'],
			$style['colors']['text'],
			$style['colors']['link']
		);

		$fonts = array();
		foreach ( (array) $style['typography']['font_families'] as $family ) {
			$fonts[] = $family['fontFamily'];
		}

		if ( ! empty( $fonts ) ) {
			$lines[] = sprintf(
				/* translators: %s: font stacks. */
				__( 'Font stacks: %s. Load webfonts from the "src" URLs in typography.font_families, or fall back to a system stack; never hotlink a font the site does not already ship.', 'wp-artifacts' ),
				implode( ' | ', array_slice( $fonts, 0, 4 ) )
			);
		}

		$lines[] = sprintf(
			/* translators: 1: content width, 2: wide width. */
			__( 'Keep the main column at %1$s and full-bleed sections at %2$s.', 'wp-artifacts' ),
			$style['spacing']['content_width'],
			$style['spacing']['wide_width']
		);

		if ( '' !== $style['css_variables'] ) {
			$lines[] = __( 'Paste css_variables into a <style> block to get the theme presets as custom properties.', 'wp-artifacts' );
		}

		if ( '' !== $style['chrome']['header_html'] ) {
			$lines[] = __( 'chrome.header_html and chrome.footer_html are the site header and footer; paste them in with chrome.css, or set wrap=true when publishing and the plugin will do it.', 'wp-artifacts' );
		}

		$lines[] = __( 'Ship one self-contained document. Reference bundle files by relative path (css/app.css), never by absolute URL. Do not inline secrets: the artifact is public HTML.', 'wp-artifacts' );

		return implode( ' ', $lines );
	}

	/*
	---------------------------------------------------------------------
	 * Helpers
	 */

	/**
	 * The resolved global styles array.
	 *
	 * @return array<string,mixed>
	 */
	private function global_styles(): array {
		if ( ! function_exists( 'wp_get_global_styles' ) ) {
			return array();
		}

		$styles = wp_get_global_styles();

		return is_array( $styles ) ? $styles : array();
	}

	/**
	 * Reads a nested array path.
	 *
	 * @param mixed             $data Array to read.
	 * @param array<int,string> $path Keys to follow.
	 * @return mixed
	 */
	private function dig( $data, array $path ) {
		foreach ( $path as $key ) {
			if ( ! is_array( $data ) || ! array_key_exists( $key, $data ) ) {
				return '';
			}
			$data = $data[ $key ];
		}

		return $data;
	}

	/**
	 * First non-empty value in a list.
	 *
	 * @param array<int,mixed> $candidates Candidate values.
	 * @return string
	 */
	private function first_non_empty( array $candidates ): string {
		foreach ( $candidates as $candidate ) {
			if ( is_string( $candidate ) && '' !== trim( $candidate ) ) {
				return trim( $candidate );
			}
		}

		return '';
	}

	/**
	 * Looks up a palette color by a list of candidate slugs.
	 *
	 * @param array<string,array<string,string>> $palette Palette keyed by slug.
	 * @param array<int,string>                  $slugs   Slugs to try in order.
	 * @return string
	 */
	private function palette_color( array $palette, array $slugs ): string {
		foreach ( $slugs as $slug ) {
			if ( isset( $palette[ $slug ]['color'] ) ) {
				return (string) $palette[ $slug ]['color'];
			}
		}

		return '';
	}

	/**
	 * Turns `var(--wp--preset--color--base)` into the literal color.
	 *
	 * @param string                             $value   Raw value.
	 * @param array<string,array<string,string>> $palette Palette keyed by slug.
	 * @return string
	 */
	private function resolve_preset( string $value, array $palette ): string {
		if ( preg_match( '#var\(\s*--wp--preset--color--([a-z0-9\-]+)\s*\)#i', $value, $matches ) ) {
			$slug = strtolower( (string) $matches[1] );
			if ( isset( $palette[ $slug ]['color'] ) ) {
				return (string) $palette[ $slug ]['color'];
			}
		}

		return $value;
	}

	/**
	 * Turns `var(--wp--preset--font-family--body)` into the literal stack.
	 *
	 * @param string                            $value    Raw value.
	 * @param array<string,array<string,mixed>> $families Families keyed by slug.
	 * @return string
	 */
	private function resolve_font_preset( string $value, array $families ): string {
		if ( preg_match( '#var\(\s*--wp--preset--font-family--([a-z0-9\-]+)\s*\)#i', $value, $matches ) ) {
			$slug = strtolower( (string) $matches[1] );
			if ( isset( $families[ $slug ]['fontFamily'] ) ) {
				return (string) $families[ $slug ]['fontFamily'];
			}
		}

		return $value;
	}

	/**
	 * Absolute URLs of the font files a family ships.
	 *
	 * @param array<string,mixed> $family Font family definition.
	 * @return array<int,string>
	 */
	private function font_sources( array $family ): array {
		$sources = array();

		if ( empty( $family['fontFace'] ) || ! is_array( $family['fontFace'] ) ) {
			return $sources;
		}

		foreach ( $family['fontFace'] as $face ) {
			if ( empty( $face['src'] ) ) {
				continue;
			}

			foreach ( (array) $face['src'] as $src ) {
				$src = (string) $src;
				if ( '' === $src ) {
					continue;
				}
				if ( str_starts_with( $src, 'file:./' ) ) {
					$src = get_theme_file_uri( substr( $src, 7 ) );
				}
				$sources[] = $src;
			}
		}

		return array_values( array_unique( $sources ) );
	}
}
