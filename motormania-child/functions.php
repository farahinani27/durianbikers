<?php 
add_action( 'wp_enqueue_scripts', 'motormania_child_enqueue_styles' );
function motormania_child_enqueue_styles() {
	wp_enqueue_style( 'parent-style', get_template_directory_uri() . '/style.css', array('bootstrap')); 
} 


// --- my custom code without the parent theme --- //

add_action( 'wp_footer', 'durianbikers_respondio_widget' );
function durianbikers_respondio_widget() {
	if ( is_admin() ) {
		return;
	}
	?>
	<script id="respondio__widget" src="https://cdn.respond.io/webchat/widget/widget.js?cId=bab8a693c0417873b78486ab05d24b0"></script>
	<?php
}

// Allow shortcode to be implement in HTML Widget
add_filter( 'elementor/widget/render_content', function( $content, $widget ) {
	if ( 'html' === $widget->get_name() ) {
		$content = do_shortcode( $content );
	}
	return $content;
}, 10, 2 );


// GSAP
function custom_gsap_scripts() {

	wp_enqueue_script(
		'gsap',
		'https://cdn.jsdelivr.net/npm/gsap@3.12.5/dist/gsap.min.js',
		array(),
		null,
		true
	);

	wp_enqueue_script(
		'scrolltrigger',
		'https://cdn.jsdelivr.net/npm/gsap@3.12.5/dist/ScrollTrigger.min.js',
		array('gsap'),
		null,
		true
	);

	wp_enqueue_script(
		'splittext',
		'https://cdn.jsdelivr.net/npm/gsap@3.15/dist/SplitText.min.js',
		array('gsap'),
		'3.13.0',
		true
	);


}
add_action('wp_enqueue_scripts', 'custom_gsap_scripts');

// Add custom id for every page on the body
add_filter('body_class', function($classes) {
	if (is_page('helmet-brands')) {
		echo 'id="helmet-brands"';
	}
	if (is_page('all-category')) {
		echo 'id="all-category"';
	}
	if (is_page('accessories-brands')) {
		echo 'id="accessories-brands"';
	}
	if (is_page('home-testing')) {
		echo 'id="home-testing"';
	}
	return $classes;
});

// shortcode to display only cart total
function motormania_cart_info_shortcode() {

	if ( ! class_exists('WooCommerce') || ! WC()->cart ) {
		return '';
	}

	$count = WC()->cart->get_cart_contents_count();
	$total = WC()->cart->get_cart_subtotal();

	ob_start(); ?>

<div class="mm-cart-shortcode">
	<a href="<?php echo esc_url( wc_get_cart_url() ); ?>" class="mm-cart-link">

		<span class="mm-cart-icon">
			<i class="fal fa-cart-plus"></i>

			<?php if ( $count > 0 ) : ?>
			<span class="mm-cart-badge">
				<?php echo $count; ?>
			</span>
			<?php endif; ?>
		</span>

		<span class="mm-cart-total">
			<?php echo $total; ?>
		</span>

	</a>
</div>

<?php
	return ob_get_clean();
}
add_shortcode('cart_info', 'motormania_cart_info_shortcode');

// shortcode AJAX to refresh page when add to cart
add_filter('woocommerce_add_to_cart_fragments', 'motormania_cart_fragment_refresh');

function motormania_cart_fragment_refresh($fragments) {

	ob_start(); ?>

<div class="mm-cart-shortcode">
	<a href="<?php echo esc_url( wc_get_cart_url() ); ?>" class="mm-cart-link">

		<span class="mm-cart-icon">
			<i class="fal fa-cart-plus"></i>

			<?php $count = WC()->cart->get_cart_contents_count(); ?>
			<?php if ( $count > 0 ) : ?>
			<span class="mm-cart-badge">
				<?php echo $count; ?>
			</span>
			<?php endif; ?>

		</span>

		<span class="mm-cart-total">
			<?php echo WC()->cart->get_cart_subtotal(); ?>
		</span>

	</a>
</div>

<?php

	$fragments['div.mm-cart-shortcode'] = ob_get_clean();

	return $fragments;
}

// 1) Force WooCommerce's cart-fragments script to always load (fixes cart not updating)
add_action( 'wp_enqueue_scripts', 'durianbikers_force_cart_fragments', 99 );
function durianbikers_force_cart_fragments() {
	if ( function_exists( 'WC' ) && ! wp_script_is( 'wc-cart-fragments', 'enqueued' ) ) {
		wp_enqueue_script( 'wc-cart-fragments' );
	}
}

// 2) Tell WooCommerce how to refresh the header cart icon on "Add to cart"
add_filter( 'woocommerce_add_to_cart_fragments', 'durianbikers_cart_icon_fragment' );
function durianbikers_cart_icon_fragment( $fragments ) {
	ob_start();
?>
<a href="<?php echo esc_url( wc_get_cart_url() ); ?>" class="header-icon cart-icon">
	<i class="fal fa-shopping-cart"></i>
	<?php if ( WC()->cart->get_cart_contents_count() > 0 ) : ?>
	<span class="cart-badge">
		<?php echo esc_html( WC()->cart->get_cart_contents_count() ); ?>
	</span>
	<?php endif; ?>
</a>
<?php
	$fragments['a.cart-icon'] = ob_get_clean();
	return $fragments;
}

// Show a small "Added to cart" toast notification
add_action( 'wp_footer', 'durianbikers_cart_toast_markup' );
function durianbikers_cart_toast_markup() {
	if ( ! function_exists( 'WC' ) ) {
		return;
	}
?>
<style>
	.db-cart-toast {
		position: fixed;
		top: 20px;
		right: 20px;
		background: #1c1c1c;
		color: #fff;
		padding: 14px 22px;
		border-radius: 6px;
		font-size: 14px;
		box-shadow: 0 4px 12px rgba(0,0,0,.2);
		z-index: 99999;
		opacity: 0;
		transform: translateY(-10px);
		transition: opacity .3s ease, transform .3s ease;
		pointer-events: none;
	}
	.db-cart-toast.show {
		opacity: 1;
		transform: translateY(0);
	}
</style>
<div id="db-cart-toast" class="db-cart-toast"></div>
<script>
	jQuery(function($){
		$(document.body).on('added_to_cart', function(event, fragments, cart_hash, $button){
			var message = 'Added to cart!';
			if ($button && $button.length) {
				var name = $button.data('product_name');
				if (name) {
					message = name + ' added to cart!';
				}
			}
			var $toast = $('#db-cart-toast');
			$toast.text(message).addClass('show');
			clearTimeout(window.dbCartToastTimer);
			window.dbCartToastTimer = setTimeout(function(){
				$toast.removeClass('show');
			}, 2500);
		});
	});
</script>
<?php
}

// Load child theme fonts
function motormania_child_enqueue_fonts() {

    wp_enqueue_style(
        'motormania-child-fonts',
        'https://fonts.googleapis.com/css2?family=Archivo:wght@200;300;400;500;600;700&display=swap',
        array(),
        null
    );

}

add_action('wp_enqueue_scripts', 'motormania_child_enqueue_fonts', 20);
// Register a widget area for the header currency switcher
add_action( 'widgets_init', function() {
	register_sidebar( array(
		'name'          => 'Header Currency Switcher',
		'id'            => 'header-currency',
		'description'   => 'WPML currency switcher shown in the header, between the search and cart icons.',
		'before_widget' => '<div class="header-currency-switcher %2$s">',
		'after_widget'  => '</div>',
		'before_title'  => '<span class="screen-reader-text">',
		'after_title'   => '</span>',
	) );
} );

// Style it to sit neatly between the search and cart icons






// shortcode to display accessories by brands

function selected_brands_shortcode($atts) {

	$atts = shortcode_atts([
		'brands' => ''
	], $atts);

	$brand_slugs = array_map('trim', explode(',', $atts['brands']));

	$output = '<div class="brand-grid">';

	foreach ($brand_slugs as $slug) {

		$brand = get_term_by('slug', $slug, 'product_brand');

		if (!$brand) {
			continue;
		}

		// Get brand logo
		$thumbnail_id = get_term_meta($brand->term_id, 'thumbnail_id', true);
		$logo = $thumbnail_id ? wp_get_attachment_image_url($thumbnail_id, 'medium') : '';

		// Product count
		$count = $brand->count;

		$output .= '<div class="brand-card">';
		$output .= '<a href="' . esc_url(get_term_link($brand)) . '">';

		if ($logo) {
			$output .= '<div class="brand-logo">';
			$output .= '<img src="' . esc_url($logo) . '" alt="' . esc_attr($brand->name) . '">';
			$output .= '</div>';
		}

		$output .= '<div class="brand-info">';
		$output .= '<h3>' . esc_html($brand->name) . '</h3>';
		$output .= '<span>' . $count . ' Products</span>';
		$output .= '</div>';

		$output .= '</a>';
		$output .= '</div>';
	}

	$output .= '</div>';

	return $output;
}

add_shortcode('selected_brands', 'selected_brands_shortcode');


// shortcode to display all category to use with custom CSS
function all_category_shortcode($atts) {

	$atts = shortcode_atts(array(
		'categories' => '',
		'columns'    => 4,
	), $atts);

	$args = array(
		'taxonomy'   => 'product_cat',
		'hide_empty' => true,
	);

	// Specific categories
	if (!empty($atts['categories'])) {

		$slugs = array_map('trim', explode(',', $atts['categories']));

		$args['slug'] = $slugs;
	}

	$categories = get_terms($args);

	if (empty($categories) || is_wp_error($categories)) {
		return '<p>No categories found.</p>';
	}

	ob_start();

	echo '<div class="all-category cols-' . intval($atts['columns']) . '">';

	foreach ($categories as $category) {

		$category_link = get_term_link($category);

		// Category image
		$thumbnail_id = get_term_meta($category->term_id, 'thumbnail_id', true);

		$image = wp_get_attachment_image_url($thumbnail_id, 'medium');

		echo '<a class="all-category-item" href="' . esc_url($category_link) . '">';

		// Image
		if ($image) {
			echo '<img src="' . esc_url($image) . '" alt="' . esc_attr($category->name) . '">';
		}

		// Category name
		echo '<h3>' . esc_html($category->name) . '</h3>';

		// Product count
		echo '<span>' . $category->count . ' Products</span>';

		echo '</a>';
	}

	echo '</div>';

	return ob_get_clean();
}

add_shortcode('all_category', 'all_category_shortcode');

// shortcode for helmet category by brands
function helmet_brands_shortcode($atts) {

	$atts = shortcode_atts(array(
		'brands'   => '',
		'category' => '',
		'columns'  => 3,
	), $atts);

	// Brand slugs
	$brand_slugs = array_map('trim', explode(',', $atts['brands']));

	// Category slugs
	$category_slugs = array_map('trim', explode(',', $atts['category']));

	// Convert category slugs to IDs
	$category_ids = array();

	foreach ($category_slugs as $slug) {

		$term = get_term_by('slug', $slug, 'product_cat');

		if ($term) {
			$category_ids[] = $term->term_id;
		}
	}	

	$brands = get_terms(array(
		'taxonomy'   => 'product_brand',
		'hide_empty' => true,
		'slug'       => $brand_slugs,
	));

	if (empty($brands) || is_wp_error($brands)) {
		return '<p>No brands found.</p>';
	}

	ob_start();

	echo '<div class="helmet-brands cols-' . intval($atts['columns']) . '">';

	foreach ($brands as $brand) {

		// Get products count ONLY from helmet category
		$products = get_posts(array(
			'post_type'      => 'product',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'tax_query'      => array(
				'relation' => 'AND',

				// Brand
				array(
					'taxonomy' => 'product_brand',
					'field'    => 'term_id',
					'terms'    => $brand->term_id,
				),

				// Categories
				array(
					'taxonomy' => 'product_cat',
					'field'    => 'slug',
					'terms'    => $category_slugs,
				),	
			),
		));

		$product_count = count($products);

		// Skip if no helmet products
		if ($product_count == 0) {
			continue;
		}


		// Build shop filter URL
		$brand_link = add_query_arg(array(
			'filtering' => '1',
			'filter_product_brand' => $brand->term_id,
			// 			'product_cat' => implode(',', $category_slugs),
			'filter_product_cat' => implode(',', $category_ids),
		), wc_get_page_permalink('shop'));	

		// Brand image
		$thumbnail_id = get_term_meta($brand->term_id, 'thumbnail_id', true);

		$image = wp_get_attachment_image_url($thumbnail_id, 'medium');

		echo '<a class="helmet-brand-item" href="' . esc_url($brand_link) . '">';

		// Image
		if ($image) {
			echo '<img src="' . esc_url($image) . '" alt="' . esc_attr($brand->name) . '">';
		}

		// Title
		echo '<h3>' . esc_html($brand->name) . ' Helmets</h3>';

		// Count
		echo '<span>' . $product_count . ' Products</span>';

		echo '</a>';
	}

	echo '</div>';

	return ob_get_clean();
}

add_shortcode('helmet_brands', 'helmet_brands_shortcode');

// add custom shortcode for Brands
// function custom_shop_by_brands() {

//     $brands = get_terms(array(
//         'taxonomy'   => 'product_brand',
//         'hide_empty' => true,
//     ));

//     if (empty($brands) || is_wp_error($brands)) {
//         return '<p>No brands found.</p>';
//     }

//     ob_start();

//     echo '<div class="shop-by-brands">';

//     foreach ($brands as $brand) {

//         $brand_link = get_term_link($brand);

//         echo '<a class="brand-item" href="' . esc_url($brand_link) . '">';
//         echo esc_html($brand->name);
//         echo '</a>';
//     }

//     echo '</div>';

//     return ob_get_clean();
// }

// add_shortcode('shop_by_brands', 'custom_shop_by_brands');

function custom_shop_by_brands($atts) {

	$atts = shortcode_atts(array(
		'brands'  => '',
		'columns' => 3, // default 3
	), $atts);

	$args = array(
		'taxonomy'   => 'product_brand',
		'hide_empty' => true,
	);

	if (!empty($atts['brands'])) {
		$slugs = array_map('trim', explode(',', $atts['brands']));
		$args['slug'] = $slugs;
	}

	$brands = get_terms($args);

	if (empty($brands) || is_wp_error($brands)) {
		return '<p>No brands found.</p>';
	}

	ob_start();

	echo '<div class="shop-by-brands cols-' . intval($atts['columns']) . '">';

	foreach ($brands as $brand) {

		$brand_link = get_term_link($brand);

		$thumbnail_id = get_term_meta($brand->term_id, 'thumbnail_id', true);
		$image = wp_get_attachment_image_url($thumbnail_id, 'medium');

		echo '<a class="brand-item" href="' . esc_url($brand_link) . '">';

		if ($image) {
			echo '<img src="' . esc_url($image) . '" alt="' . esc_attr($brand->name) . '">';
		}

		echo '<h3>' . esc_html($brand->name) . '</h3>';
		echo '<span>' . $brand->count . ' Products</span>';

		echo '</a>';
	}

	echo '</div>';

	return ob_get_clean();
}

add_shortcode('shop_by_brands', 'custom_shop_by_brands');

add_action( 'wp_enqueue_scripts', function() {
    wp_enqueue_script(
        'motormania-child-custom',
        get_stylesheet_directory_uri() . '/assets/js/custom.js',
        array( 'jquery' ),
        '1.0.0',
        true
    );
}, 20 );


/* =============================================================
 * DUAL STORE — URL-prefix storefronts
 *
 *   /            splash, no store
 *   /my/...      Malaysia, MYR
 *   /gb/...      Global, USD
 *
 * The URL is the source of truth. No store cookie, and cached
 * pages are per-store because the prefix is part of the cache key.
 * ============================================================= */

/** Where unprefixed URLs go — old links, search results. 'my' or 'gb'. */
define( 'DB_DEFAULT_STORE', 'my' );

/** 302 while testing. Move to 301 only once the URLs are settled. */
define( 'DB_LEGACY_REDIRECT_STATUS', 302 );


/* -------------------------------------------------------------
 * 1. The store map
 * ----------------------------------------------------------- */

function db_store_map() {
	return array(
		'my' => array( 'store' => 'local',  'currency' => 'MYR' ),
		'gb' => array( 'store' => 'global', 'currency' => 'USD' ),
	);
}

/** Storefront homepage page IDs, keyed by prefix. */
function db_store_home_ids() {

	$home = 55540; 

	return array(
		'my' => $home,
		'gb' => $home,
	);
}

/* -------------------------------------------------------------
 * 2. Paths that are never store URLs
 *
 *    REST and the Stripe callback live here. Prefixing either one
 *    breaks checkout, so this list is load-bearing.
 * ----------------------------------------------------------- */

function db_is_reserved_path( $path ) {

	$path = ltrim( (string) $path, '/' );

	if ( '' === $path ) {
		return false;
	}

	$reserved = array(
		'wp-admin', 'wp-login.php', 'wp-cron.php', 'wp-json',
		'xmlrpc.php', 'wp-content', 'wp-includes', 'wc-api',
	);

	foreach ( $reserved as $prefix ) {
		if ( $path === $prefix || 0 === strpos( $path, $prefix . '/' ) ) {
			return true;
		}
	}

	// robots.txt, favicon.ico, sitemaps — anything file-like at the root.
	return (bool) preg_match( '#^[^/]+\.[a-z0-9]{2,4}$#i', $path );
}


/* -------------------------------------------------------------
 * 3. Read the store off the URL, then hide the prefix from WP
 * ----------------------------------------------------------- */

$GLOBALS['db_store_prefix'] = '';

function db_detect_store_prefix() {

	if ( empty( $_SERVER['REQUEST_URI'] ) ) {
		return;
	}

	$uri  = $_SERVER['REQUEST_URI'];
	$path = (string) parse_url( $uri, PHP_URL_PATH );

	if ( db_is_reserved_path( $path ) ) {
		return;
	}

	// Whole first segment only. /my/shop/ matches; /my-account/ and
	// /my-1/ must not.
	$keys = implode( '|', array_keys( db_store_map() ) );

	if ( ! preg_match( '#^/(' . $keys . ')(/.*)?$#', $path, $m ) ) {

		// Cart fragments can still arrive at /?wc-ajax=… unprefixed —
		// a cached script, or third-party code holding the old URL.
		// Take the store from the page that asked, so the fragment
		// comes back with prefixed cart links either way.
		if ( ! empty( $_GET['wc-ajax'] ) ) {
			$GLOBALS['db_store_prefix'] = db_effective_store_prefix();
		}

		return;
	}

	$prefix = $m[1];
	$rest   = isset( $m[2] ) ? $m[2] : '';

	$GLOBALS['db_store_prefix']         = $prefix;
	$GLOBALS['db_original_request_uri'] = $uri;

	// Always strip the prefix — including on the bare store roots.
	//
	// Leaving /my/ intact would make WP resolve it as the page "my",
	// which sets is_singular and silently DISCARDS ?s=… — so search
	// from inside a store would return the storefront homepage with
	// no results and no error. Strip to '/' instead and let
	// db_route_store_home() below put the right page back.
	if ( '' === $rest || '/' === $rest ) {

		$query = parse_url( $uri, PHP_URL_QUERY );

		$_SERVER['REQUEST_URI'] = '/' . ( $query ? '?' . $query : '' );

	} else {

		$_SERVER['REQUEST_URI'] = substr( $uri, strlen( '/' . $prefix ) );
	}
}

db_detect_store_prefix();


/* -------------------------------------------------------------
 * 4. Accessors
 * ----------------------------------------------------------- */

/** The prefix from the path: 'my', 'gb', or '' on the splash. */
function db_store_prefix() {
	return isset( $GLOBALS['db_store_prefix'] ) ? $GLOBALS['db_store_prefix'] : '';
}

/**
 * The store for pricing, links and shipping decisions.
 *
 * REST (/wp-json/wc/store/*) and admin-ajax carry no prefix, so for
 * those we read the referring page instead. Without this the block
 * cart could total in a different currency than the page it sits on,
 * and AJAX search results would come back with unprefixed links.
 */
function db_effective_store_prefix() {

	$prefix = db_store_prefix();

	if ( $prefix ) {
		return $prefix;
	}

	if ( ! empty( $_SERVER['HTTP_REFERER'] ) ) {

		$ref  = (string) parse_url( $_SERVER['HTTP_REFERER'], PHP_URL_PATH );
		$keys = implode( '|', array_keys( db_store_map() ) );

		if ( preg_match( '#^/(' . $keys . ')(/|$)#', $ref, $m ) ) {
			return $m[1];
		}
	}

	return '';
}

/** 'local' | 'global' | '' */
function db_get_store() {

	$map    = db_store_map();
	$prefix = db_effective_store_prefix();

	return isset( $map[ $prefix ] ) ? $map[ $prefix ]['store'] : '';
}

/** The real URL of this request, prefix included. */
function db_current_store_url() {

	$uri = isset( $GLOBALS['db_original_request_uri'] )
		? $GLOBALS['db_original_request_uri']
		: ( isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '/' );

	// get_option() rather than home_url() — home_url is filtered below
	// and we need the unprefixed base here.
	return untrailingslashit( get_option( 'home' ) ) . $uri;
}


/* -------------------------------------------------------------
 * 5. Currency, decided by the URL
 *
 *    CURCY reads its current currency from the wmc_current_currency
 *    cookie. We overwrite that value in-process as this file loads,
 *    so the very first render already uses the right currency, then
 *    tell CURCY properly on init so it persists the choice.
 *
 *    The cookie is not our state — the URL is. We rewrite it from
 *    the URL on every request, so it can never drift or expire into
 *    the wrong store.
 * ----------------------------------------------------------- */

function db_seed_store_currency() {

	$map    = db_store_map();
	$prefix = db_effective_store_prefix();

	if ( isset( $map[ $prefix ] ) ) {
		$_COOKIE['wmc_current_currency'] = $map[ $prefix ]['currency'];
	}
}

db_seed_store_currency();

add_action( 'init', 'db_force_store_currency', 0 );

function db_force_store_currency() {

	if ( is_admin() && ! wp_doing_ajax() ) {
		return;
	}

	$map    = db_store_map();
	$prefix = db_effective_store_prefix();

	if ( ! isset( $map[ $prefix ] ) ) {
		return;
	}

	$want = $map[ $prefix ]['currency'];

	// CURCY names its singleton accessor get_ins(); other builds use
	// get_instance(). Try both.
	foreach ( array( 'WOOMULTI_CURRENCY_F_Data', 'WOOMULTI_CURRENCY_Data' ) as $class ) {

		if ( ! class_exists( $class ) ) {
			continue;
		}

		foreach ( array( 'get_ins', 'get_instance' ) as $accessor ) {

			if ( ! method_exists( $class, $accessor ) ) {
				continue;
			}

			$curcy = call_user_func( array( $class, $accessor ) );

			if ( is_object( $curcy ) && method_exists( $curcy, 'set_current_currency' ) ) {
				$curcy->set_current_currency( $want );
				return;
			}
		}
	}

	// Nothing matched. The page will render in whatever currency CURCY
	// picked on its own, which on a /gb/ URL means MYR prices behind a
	// global storefront — so this must be loud, not silent.
	error_log( sprintf(
		'[durianbikers] CURCY setter not found; could not force %s on store "%s". Check woo-multi-currency after a plugin update.',
		$want,
		$prefix
	) );
}


/* -------------------------------------------------------------
 * 6. Put the prefix on every generated link
 *
 *    home_url() is the root of virtually every front-end URL in WP
 *    and WooCommerce, so this one filter covers menus, permalinks,
 *    archives, pagination, cart, checkout and my-account.
 * ----------------------------------------------------------- */

add_filter( 'home_url', 'db_prefix_home_url', 10, 3 );

function db_prefix_home_url( $url, $path, $scheme ) {

	// Effective, not raw: admin-ajax carries no prefix in its own URL,
	// and AJAX search results must still come back with store links.
	$prefix = db_effective_store_prefix();

	if ( ! $prefix ) {
		return $url;
	}

	// REST URLs keep their own shape.
	if ( 'rest' === $scheme ) {
		return $url;
	}

	$candidate = '/' . ltrim( (string) $path, '/' );

	if ( db_is_reserved_path( $candidate ) ) {
		return $url;
	}

	// Relative URLs — home_url( '/', 'relative' ), which is how
	// WooCommerce builds its AJAX endpoint and its add-to-cart
	// links. No host to anchor to, so insert at the front.
	if ( ! preg_match( '#^https?://#i', $url ) ) {
		return '/' . $prefix . '/' . ltrim( $url, '/' );
	}

	// Insert the segment straight after the host.
	return preg_replace(
		'#^(https?://[^/]+)#',
		'${1}/' . $prefix,
		$url,
		1
	);
}


/* -------------------------------------------------------------
 * 6b. Keep the prefix out of WordPress's own request parsing
 *
 *     WP::parse_request() calls home_url() to work out the site's
 *     base path, then strips that path off the front of the request.
 *     With the filter on it sees a base of /my — so /my/ resolves to
 *     nothing and falls through to the front page, and /my/my-account/
 *     becomes "-account" and 404s. Switch it off for the parse only.
 * ----------------------------------------------------------- */

add_filter( 'do_parse_request', function( $continue ) {
	remove_filter( 'home_url', 'db_prefix_home_url', 10 );
	return $continue;
}, 0 );

add_action( 'parse_request', function() {

	add_filter( 'home_url', 'db_prefix_home_url', 10, 3 );

	// Routing is finished, so put the real URL back. add_query_arg()
	// builds on REQUEST_URI, and WooCommerce uses it for add-to-cart
	// links, pagination and the sorting dropdown — all of which drop
	// the prefix while the stripped copy is still in place.
	if ( ! empty( $GLOBALS['db_original_request_uri'] ) ) {
		$_SERVER['REQUEST_URI'] = $GLOBALS['db_original_request_uri'];
	}
}, 0 );


/* -------------------------------------------------------------
 * 6c. Put the storefront homepage back
 *
 *     Section 3 strips /my/ down to '/', which WP resolves to the
 *     splash. Point the bare store root at that store's own page
 *     instead. Only when there are no query vars — a search or a
 *     filtered archive must fall through untouched.
 * ----------------------------------------------------------- */

add_action( 'parse_query', 'db_route_store_home' );

function db_route_store_home( $q ) {

	if ( is_admin() || ! $q->is_main_query() ) {
		return;
	}

	$prefix = db_store_prefix();

	if ( ! $prefix || ! empty( $q->query ) ) {
		return;
	}

	$pages = db_store_home_ids();

	if ( empty( $pages[ $prefix ] ) ) {
		return;
	}

	$q->set( 'page_id', $pages[ $prefix ] );

	$q->is_home     = false;
	$q->is_page     = true;
	$q->is_singular = true;
}


/* -------------------------------------------------------------
 * 7. Stop WordPress fighting us over the canonical URL
 *
 *    redirect_canonical compares the stripped REQUEST_URI against a
 *    prefixed permalink, decides they differ, and redirects — for
 *    ever. Inside a store we own the URL shape, so switch it off.
 * ----------------------------------------------------------- */

add_filter( 'redirect_canonical', function( $redirect_url ) {
	return db_store_prefix() ? false : $redirect_url;
}, 10, 1 );


/* -------------------------------------------------------------
 * 8. Send unprefixed front-end URLs into a store
 *
 *    Old links, indexed URLs, anything hardcoded in Elementor.
 *    GET only — redirecting a POST would throw the body away.
 * ----------------------------------------------------------- */

add_action( 'template_redirect', 'db_redirect_unprefixed_urls', 1 );

function db_redirect_unprefixed_urls() {

	if ( db_store_prefix() ) {
		return; // already in a store
	}

	if ( is_admin() || wp_doing_ajax() || is_front_page() ) {
		return; // wp-admin, AJAX, and the splash itself
	}

	if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || 'GET' !== $_SERVER['REQUEST_METHOD'] ) {
		return;
	}

	if ( ! empty( $_GET['wc-ajax'] ) ) {
		return;
	}

	$path = (string) parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH );

	if ( db_is_reserved_path( $path ) ) {
		return;
	}

	// Keep the visitor in the store they came from. Any link we failed
	// to prefix — an Elementor button, a custom menu item, an AJAX
	// result — lands here, and sending a global customer to the default
	// store would silently switch them to MYR. The referer is not
	// reliable, so DB_DEFAULT_STORE remains the backstop.
	$prefix = db_effective_store_prefix();

	if ( ! $prefix ) {
		$prefix = DB_DEFAULT_STORE;
	}

	$target = preg_replace(
		'#^(https?://[^/]+)#',
		'${1}/' . $prefix,
		db_current_store_url(),
		1
	);

	wp_safe_redirect( $target, DB_LEGACY_REDIRECT_STATUS );
	exit;
}


/* -------------------------------------------------------------
 * 9. Restrict billing + shipping countries to match the store
 * ----------------------------------------------------------- */

add_filter( 'woocommerce_countries_allowed_countries',  'db_store_countries' );
add_filter( 'woocommerce_countries_shipping_countries', 'db_store_countries' );

function db_store_countries( $countries ) {

	// Never restrict wp-admin — you still need every country when
	// editing orders or configuring shipping zones.
	if ( is_admin() && ! wp_doing_ajax() ) {
		return $countries;
	}

	$store = db_get_store();

	// No store in the URL and no referer to read it from: REST calls,
	// cron, webhooks. Fall back to the default store rather than
	// letting the request drift into "global" and lock out Malaysia.
	if ( ! $store ) {
		$map   = db_store_map();
		$store = $map[ DB_DEFAULT_STORE ]['store'];
	}

	if ( 'local' === $store ) {
		return isset( $countries['MY'] )
			? array( 'MY' => $countries['MY'] )
			: $countries;
	}

	// Global store: everywhere except Malaysia.
	unset( $countries['MY'] );

	return $countries;
}