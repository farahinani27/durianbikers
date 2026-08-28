<?php 
add_action( 'wp_enqueue_scripts', 'motormania_child_enqueue_styles' );
function motormania_child_enqueue_styles() {
	wp_enqueue_style( 'parent-style', get_template_directory_uri() . '/style.css', array('bootstrap')); 
} 


// --- my custom code without the parent theme --- //


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
