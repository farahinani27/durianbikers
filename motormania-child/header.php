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
	
	<?php if ( !is_page_template( 'custom-page-without-breadcrumb.php' ) ) { ?>
		
	
	<section class="breadcrumbs">
		<div class="container">
			<div class="row">
				<div class="col-md-12 my-auto">
					<h1>
				    	<?php
				      	if(is_home() && is_front_page()){
				            echo esc_html( $blog_breadcrumb_title ); 
				      	} else { 
				            echo wp_title('', false);
				      	} ?>
				    </h1>
					<?php motormania_breadcrumb(); ?>
				</div>
			</div>
			
		</div>
	</section>
	
	<?php } ?>
	