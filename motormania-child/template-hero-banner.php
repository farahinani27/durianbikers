<?php
/**
 * Template Name: Hero Banner (3D)
 *
 * Full-bleed two-panel hero with a scroll-driven 3D helmet.
 *
 * Self-contained: assign it to a page from Page Attributes > Template. It
 * registers nothing globally and needs no functions.php changes - its CSS,
 * import map and module script are printed by header-hero.php / footer-hero.php,
 * so no other page on the site is affected.
 *
 * @package MotorMania Child
 */

defined( 'ABSPATH' ) || exit;

$db_hero_model = get_stylesheet_directory_uri() . '/assets/models/helmet-opt.glb';

/**
 * Swap the helmet without editing this file:
 *   add_filter( 'db_hero_model_url', fn() => wp_get_attachment_url( 123 ) );
 */
$db_hero_model = apply_filters( 'db_hero_model_url', $db_hero_model );

get_header( 'hero' );
?>

<div class="db-hero">

	<div class="db-stage" data-model="<?php echo esc_url( $db_hero_model ); ?>" aria-hidden="true">
		<div class="db-stage__poster"><div class="db-stage__spinner"></div></div>
	</div>

	<!--============ PANEL 1 - BLACK ============-->
	<section class="db-panel db-panel--dark">

		<header class="db-hero__header">
			<nav class="db-nav" aria-label="<?php esc_attr_e( 'Primary', 'motormania-child' ); ?>">
				<?php
				$db_hero_nav = array(
					'/'                   => __( 'Home', 'motormania-child' ),
					'/shop/'              => __( 'Shop', 'motormania-child' ),
					'/motorcycle-helmet/' => __( 'Motorcycle Helmet', 'motormania-child' ),
					'/about-us/'          => __( 'About Us', 'motormania-child' ),
					'/contact-us/'        => __( 'Contact Us', 'motormania-child' ),
				);
				foreach ( $db_hero_nav as $db_path => $db_label ) {
					printf(
						'<a href="%1$s">%2$s</a>',
						esc_url( home_url( $db_path ) ),
						esc_html( $db_label )
					);
				}
				?>
			</nav>

			<picture class="db-hero__mark">
				<source srcset="<?php echo esc_url( get_stylesheet_directory_uri() . '/assets/img/db-logo.webp' ); ?>" type="image/webp">
				<img src="<?php echo esc_url( get_stylesheet_directory_uri() . '/assets/img/db-logo.png' ); ?>"
					alt="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>" width="176" height="176" decoding="async">
			</picture>

			<div class="db-hero__actions">
				<a class="db-btn db-btn--accent" href="<?php echo esc_url( function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'shop' ) : home_url( '/' ) ); ?>">
					<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true">
						<path d="M6 7h12l-1 13H7L6 7Z"/><path d="M9 7a3 3 0 0 1 6 0"/>
					</svg>
					<?php esc_html_e( 'Store', 'motormania-child' ); ?>
				</a>
				<button class="db-btn db-btn--ghost" type="button" aria-label="<?php esc_attr_e( 'Open menu', 'motormania-child' ); ?>">
					<svg width="17" height="17" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2" aria-hidden="true">
						<path d="M4 9h16"/><path d="M4 15h10"/>
					</svg>
				</button>
			</div>
		</header>

		<div class="db-hero__body">

			<h1 class="db-hero__title">
				<span class="db-hero__title-on"><?php esc_html_e( 'On', 'motormania-child' ); ?></span>
				<span class="db-hero__title-track"><?php esc_html_e( 'Track', 'motormania-child' ); ?></span>
			</h1>

			<p class="db-hero__meta">
				<span><?php esc_html_e( 'MT Thunder 4R SV', 'motormania-child' ); ?></span>
				<span><?php esc_html_e( 'Hakim Danish', 'motormania-child' ); ?></span>
				<span><?php esc_html_e( 'Malaysia', 'motormania-child' ); ?> <span class="db-card__flag">&#127474;&#127486;</span></span>
			</p>

			<div class="db-hero__copy">
				<p class="db-eyebrow"><?php esc_html_e( 'Built for the ride', 'motormania-child' ); ?></p>
				<p class="db-hero__lede">
					<?php
					printf(
						/* translators: 1: em dash, 2: emphasised closing phrase. */
						esc_html__( 'The MT Thunder 4R balances safety, comfort and everyday use %1$s improved intakes, a redesigned spoiler, Pinlock anti-fog as standard and MT\'s QVSS quick-release visor. %2$s.', 'motormania-child' ),
						'&mdash;',
						'<em>' . esc_html__( 'Premium performance at a competitive price', 'motormania-child' ) . '</em>'
					);
					?>
				</p>
			</div>

			<div class="db-cards">

				<article class="db-card">
					<span class="db-card__label"><?php esc_html_e( 'Shell', 'motormania-child' ); ?></span>
					<span class="db-card__value"><?php esc_html_e( 'Shell made of HIRP', 'motormania-child' ); ?></span>
					<span class="db-card__foot"><?php esc_html_e( 'High Impact Resistant Polymer', 'motormania-child' ); ?></span>
				</article>

				<article class="db-card">
					<span class="db-card__label"><?php esc_html_e( 'Model / Series', 'motormania-child' ); ?></span>
					<span class="db-card__value"><?php esc_html_e( 'Thunder 4R SV Danish B5 Gloss', 'motormania-child' ); ?></span>
					<span class="db-card__foot"><?php esc_html_e( 'New in', 'motormania-child' ); ?> <span class="db-card__flag">&#127474;&#127486;</span></span>
				</article>

				<article class="db-card">
					<span class="db-card__label"><?php esc_html_e( 'Ventilation', 'motormania-child' ); ?></span>
					<span class="db-card__value"><?php esc_html_e( 'Optimized ventilation system', 'motormania-child' ); ?></span>
				</article>

				<article class="db-card">
					<span class="db-card__label"><?php esc_html_e( 'Price', 'motormania-child' ); ?></span>
					<span class="db-card__value db-card__value--xl">RM&nbsp;749</span>
					<span class="db-card__foot"><?php esc_html_e( 'In stock', 'motormania-child' ); ?></span>
				</article>

			</div>
	</section>

	<!--============ PANEL 2 - WHITE ============-->
	<section class="db-panel db-panel--light">
		<div class="db-panel__inner">

			<div>
				<p class="db-eyebrow"><?php esc_html_e( 'MT Helmets', 'motormania-child' ); ?></p>
				<h2 class="db-spec__title"><?php esc_html_e( 'Hakim Danish', 'motormania-child' ); ?></h2>
			</div>

			<div class="db-spec__stage" aria-hidden="true"></div>

			<div class="db-buy">
				<a class="db-btn db-btn--accent db-btn--lg" href="<?php echo esc_url( function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'shop' ) : home_url( '/' ) ); ?>">
					<?php esc_html_e( 'Buy Now', 'motormania-child' ); ?>
				</a>
			</div>

			<dl class="db-spec__row">
				<div><dt><?php esc_html_e( 'Shell', 'motormania-child' ); ?></dt><dd><?php esc_html_e( 'Fibreglass composite', 'motormania-child' ); ?></dd></div>
				<div><dt><?php esc_html_e( 'Weight', 'motormania-child' ); ?></dt><dd><?php esc_html_e( '1650 g', 'motormania-child' ); ?></dd></div>
				<div><dt><?php esc_html_e( 'Certification', 'motormania-child' ); ?></dt><dd><?php esc_html_e( 'Hakim Danish', 'motormania-child' ); ?></dd></div>
				<div><dt><?php esc_html_e( 'Sizes', 'motormania-child' ); ?></dt><dd><?php esc_html_e( 'S - XXL', 'motormania-child' ); ?></dd></div>
			</dl>

		</div>
	</section>

</div>

<?php
get_footer( 'hero' );
