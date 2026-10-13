<?php
/**
 * The header for our theme
 *
 * This is the template that displays all of the <head> section and everything up until <div id="content">
 *
 * @link https://developer.wordpress.org/themes/basics/template-files/#template-partials
 *
 * @package motormania
 */


global $motormania_opt;

$site_preloader = !empty( $motormania_opt['site_preloader'] ) ? $motormania_opt['site_preloader'] : '';
$blog_breadcrumb_title = !empty($motormania_opt['blog_breadcrumb_title']) ? $motormania_opt['blog_breadcrumb_title'] : esc_html__( 'Latest news', 'motormania' );
$header_style = isset($motormania_opt['header_style']) ? $motormania_opt['header_style'] : '';

?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<link rel="profile" href="https://gmpg.org/xfn/11">
	
	<?php wp_head(); ?>
	
</head>

<body <?php body_class(); ?>>
	 <?php wp_body_open(); ?>
	
	<?php if ($site_preloader): ?>
		<!-- Preloading -->
		<div id="preloader">
			<div class="spinner">
				<div class="uil-ripple-css"><div></div><div></div></div>
			</div>
		</div>
	<?php endif ?>
	
	<a class="skip-link screen-reader-text" href="#content"><?php esc_html_e( 'Skip to content', 'motormania' ); ?></a>

    <?php 
    if ( $header_style == 'style1' ) {
        get_template_part( 'template-parts/header/header', '1' );
    } elseif ( $header_style == 'style2' ) {
        get_template_part( 'template-parts/header/header', '2' );
	} elseif ( $header_style == 'style3' ) {
		get_template_part( 'template-parts/header/header', '3' );
    } else {
        get_template_part( 'template-parts/header/header', '1' );
    } ?>
	
<?php if ( !is_page_template( 'custom-page-without-breadcrumb.php' ) && !is_404() ) { ?>		
	
	<section class="breadcrumbs">
		<div class="container">
			<div class="row">
				<div class="col-md-12 my-auto">
					<h1>
				    	<?php
				      	if(is_home() && is_front_page()){
				            echo esc_html( $blog_breadcrumb_title ); 
				      	} else { 
				            // wp_title() is deprecated and on a term archive it printed the
				            // taxonomy's PLURAL label then the term name ("Product categories  Helmets").
				            // The H1 must be the subject of the page, so resolve it per context and
				            // keep wp_title() only as the last-resort fallback: this H1 is on nearly
				            // every page and must never render empty.
				            $motormania_h1 = '';

				            if ( function_exists( 'is_shop' ) && is_shop() ) {
				                if ( function_exists( 'woocommerce_page_title' ) ) {
				                    $motormania_h1 = esc_html( trim( (string) woocommerce_page_title( false ) ) );
				                } elseif ( function_exists( 'wc_get_page_id' ) ) {
				                    $motormania_h1 = esc_html( trim( (string) get_the_title( wc_get_page_id( 'shop' ) ) ) );
				                }
				            } elseif ( is_tax() || is_category() || is_tag() ) {
				                // product_cat / product_tag / product_brand and any other taxonomy:
				                // the TERM name alone, e.g. "Helmets".
				                $motormania_h1 = esc_html( trim( (string) single_term_title( '', false ) ) );
				            } elseif ( is_singular() ) {
				                $motormania_h1 = esc_html( trim( (string) get_the_title() ) );
				            } elseif ( is_search() ) {
				                // Checked before the generic archive branch: get_the_archive_title()
				                // does not understand search and returns the bare word "Archives",
				                // which tells the reader nothing about the page.
				                $motormania_h1 = sprintf(
				                    /* translators: %s: the submitted search term */
				                    esc_html__( 'Search results for: %s', 'motormania-child' ),
				                    esc_html( trim( (string) get_search_query() ) )
				                );
				            } elseif ( is_post_type_archive() || is_author() || is_date() || is_archive() ) {
				                // get_the_archive_title() may legitimately carry markup, so it is
				                // kses'd rather than esc_html'd - escaping it twice would show tags.
				                $motormania_h1 = trim( wp_kses_post( get_the_archive_title() ) );
				            }

				            if ( '' === $motormania_h1 ) {
				                $motormania_h1 = esc_html( trim( (string) wp_title( '', false ) ) );
				            }

				            echo $motormania_h1; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above per branch.
				      	} ?>
				    </h1>
					<?php motormania_breadcrumb(); ?>
				</div>
			</div>
			
		</div>
	</section>
	
	<?php } ?>
	