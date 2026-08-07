<?php 
global $motormania_opt;
$motormania_header_sticky = !empty( $motormania_opt['motormania_header_sticky'] ) ? $motormania_opt['motormania_header_sticky'] : '';
$motormania_header_full_width = !empty( $motormania_opt['motormania_header_full_width'] ) ? $motormania_opt['motormania_header_full_width'] : '';
?>
<header>
    <div class="site-header pt-4 pb-4 <?php if( true == $motormania_header_sticky ){ echo'sticky-header'; } ?>">
        <div class="container<?php if( $motormania_header_full_width == true ){ echo'-fluid'; } ?>">
            <div class="row justify-content-center">
                <div class="col-md-2 my-auto">
                    <?php if (has_custom_logo()) {
                        the_custom_logo();
                    } else { ?>
                        <a class="navbar-logo-text" rel="home" href="<?php echo esc_url( home_url( '/' ) ); ?>" title="<?php echo esc_attr( get_bloginfo( 'name', 'display' ) ); ?>"><?php bloginfo( 'name' ); ?></a>
                    <?php } ?>
                </div>
                <div class="col-md-10 my-auto">
                    <div class="primary-menu d-none d-lg-inline-block float-end">
                        <nav class="desktop-menu">
                            <?php
                                wp_nav_menu( array(
                                'theme_location'    => 'primary',
                                'depth'             => 3,
                                'container'         => 'ul',
                            ) ); ?>
                        </nav>                      
                    </div>
                </div>      
            </div>
        </div>
    </div>
</header><!-- #masthead -->

<!--Mobile Navigation Toggler-->
<div class="off-canvas-menu-bar">
    <div class="container">
        <div class="row">
            <div class="col-6 my-auto">
            <?php if (has_custom_logo()) {
                the_custom_logo();
            } else { ?>
                <a class="navbar-logo-text" rel="home" href="<?php echo esc_url( home_url( '/' ) ); ?>" title="<?php echo esc_attr( get_bloginfo( 'name', 'display' ) ); ?>"><?php bloginfo( 'name' ); ?></a>
            <?php } ?>
            </div>
            <div class="col-6">
                <div class="mobile-nav-toggler"><span class="fal fa-bars"></span></div>
            </div>
        </div>
    </div>
</div>

<!-- Mobile Menu  -->
<div class="off-canvas-menu">
    <div class="menu-backdrop"></div>
    <i class="close-btn fa fa-close"></i>
    <nav class="mobile-nav">
        <div class="text-center pt-3 pb-3">
        <?php if (has_custom_logo()) {
            the_custom_logo();
        } else { ?>
            <a class="navbar-logo-text" rel="home" href="<?php echo esc_url( home_url( '/' ) ); ?>" title="<?php echo esc_attr( get_bloginfo( 'name', 'display' ) ); ?>"><?php bloginfo( 'name' ); ?></a>
        <?php } ?>
        </div>
        
        <ul class="navigation"><!--Keep This Empty / Menu will come through Javascript--></ul>
    </nav>
</div>