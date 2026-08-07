<!-- custom header 5/6/2026 -->

<?php 
global $motormania_opt;

$motormania_top_header = !empty( $motormania_opt['motormania_top_header'] ) ? $motormania_opt['motormania_top_header'] : false;
$motormania_header_sticky = !empty( $motormania_opt['motormania_header_sticky'] ) ? $motormania_opt['motormania_header_sticky'] : '';
$motormania_header_full_width = !empty( $motormania_opt['motormania_header_full_width'] ) ? $motormania_opt['motormania_header_full_width'] : '';
$motormania_navbar_button =  !empty( $motormania_opt['motormania_navbar_button'] ) ? $motormania_opt['motormania_navbar_button'] : '';
$motormania_navbar_button_text =  !empty( $motormania_opt['motormania_navbar_button_text'] ) ? $motormania_opt['motormania_navbar_button_text'] : '';
$motormania_navbar_button_url =  !empty( $motormania_opt['motormania_navbar_button_url'] ) ? $motormania_opt['motormania_navbar_button_url'] : '';
?>
<header class="custom-transparent-header sticky-header">

	<div class="header-inner">

		<!-- LEFT : Hamburger (Mobile&Tablet) -->
		<div class="header-left">
			<button class="hamburger-btn" id="hamburger-btn">
				<span></span>
				<span></span>
				<span></span>
			</button>
		</div>

		<!-- LEFT : Menu -->
		<div class="header-left primary-menu">
			<nav class="desktop-menu">
				<?php
				wp_nav_menu( array(
					'theme_location' => 'primary',
					'depth'          => 3,
					'container'      => 'ul',
				) );
				?>
			</nav>
		</div>

		<!-- CENTRE : Logo -->
		<div class="header-centre">
			<?php
			if ( has_custom_logo() ) {
				the_custom_logo();
			} else { ?>
			<a href="<?php echo esc_url(home_url('/')); ?>" class="site-title">
				<?php bloginfo('name'); ?>
			</a>
			<?php } ?>
		</div>

		<!-- RIGHT : Search + Cart -->
		<div class="header-right">

			<!-- Search -->
			<a href="#" class="header-icon" id="search-trigger">
				<i class="fal fa-search"></i>
			</a>
			
			<!-- Currency Switcher -->
<!-- 			<?php if ( is_active_sidebar( 'header-currency' ) ) : ?>
				<div class="header-icon header-currency-wrap">
					<?php dynamic_sidebar( 'header-currency' ); ?>
				</div>
			<?php endif; ?> -->

			<!-- My Account -->
<!-- 			<a href="<?php echo esc_url( wc_get_page_permalink( 'myaccount' ) ); ?>" class="header-icon" id="myaccount-trigger">
				<i class="fal fa-user"></i>
			</a> -->
			<a class="header-icon account-link" href="<?php echo esc_url( wc_get_page_permalink( 'myaccount' ) ); ?>">
				<?php if ( is_user_logged_in() ) :
				$current_user = wp_get_current_user(); ?>
				<span class="account-name">Hi, <?php echo esc_html( $current_user->display_name ); ?></span>
				<?php else : ?>
				<i class="fal fa-user"></i>
				<?php endif; ?>
			</a>
			<!-- 	Use $user->display_name for a friendly name, or $user->user_login for the exact login username		 -->
			
			<!-- Cart -->
<!-- 			<a href="<?php echo wc_get_cart_url(); ?>" class="header-icon cart-icon">

				<i class="fal fa-shopping-cart"></i>

				<?php if ( WC()->cart->get_cart_contents_count() > 0 ) : ?>
				<span class="cart-badge">
					<?php echo WC()->cart->get_cart_contents_count(); ?>
				</span>
				<?php endif; ?>
			</a> -->
			
			<!-- Cart -->
			<a href="<?php echo esc_url( wc_get_cart_url() ); ?>" class="header-icon cart-icon">
				<i class="fal fa-shopping-cart"></i>
				<?php if ( WC()->cart->get_cart_contents_count() > 0 ) : ?>
				<span class="cart-badge">
					<?php echo esc_html( WC()->cart->get_cart_contents_count() ); ?>
				</span>
				<?php endif; ?>
			</a>

		</div>

	</div>

</header>

<!-- MOBILE MENU -->
<div class="mobile-menu" id="mobile-menu">

	<!-- CLOSE BUTTON -->
	<button class="mobile-menu-close" id="mobile-menu-close">
		×
	</button>

	<?php
	wp_nav_menu(array(
		'theme_location' => 'primary',
		'container'      => false,
		'menu_class'     => 'mobile-menu-list',
	));
	?>
	
	<!-- Currency on Sidebar Mobile&Tablet -->
<!-- 	<div class="mobile-currency-switcher">
		<p class="mobile-currency-label">Choose your region:</p>
		<?php dynamic_sidebar( 'header-currency' ); ?>
	</div> -->
</div>

<!-- SEARCH POPUP -->
<div class="search-popup" id="search-popup">

	<button class="search-close" id="search-close">
		×
	</button>

	<!--    <form method="get" action="<?php echo esc_url(home_url('/')); ?>">

	<input type="hidden" name="post_type" value="product">

		<input
			type="search"
			name="s"
			placeholder="Search products..."
			required
		>

		<button type="submit">
			Search
		</button>

	</form> -->

	<form class="ajax-search-form" action="<?php echo esc_url(home_url( '/' )); ?>">
		<input type="text" name="s" id="keyword" placeholder="<?php echo esc_attr__( 'Search', 'motormania' ); ?>">
		<button type="submit"><i class="fal fa-search"></i></button>
		<input type="hidden" name="post_type" value="product" />
		<div id="datafetch"></div>
	</form>

</div>

<script>
	document.addEventListener('DOMContentLoaded', function() {

		const hamburger = document.getElementById('hamburger-btn');
		const mobileMenu = document.getElementById('mobile-menu');
		const closeBtn = document.getElementById('mobile-menu-close');

		// OPEN MENU
		hamburger.addEventListener('click', function() {
			mobileMenu.classList.add('active');
		});

		// CLOSE MENU
		closeBtn.addEventListener('click', function() {
			mobileMenu.classList.remove('active');
		});
		
		// RESIZE HEADER & SIDEBAR DIFFERENT ON SCREEN SIZE
		window.addEventListener('resize', function() {
			if (window.innerWidth > 768) {
				mobileMenu.classList.remove('active');
			}
		});

		const searchTrigger = document.getElementById('search-trigger');
		const searchPopup = document.getElementById('search-popup');
		const searchClose = document.getElementById('search-close');

		searchTrigger.addEventListener('click', function(e){
			e.preventDefault();
			searchPopup.classList.add('active');
		});

		searchClose.addEventListener('click', function(){
			searchPopup.classList.remove('active');
		});

		searchPopup.addEventListener('click', function(e){
			if(e.target === searchPopup){
				searchPopup.classList.remove('active');
			}
		});
	});
</script>