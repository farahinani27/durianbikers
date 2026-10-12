<?php
/**
 * Plugin Name: Durian Bikers SEO
 * Description: Fixes the WordPress core sitemap to emit CURCY /my/ store-prefixed URLs instead of bare redirecting ones, removes the author ("users") sitemap, excludes the transactional (cart/checkout/my-account) and work-in-progress pages from the sitemap, and holds the GA4 tag.
 * Version:     1.0.0
 * Author:      Durian Bikers
 *
 * Loaded as an mu-plugin: no activation step, and it cannot overwrite theme files.
 * Every constant and function below is guarded, because an older copy of this
 * same code may still be sitting in the child theme's functions.php.
 *
 * @package DurianBikers
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Sitemap: emit the CURCY store-prefixed URL so no <loc> is a 302 redirect
// WordPress canonicals are bare (/product/foo/) and CURCY redirects those to /my/,
// so the core sitemap was listing thousands of redirecting URLs. Rewrite them here.
if ( ! defined( 'DURIANBIKERS_SITEMAP_STORE_PREFIX' ) ) {
	define( 'DURIANBIKERS_SITEMAP_STORE_PREFIX', 'my' );
}

if ( ! function_exists( 'durianbikers_sitemap_store_prefix_url' ) ) {
	function durianbikers_sitemap_store_prefix_url( $url ) {

		// Fail safe: anything we do not fully understand goes back untouched.
		if ( ! is_string( $url ) || '' === trim( $url ) ) {
			return $url;
		}

		$prefix = trim( (string) DURIANBIKERS_SITEMAP_STORE_PREFIX, '/' );

		if ( '' === $prefix ) {
			return $url;
		}

		$parts = wp_parse_url( $url );
		$home  = wp_parse_url( home_url( '/' ) );

		if ( empty( $parts ) || ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return $url;
		}

		if ( empty( $home ) || ! is_array( $home ) || empty( $home['host'] ) ) {
			return $url;
		}

		// Our own host only - never touch an external URL.
		if ( strtolower( $parts['host'] ) !== strtolower( $home['host'] ) ) {
			return $url;
		}

		$path = isset( $parts['path'] ) ? $parts['path'] : '/';

		if ( '' === $path ) {
			$path = '/';
		}

		// The site root is the "Choose Your Store" selector, NOT a storefront page:
		// it is the one bare URL CURCY does not redirect - it returns 200 and is
		// self-canonical. Prefixing it would send the sitemap to /my/, which itself
		// canonicals on to /my/home/. Leave the root exactly as it is.
		if ( '' === ltrim( $path, '/' ) ) {
			return $url;
		}

		// Idempotent: a path already carrying a store segment is left alone,
		// otherwise a URL CURCY already filtered would become /my/my/.
		$first = strtolower( (string) strtok( ltrim( $path, '/' ), '/' ) );

		if ( 'my' === $first || 'gb' === $first ) {
			return $url;
		}

		$path = '/' . $prefix . '/' . ltrim( $path, '/' );

		$rebuilt  = isset( $parts['scheme'] ) ? $parts['scheme'] . '://' : '//';
		$rebuilt .= $parts['host'];
		$rebuilt .= isset( $parts['port'] ) ? ':' . $parts['port'] : '';
		$rebuilt .= $path;
		$rebuilt .= isset( $parts['query'] ) ? '?' . $parts['query'] : '';
		$rebuilt .= isset( $parts['fragment'] ) ? '#' . $parts['fragment'] : '';

		return $rebuilt;
	}
}

add_filter( 'wp_sitemaps_posts_entry', 'durianbikers_sitemap_prefix_entry' );
add_filter( 'wp_sitemaps_taxonomies_entry', 'durianbikers_sitemap_prefix_entry' );

if ( ! function_exists( 'durianbikers_sitemap_prefix_entry' ) ) {
	function durianbikers_sitemap_prefix_entry( $entry ) {

		if ( is_array( $entry ) && ! empty( $entry['loc'] ) ) {
			$entry['loc'] = durianbikers_sitemap_store_prefix_url( $entry['loc'] );
		}

		return $entry;
	}
}

// Drop the author ("users") sitemap only - no SEO value here and it lists usernames
add_filter( 'wp_sitemaps_add_provider', 'durianbikers_sitemap_remove_users_provider', 10, 2 );

if ( ! function_exists( 'durianbikers_sitemap_remove_users_provider' ) ) {
	function durianbikers_sitemap_remove_users_provider( $provider, $name ) {

		if ( 'users' === $name ) {
			return false;
		}

		return $provider;
	}
}

// GA4: standard async gtag.js in <head>
// FILL IN the G- measurement ID below before this does anything - while the
// constant is an empty string this block prints nothing at all.
if ( ! defined( 'DURIANBIKERS_GA4_MEASUREMENT_ID' ) ) {
	define( 'DURIANBIKERS_GA4_MEASUREMENT_ID', '' );
}

add_action( 'wp_head', 'durianbikers_ga4_tag' );

if ( ! function_exists( 'durianbikers_ga4_tag' ) ) {
	function durianbikers_ga4_tag() {

		$id = trim( (string) DURIANBIKERS_GA4_MEASUREMENT_ID );

		// Safe to deploy before the ID exists.
		if ( '' === $id ) {
			return;
		}

		// Keep staff visits out of analytics.
		if ( is_user_logged_in() && current_user_can( 'manage_options' ) ) {
			return;
		}
?>
<script async src="https://www.googletagmanager.com/gtag/js?id=<?php echo esc_attr( $id ); ?>"></script>
<script>
	window.dataLayer = window.dataLayer || [];
	function gtag(){dataLayer.push(arguments);}
	gtag('js', new Date());
	gtag('config', '<?php echo esc_js( $id ); ?>');
</script>
<?php
	}
}

// Sitemap: keep six pages out of it entirely
// They all return 200 with no robots meta tag (no SEO plugin here), so the core
// sitemap was actively submitting cart, checkout, my-account and three
// work-in-progress pages to Google. Excluded at QUERY level, not entry level:
// wp_sitemaps_posts_entry appends whatever it returns, so an empty array there
// yields a malformed empty <url> rather than skipping the page.
// NOTE: /home/ is deliberately NOT excluded - /my/home/ is the canonical storefront.
if ( ! function_exists( 'durianbikers_sitemap_excluded_page_slugs' ) ) {
	function durianbikers_sitemap_excluded_page_slugs() {

		return array(
			'under-construction', // unfinished page, not for public consumption
			'chatbot-test',       // internal chatbot test page
			'hero-banner',        // Elementor template fragment, not a real page
		);
	}
}

add_filter( 'wp_sitemaps_posts_query_args', 'durianbikers_sitemap_exclude_pages', 10, 2 );

if ( ! function_exists( 'durianbikers_sitemap_exclude_pages' ) ) {
	function durianbikers_sitemap_exclude_pages( $args, $post_type ) {

		// Pages only - products and every other post type pass straight through.
		if ( 'page' !== $post_type ) {
			return $args;
		}

		$exclude = array();

		// WooCommerce pages by role, not by slug, so renaming or moving them still works.
		if ( function_exists( 'wc_get_page_id' ) ) {

			foreach ( array( 'cart', 'checkout', 'myaccount' ) as $wc_page ) {

				$wc_id = (int) wc_get_page_id( $wc_page );

				// wc_get_page_id() returns -1 (or 0) when the page is not set.
				if ( $wc_id > 0 ) {
					$exclude[] = $wc_id;
				}
			}
		}

		// Custom pages by slug - silently skipped if one has been deleted.
		foreach ( durianbikers_sitemap_excluded_page_slugs() as $slug ) {

			$page = get_page_by_path( $slug, OBJECT, 'page' );

			if ( $page && ! empty( $page->ID ) ) {
				$exclude[] = (int) $page->ID;
			}
		}

		if ( empty( $exclude ) ) {
			return $args;
		}

		// Merge with anything already excluded rather than overwriting it.
		$existing = array();

		if ( isset( $args['post__not_in'] ) ) {
			$existing = is_array( $args['post__not_in'] ) ? $args['post__not_in'] : array( $args['post__not_in'] );
		}

		$exclude = array_values( array_unique( array_map( 'intval', array_merge( $existing, $exclude ) ) ) );

		if ( ! empty( $exclude ) ) {
			$args['post__not_in'] = $exclude;
		}

		return $args;
	}
}

// TEMPORARY DEPLOY PROBE - remove once the deploy is confirmed.
// Prints one HTML comment in <head> for every visitor, logged in or not, with no
// dependency on the GA4 constant. One page fetch then proves this file is live.
add_action( 'wp_head', 'durianbikers_seo_build_marker' );

if ( ! function_exists( 'durianbikers_seo_build_marker' ) ) {
	function durianbikers_seo_build_marker() {
		echo "<!-- dbk-seo build 1.0.0 -->\n";
	}
}
