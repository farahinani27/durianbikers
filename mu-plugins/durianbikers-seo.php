<?php
/**
 * Plugin Name: Durian Bikers SEO
 * Description: Fixes the WordPress core sitemap to emit CURCY /my/ store-prefixed URLs instead of bare redirecting ones, removes the author ("users") sitemap, excludes the transactional (cart/checkout/my-account) and work-in-progress pages from the sitemap, and holds the GA4 tag.
 * Version:     1.5.0
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
	define( 'DURIANBIKERS_GA4_MEASUREMENT_ID', 'G-L1WSVKJRTQ' );
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

// The six pages that must stay out of Google, resolved to post IDs
// Factored out of the sitemap filter because the robots directives below need
// exactly the same six: the sitemap exclusion only stops US submitting them,
// it does not stop Google indexing one it found by any other route.
if ( ! function_exists( 'durianbikers_excluded_page_ids' ) ) {
	function durianbikers_excluded_page_ids() {

		$ids = array();

		// WooCommerce pages by role, not by slug, so renaming or moving them still works.
		if ( function_exists( 'wc_get_page_id' ) ) {

			foreach ( array( 'cart', 'checkout', 'myaccount' ) as $wc_page ) {

				$wc_id = (int) wc_get_page_id( $wc_page );

				// wc_get_page_id() returns -1 (or 0) when the page is not set.
				if ( $wc_id > 0 ) {
					$ids[] = $wc_id;
				}
			}
		}

		// Custom pages by slug - silently skipped if one has been deleted.
		if ( function_exists( 'get_page_by_path' ) && function_exists( 'durianbikers_sitemap_excluded_page_slugs' ) ) {

			foreach ( durianbikers_sitemap_excluded_page_slugs() as $slug ) {

				$page = get_page_by_path( $slug, OBJECT, 'page' );

				if ( $page && ! empty( $page->ID ) ) {
					$ids[] = (int) $page->ID;
				}
			}
		}

		return array_values( array_unique( array_map( 'intval', $ids ) ) );
	}
}

add_filter( 'wp_sitemaps_posts_query_args', 'durianbikers_sitemap_exclude_pages', 10, 2 );

if ( ! function_exists( 'durianbikers_sitemap_exclude_pages' ) ) {
	function durianbikers_sitemap_exclude_pages( $args, $post_type ) {

		// Pages only - products and every other post type pass straight through.
		if ( 'page' !== $post_type ) {
			return $args;
		}

		// Shared with the robots directives - see durianbikers_excluded_page_ids().
		$exclude = durianbikers_excluded_page_ids();

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

// ===========================================================================
// SEO <head> output: meta description, Open Graph/Twitter, hreflang, robots
// There is NO SEO plugin on this store, so nothing emitted any of these and
// the two CURCY stores competed as duplicates. Everything below is READ-ONLY
// output in <head>: no cart, checkout, pricing or gateway code is touched.
//
// Two deliberate omissions:
//   - Titles are NOT touched. Product names run to 193 characters and choosing
//     where to cut one is an editorial decision, not a mechanical one.
//   - NO canonical tag is added. CURCY already self-canonicals every
//     store-prefixed page, and two canonicals is worse than one.
// ===========================================================================

if ( ! defined( 'DURIANBIKERS_SEO_DESCRIPTION_MAX' ) ) {
	define( 'DURIANBIKERS_SEO_DESCRIPTION_MAX', 155 );
}

// The international store is the generic fallback for anyone we have no
// country match for, so it - not /my/ - carries hreflang="x-default".
if ( ! defined( 'DURIANBIKERS_SEO_XDEFAULT_STORE' ) ) {
	define( 'DURIANBIKERS_SEO_XDEFAULT_STORE', 'gb' );
}

// The CURCY store segments and their hreflang codes, in ONE place
// A third store is a one-line change here; nothing below hardcodes a prefix.
if ( ! function_exists( 'durianbikers_seo_store_prefixes' ) ) {
	function durianbikers_seo_store_prefixes() {

		return array(
			'my' => 'en-MY', // Malaysia, MYR
			'gb' => 'en-GB', // international, USD
		);
	}
}

// One gate for every block below
// A missing tag is harmless; output in the wrong context (admin, REST, AJAX,
// feed, sitemap XML, 404) is not. Bail quietly on all of them.
if ( ! function_exists( 'durianbikers_seo_head_allowed' ) ) {
	function durianbikers_seo_head_allowed() {

		if ( is_admin() ) {
			return false;
		}

		if ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) {
			return false;
		}

		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return false;
		}

		if ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) {
			return false;
		}

		// wp_head() does not run for the core sitemap, but belt and braces:
		// a sitemap request is identified by its own query vars.
		if ( function_exists( 'get_query_var' ) ) {

			if ( '' !== (string) get_query_var( 'sitemap' ) || '' !== (string) get_query_var( 'sitemap-stylesheet' ) ) {
				return false;
			}
		}

		if ( function_exists( 'is_feed' ) && is_feed() ) {
			return false;
		}

		if ( function_exists( 'is_embed' ) && is_embed() ) {
			return false;
		}

		// A 404 has no subject, so describing or sharing it is meaningless.
		if ( function_exists( 'is_404' ) && is_404() ) {
			return false;
		}

		return true;
	}
}

// Where the description text comes from, before any cleaning
// Priority is per page type; the front page and the shop archive have no
// honest source, so they return '' and the tag is skipped entirely.
if ( ! function_exists( 'durianbikers_seo_description_source' ) ) {
	function durianbikers_seo_description_source() {

		// TODO: the "Choose Your Store" root and the shop archive both need a
		// HAND-WRITTEN description added here. Until then they get none, which
		// is better than a machine-invented one.
		if ( function_exists( 'is_front_page' ) && is_front_page() ) {
			return '';
		}

		if ( function_exists( 'is_home' ) && is_home() ) {
			return '';
		}

		if ( function_exists( 'is_shop' ) && is_shop() ) {
			return '';
		}

		if ( is_singular() ) {

			$post = get_queried_object();

			if ( ! $post instanceof WP_Post ) {
				return '';
			}

			// Products: the short description IS post_excerpt in WooCommerce.
			$text = (string) $post->post_excerpt;

			if ( '' === trim( $text ) ) {
				$text = (string) $post->post_content;
			}

			return $text;
		}

		// Product category / tag / brand are all taxonomies, so is_tax()
		// covers product_cat, product_tag and product_brand in one.
		if ( is_category() || is_tag() || is_tax() ) {

			$term = get_queried_object();

			if ( $term instanceof WP_Term ) {
				return (string) $term->description;
			}
		}

		return '';
	}
}

// The ONE description function - both the meta tag and og:description call it
// Factored so the two can never disagree, and memoised so a product's full
// post_content is only cleaned once per request.
if ( ! function_exists( 'durianbikers_seo_description' ) ) {
	function durianbikers_seo_description() {

		static $cached = null;

		if ( null !== $cached ) {
			return $cached;
		}

		$cached = '';
		$raw    = durianbikers_seo_description_source();

		if ( ! is_string( $raw ) || '' === trim( $raw ) ) {
			return $cached;
		}

		// Shortcodes first: Elementor/Woo shortcodes would otherwise leave
		// their attributes behind once the tags around them are stripped.
		$text = strip_shortcodes( $raw );

		// Block-level tags become a space, or "word</p><p>word" fuses.
		$text = str_replace(
			array( '<br>', '<br/>', '<br />', '</p>', '</li>', '</div>', '</h1>', '</h2>', '</h3>' ),
			' ',
			$text
		);

		$text = wp_strip_all_tags( $text );
		$text = html_entity_decode( $text, ENT_QUOTES, 'UTF-8' );
		$text = preg_replace( '/\s+/u', ' ', $text );
		$text = trim( (string) $text );

		if ( '' === $text ) {
			return $cached;
		}

		$max = (int) DURIANBIKERS_SEO_DESCRIPTION_MAX;

		if ( $max > 0 && durianbikers_seo_strlen( $text ) > $max ) {

			// Reserve one character for the ellipsis so the whole string,
			// ellipsis included, still fits inside the limit.
			$cut   = durianbikers_seo_substr( $text, 0, $max - 1 );
			$space = function_exists( 'mb_strrpos' ) ? mb_strrpos( $cut, ' ', 0, 'UTF-8' ) : strrpos( $cut, ' ' );

			// Word boundary: never cut mid-word.
			if ( false !== $space && $space > 0 ) {
				$cut = durianbikers_seo_substr( $cut, 0, (int) $space );
			}

			$cut = rtrim( $cut, " \t\n\r\0\x0B,;:-" );

			if ( '' === trim( $cut ) ) {
				return $cached;
			}

			$text = $cut . "\xE2\x80\xA6";
		}

		// Whitespace-only never ships.
		if ( '' === trim( $text ) ) {
			return $cached;
		}

		$cached = $text;

		return $cached;
	}
}

// mbstring is not guaranteed on this host, so both helpers degrade to bytes
if ( ! function_exists( 'durianbikers_seo_strlen' ) ) {
	function durianbikers_seo_strlen( $text ) {

		return function_exists( 'mb_strlen' ) ? (int) mb_strlen( (string) $text, 'UTF-8' ) : strlen( (string) $text );
	}
}

if ( ! function_exists( 'durianbikers_seo_substr' ) ) {
	function durianbikers_seo_substr( $text, $start, $length ) {

		if ( function_exists( 'mb_substr' ) ) {
			return (string) mb_substr( (string) $text, (int) $start, (int) $length, 'UTF-8' );
		}

		return (string) substr( (string) $text, (int) $start, (int) $length );
	}
}

// 1. Meta description
// Skipped entirely when there is no source rather than padded with the site
// tagline - an empty description is better than a duplicated one.
add_action( 'wp_head', 'durianbikers_seo_meta_description', 2 );

if ( ! function_exists( 'durianbikers_seo_meta_description' ) ) {
	function durianbikers_seo_meta_description() {

		if ( ! durianbikers_seo_head_allowed() ) {
			return;
		}

		$description = durianbikers_seo_description();

		if ( '' === trim( (string) $description ) ) {
			return;
		}

		echo '<meta name="description" content="' . esc_attr( $description ) . '" />' . "\n";
	}
}

// The path of the current request, normalised to a leading slash
// Read straight off REQUEST_URI because the store prefix is a path segment
// CURCY adds, not something derivable from a post ID.
if ( ! function_exists( 'durianbikers_seo_request_path' ) ) {
	function durianbikers_seo_request_path() {

		$uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( (string) $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

		$path = wp_parse_url( $uri, PHP_URL_PATH );

		if ( ! is_string( $path ) || '' === $path ) {
			return '/';
		}

		return '/' . ltrim( $path, '/' );
	}
}

// Which store this request is in: 'my', 'gb' or '' for none
// '' is the site root, wp-admin, feeds - everything CURCY does not prefix.
if ( ! function_exists( 'durianbikers_seo_current_store_prefix' ) ) {
	function durianbikers_seo_current_store_prefix() {

		$first = strtolower( (string) strtok( ltrim( durianbikers_seo_request_path(), '/' ), '/' ) );

		$prefixes = durianbikers_seo_store_prefixes();

		return isset( $prefixes[ $first ] ) ? $first : '';
	}
}

// The current path with its store segment removed (or unchanged if it had none)
if ( ! function_exists( 'durianbikers_seo_path_without_store' ) ) {
	function durianbikers_seo_path_without_store() {

		$path    = ltrim( durianbikers_seo_request_path(), '/' );
		$current = durianbikers_seo_current_store_prefix();

		if ( '' === $current ) {
			return $path;
		}

		return ltrim( (string) substr( $path, strlen( $current ) ), '/' );
	}
}

// 1c. The bare site base: scheme://host[:port], with NO path
// CURCY filters home_url(), so home_url('/') already carries the current
// store prefix. Appending a prefix to it therefore produced /my/my/... and
// /my/gb/... - both 404. Anything that builds a store-prefixed URL by hand
// must start from the host alone, exactly as the sitemap helper above does.
if ( ! function_exists( 'durianbikers_seo_site_base' ) ) {
	function durianbikers_seo_site_base() {

		$home = wp_parse_url( home_url( '/' ) );

		if ( empty( $home ) || ! is_array( $home ) || empty( $home['host'] ) ) {
			return '';
		}

		$scheme = isset( $home['scheme'] ) ? $home['scheme'] : 'https';
		$base   = $scheme . '://' . $home['host'];

		if ( isset( $home['port'] ) ) {
			$base .= ':' . $home['port'];
		}

		return $base;
	}
}

// 2a. The page's own canonical URL, store prefix included
// wp_get_canonical_url() for singular (CURCY filters permalinks, so it
// normally already carries the prefix); the live request URL otherwise.
if ( ! function_exists( 'durianbikers_seo_canonical_url' ) ) {
	function durianbikers_seo_canonical_url() {

		$url = '';

		if ( is_singular() && function_exists( 'wp_get_canonical_url' ) ) {

			$canonical = wp_get_canonical_url();

			if ( is_string( $canonical ) && '' !== $canonical ) {
				$url = $canonical;
			}
		}

		if ( '' === $url ) {
			// REQUEST_URI already carries the store prefix, so this must be
			// hung off the bare host - not home_url(), which carries it too.
			$url = durianbikers_seo_site_base() . '/' . ltrim( durianbikers_seo_request_path(), '/' );
		}

		// Safety net: if we are being served under a store prefix but the
		// canonical came back bare, og:url would point at a redirecting URL.
		// Re-apply the CURRENT prefix - never a hardcoded one.
		$current = durianbikers_seo_current_store_prefix();

		if ( '' !== $current ) {

			$path     = (string) wp_parse_url( $url, PHP_URL_PATH );
			$first    = strtolower( (string) strtok( ltrim( $path, '/' ), '/' ) );
			$prefixes = durianbikers_seo_store_prefixes();

			if ( '' !== ltrim( $path, '/' ) && ! isset( $prefixes[ $first ] ) ) {
				$url = durianbikers_seo_site_base() . '/' . $current . '/' . ltrim( $path, '/' );
			}
		}

		return $url;
	}
}

// 2b. The subject's own title, with the site-name suffix left off
// Deliberately NOT truncated - see the note at the top of this section.
if ( ! function_exists( 'durianbikers_seo_title' ) ) {
	function durianbikers_seo_title() {

		$title = '';

		if ( is_singular() ) {

			$post = get_queried_object();

			if ( $post instanceof WP_Post ) {
				$title = get_the_title( $post );
			}
		} elseif ( is_category() || is_tag() || is_tax() ) {

			$term = get_queried_object();

			if ( $term instanceof WP_Term ) {
				$title = $term->name;
			}
		}

		if ( '' === trim( (string) $title ) && function_exists( 'wp_get_document_title' ) ) {
			$title = wp_get_document_title();
		}

		$title = wp_strip_all_tags( html_entity_decode( (string) $title, ENT_QUOTES, 'UTF-8' ) );
		$title = preg_replace( '/\s+/u', ' ', $title );

		return trim( (string) $title );
	}
}

// 2. Open Graph + Twitter Card
// og:description reuses durianbikers_seo_description() so the share preview
// and the meta description can never drift apart.
add_action( 'wp_head', 'durianbikers_seo_open_graph', 3 );

if ( ! function_exists( 'durianbikers_seo_open_graph' ) ) {
	function durianbikers_seo_open_graph() {

		if ( ! durianbikers_seo_head_allowed() ) {
			return;
		}

		$title = durianbikers_seo_title();
		$url   = durianbikers_seo_canonical_url();

		if ( '' === trim( (string) $title ) || '' === trim( (string) $url ) ) {
			return;
		}

		$type = 'website';

		if ( is_singular( 'product' ) ) {
			$type = 'product';
		} elseif ( is_singular( 'post' ) ) {
			$type = 'article';
		}

		echo '<meta property="og:type" content="' . esc_attr( $type ) . '" />' . "\n";
		echo '<meta property="og:title" content="' . esc_attr( $title ) . '" />' . "\n";

		$description = durianbikers_seo_description();

		if ( '' !== trim( (string) $description ) ) {
			echo '<meta property="og:description" content="' . esc_attr( $description ) . '" />' . "\n";
		}

		echo '<meta property="og:url" content="' . esc_url( $url ) . '" />' . "\n";

		$site_name = wp_strip_all_tags( (string) get_bloginfo( 'name' ) );

		if ( '' !== trim( $site_name ) ) {
			echo '<meta property="og:site_name" content="' . esc_attr( $site_name ) . '" />' . "\n";
		}

		// Featured image at full size. NO logo fallback: a share card showing
		// the logo for every product is worse than one showing no image, and
		// picking a default image is a branding decision, not a code one.
		$image = '';

		if ( is_singular() && function_exists( 'get_post_thumbnail_id' ) && function_exists( 'wp_get_attachment_image_url' ) ) {

			$thumbnail_id = (int) get_post_thumbnail_id( get_queried_object_id() );

			if ( $thumbnail_id > 0 ) {

				$src = wp_get_attachment_image_url( $thumbnail_id, 'full' );

				if ( is_string( $src ) && '' !== $src ) {
					$image = $src;
				}
			}
		}

		if ( '' !== $image ) {
			echo '<meta property="og:image" content="' . esc_url( $image ) . '" />' . "\n";
		}

		echo '<meta name="twitter:card" content="summary_large_image" />' . "\n";
	}
}

// 3. hreflang between the /my/ and /gb/ stores
// Both stores are full copies of the same ~5,215 products and each
// self-canonicals, so with no hreflang they compete as duplicates. These tags
// tell Google they are the same page for different markets.
//
// CRITICAL: emits NOTHING unless the current URL already carries a store
// prefix. A set of alternates that does not point back at the page serving it
// is not reciprocal, and a non-reciprocal set is worse than no set at all -
// so the site root, wp-admin and feeds get none.
add_action( 'wp_head', 'durianbikers_seo_hreflang', 4 );

if ( ! function_exists( 'durianbikers_seo_hreflang' ) ) {
	function durianbikers_seo_hreflang() {

		if ( ! durianbikers_seo_head_allowed() ) {
			return;
		}

		// Paginated / filtered / tracked URLs: the swapped twin is not
		// guaranteed to be the same result set, so claim nothing.
		if ( ! empty( $_SERVER['QUERY_STRING'] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			return;
		}

		$current = durianbikers_seo_current_store_prefix();

		if ( '' === $current ) {
			return;
		}

		// Swap the FIRST path segment on the current URL - no permalink
		// rebuilding and no post-ID guessing, so it works for products,
		// archives and plain pages alike.
		$rest     = durianbikers_seo_path_without_store();
		$prefixes = durianbikers_seo_store_prefixes();
		$urls     = array();

		foreach ( $prefixes as $prefix => $hreflang ) {

			$urls[ $prefix ] = durianbikers_seo_site_base() . '/' . $prefix . '/' . $rest;

			echo '<link rel="alternate" hreflang="' . esc_attr( $hreflang ) . '" href="' . esc_url( $urls[ $prefix ] ) . '" />' . "\n";
		}

		$default = (string) DURIANBIKERS_SEO_XDEFAULT_STORE;

		if ( isset( $urls[ $default ] ) ) {
			echo '<link rel="alternate" hreflang="x-default" href="' . esc_url( $urls[ $default ] ) . '" />' . "\n";
		}
	}
}

// 4. noindex, nofollow on the transactional and work-in-progress pages
// Cart, checkout, my-account plus the three WIP pages are already kept out of
// the sitemap, but they still return 200 with no robots directive - so any
// other route to them (a link, a crawl, an old index entry) leaves them
// indexable. Done through the wp_robots filter rather than a raw meta tag so
// core stays the single owner of that one tag.
// Priority 99: WooCommerce also filters wp_robots for cart/checkout/my-account
// and re-adds `follow` after us, which produced the self-contradictory
// "noindex, nofollow, follow". Running late gives this the final word.
add_filter( 'wp_robots', 'durianbikers_seo_noindex_excluded_pages', 99 );

if ( ! function_exists( 'durianbikers_seo_noindex_excluded_pages' ) ) {
	function durianbikers_seo_noindex_excluded_pages( $robots ) {

		if ( ! is_array( $robots ) ) {
			$robots = array();
		}

		if ( is_admin() || ! function_exists( 'is_page' ) || ! is_page() ) {
			return $robots;
		}

		$page_id = (int) get_queried_object_id();

		if ( $page_id <= 0 ) {
			return $robots;
		}

		if ( ! function_exists( 'durianbikers_excluded_page_ids' ) ) {
			return $robots;
		}

		if ( ! in_array( $page_id, durianbikers_excluded_page_ids(), true ) ) {
			return $robots;
		}

		if ( function_exists( 'wp_robots_no_robots' ) ) {
			$robots = wp_robots_no_robots( $robots );
		}

		// wp_robots_no_robots() only guarantees noindex, and it never removes a
		// conflicting index/follow another filter may have set first.
		unset( $robots['index'], $robots['follow'] );

		$robots['noindex']  = true;
		$robots['nofollow'] = true;

		return $robots;
	}
}

// TEMPORARY DEPLOY PROBE - remove once the deploy is confirmed.
// Prints one HTML comment in <head> for every visitor, logged in or not, with no
// dependency on the GA4 constant. One page fetch then proves this file is live.
// 6. Google Search Console ownership verification
// URL-prefix property https://durianbikers.com/ on admindurianbikers@gmail.com,
// created 2026-10-12. Note there is ALSO a google-site-verification TXT record
// on the domain belonging to some OTHER Google account (Shopify era or an old
// agency) - that one is not ours and does not verify this property.
// Google requires this tag to STAY after verification succeeds: removing it
// un-verifies the property and data collection stops.
if ( ! defined( 'DURIANBIKERS_GSC_VERIFICATION' ) ) {
	define( 'DURIANBIKERS_GSC_VERIFICATION', 'LUXU8qcvdSxo3vXWh63ov0tcu7VkKFrjQMgZQIbv-iw' );
}

// Priority 1, and deliberately NOT behind durianbikers_seo_head_allowed():
// Google fetches the home page and wants this present unconditionally.
add_action( 'wp_head', 'durianbikers_gsc_verification', 1 );

if ( ! function_exists( 'durianbikers_gsc_verification' ) ) {
	function durianbikers_gsc_verification() {

		$token = trim( (string) DURIANBIKERS_GSC_VERIFICATION );

		if ( '' === $token ) {
			return;
		}

		echo '<meta name="google-site-verification" content="' . esc_attr( $token ) . '" />' . "\n";
	}
}

add_action( 'wp_head', 'durianbikers_seo_build_marker' );

if ( ! function_exists( 'durianbikers_seo_build_marker' ) ) {
	function durianbikers_seo_build_marker() {
		echo "<!-- dbk-seo build 1.5.0 -->\n";
	}
}

// ===========================================================================
// 7. The /gb/ store's own XML sitemap
// The sitemap filters at the top of this file rewrite every <loc> to the ONE
// prefix in DURIANBIKERS_SITEMAP_STORE_PREFIX ('my'), so the ~5,215 /gb/
// product URLs and every /gb/ term archive were in no sitemap at all - even
// though the hreflang block above already tells Google they exist.
//
// Done as ADDITIONAL core sitemap providers rather than by widening the
// existing filters: the /my/ output keeps running through exactly the code
// that is live today, untouched, and the /gb/ output is a separate tree that
// can be removed by deleting this block alone.
//
// Two providers, because the registry is keyed by provider name and one name
// resolves to one provider:
//   gb    -> /wp-sitemap-gb-product-1.xml      (posts: product, page, post)
//   gbtax -> /wp-sitemap-gbtax-product_cat-1.xml (taxonomies)
// Both names are plain lowercase letters on purpose: core's sitemap rewrite
// rule matches ^wp-sitemap-([a-z]+?)-..., so no rewrite flush is needed. A
// name with a digit or a hyphen would 404 until permalinks were re-saved.
//
// Exclusions are INHERITED, not re-implemented: the parent classes build
// their queries through get_posts_query_args(), which fires
// wp_sitemaps_posts_query_args - so durianbikers_sitemap_exclude_pages()
// already removes the same six pages here, and get_max_num_pages() counts
// the same set. The users/author sitemap stays removed for the same reason.
// ===========================================================================

// Rewrite one <loc> onto a given store prefix, whatever prefix it arrives with
// The parent classes fire wp_sitemaps_posts_entry / wp_sitemaps_taxonomies_entry
// while building their list, so a loc reaching us has ALREADY been through
// durianbikers_sitemap_store_prefix_url() and carries /my/. This therefore
// SWAPS the leading store segment instead of prepending to it - prepending is
// what produced /my/my/ and /my/gb/ before, and those 404.
// Returns '' to mean "drop this entry entirely".
if ( ! function_exists( 'durianbikers_sitemap_swap_store_prefix_url' ) ) {
	function durianbikers_sitemap_swap_store_prefix_url( $url, $prefix ) {

		// Fail safe: anything we do not fully understand is dropped rather than
		// emitted wrong. A short sitemap is recoverable; a sitemap full of
		// redirecting or 404ing locs is the bug we fixed today.
		if ( ! is_string( $url ) || '' === trim( $url ) ) {
			return '';
		}

		$prefix = trim( (string) $prefix, '/' );

		if ( '' === $prefix ) {
			return '';
		}

		$parts = wp_parse_url( $url );
		$home  = wp_parse_url( home_url( '/' ) );

		if ( empty( $parts ) || ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return '';
		}

		if ( empty( $home ) || ! is_array( $home ) || empty( $home['host'] ) ) {
			return '';
		}

		// Our own host only - never rewrite, and never publish, a foreign URL.
		if ( strtolower( $parts['host'] ) !== strtolower( $home['host'] ) ) {
			return '';
		}

		$rest = ltrim( isset( $parts['path'] ) ? (string) $parts['path'] : '', '/' );

		// Strip an existing store segment, so this is idempotent.
		if ( function_exists( 'durianbikers_seo_store_prefixes' ) ) {

			$first    = strtolower( (string) strtok( $rest, '/' ) );
			$prefixes = durianbikers_seo_store_prefixes();

			if ( isset( $prefixes[ $first ] ) ) {
				$rest = ltrim( (string) substr( $rest, strlen( $first ) ), '/' );
			}
		}

		// The site root is the "Choose Your Store" selector, not a storefront
		// page. It belongs once, in the existing /my/ output, and never here.
		if ( '' === $rest ) {
			return '';
		}

		$rebuilt  = isset( $parts['scheme'] ) ? $parts['scheme'] . '://' : '//';
		$rebuilt .= $parts['host'];
		$rebuilt .= isset( $parts['port'] ) ? ':' . $parts['port'] : '';
		$rebuilt .= '/' . $prefix . '/' . $rest;
		$rebuilt .= isset( $parts['query'] ) ? '?' . $parts['query'] : '';
		$rebuilt .= isset( $parts['fragment'] ) ? '#' . $parts['fragment'] : '';

		return $rebuilt;
	}
}

// Map a whole url_list onto the /gb/ store, dropping anything unusable
if ( ! function_exists( 'durianbikers_sitemap_gb_url_list' ) ) {
	function durianbikers_sitemap_gb_url_list( $url_list ) {

		if ( ! is_array( $url_list ) || empty( $url_list ) ) {
			return array();
		}

		$out = array();

		foreach ( $url_list as $entry ) {

			if ( ! is_array( $entry ) || empty( $entry['loc'] ) ) {
				continue;
			}

			$loc = durianbikers_sitemap_swap_store_prefix_url( $entry['loc'], 'gb' );

			// '' means the helper refused it (root, foreign host, unparseable).
			if ( '' === $loc ) {
				continue;
			}

			$entry['loc'] = $loc;
			$out[]        = $entry;
		}

		return $out;
	}
}

// CRITICAL TIMING: WP_Sitemaps_Posts / WP_Sitemaps_Taxonomies do not exist
// when mu-plugins load - core includes them on init, then fires
// wp_sitemaps_init once the registry is built. So the subclasses are declared
// INSIDE this callback, and if the classes are absent (core changed, sitemaps
// disabled) it returns quietly and the /my/ sitemap is unaffected.
add_action( 'wp_sitemaps_init', 'durianbikers_sitemap_register_gb_providers' );

if ( ! function_exists( 'durianbikers_sitemap_register_gb_providers' ) ) {
	function durianbikers_sitemap_register_gb_providers() {

		if ( ! function_exists( 'wp_register_sitemap_provider' ) ) {
			return;
		}

		if ( ! class_exists( 'WP_Sitemaps_Posts' ) || ! class_exists( 'WP_Sitemaps_Taxonomies' ) ) {
			return;
		}

		// Guarded because an older copy of this code may already have declared
		// them, and a second declaration is a fatal error.
		if ( ! class_exists( 'Durianbikers_Sitemaps_Posts_Gb' ) ) {

			/**
			 * The posts sitemap, with every <loc> moved onto the /gb/ store.
			 */
			class Durianbikers_Sitemaps_Posts_Gb extends WP_Sitemaps_Posts {

				public function __construct() {

					parent::__construct();

					// The registry does NOT set this from the name it is
					// registered under, and get_sitemap_entries() builds the
					// index URLs from it - so it must be set here.
					$this->name = 'gb';
				}

				public function get_url_list( $page_num, $object_subtype = '' ) {

					return durianbikers_sitemap_gb_url_list( parent::get_url_list( $page_num, $object_subtype ) );
				}
			}
		}

		if ( ! class_exists( 'Durianbikers_Sitemaps_Taxonomies_Gb' ) ) {

			/**
			 * The taxonomies sitemap, with every <loc> moved onto the /gb/ store.
			 */
			class Durianbikers_Sitemaps_Taxonomies_Gb extends WP_Sitemaps_Taxonomies {

				public function __construct() {

					parent::__construct();

					$this->name = 'gbtax';
				}

				public function get_url_list( $page_num, $object_subtype = '' ) {

					return durianbikers_sitemap_gb_url_list( parent::get_url_list( $page_num, $object_subtype ) );
				}
			}
		}

		// add_provider() returns false rather than throwing if the name is
		// taken, so calling this twice is harmless.
		wp_register_sitemap_provider( 'gb', new Durianbikers_Sitemaps_Posts_Gb() );
		wp_register_sitemap_provider( 'gbtax', new Durianbikers_Sitemaps_Taxonomies_Gb() );
	}
}

// ===========================================================================
// 8. BreadcrumbList JSON-LD
// There was none anywhere on the store, so Google renders the raw URL as the
// breadcrumb in search results. Read-only <head> output: no cart, checkout,
// pricing or gateway code is touched.
//
// Every item URL is built from durianbikers_seo_site_base() plus the CURRENT
// store prefix, so a /gb/ page emits an all-/gb/ trail. Nothing here appends
// to home_url() - CURCY filters that and already returns the prefix.
// ===========================================================================

// Where "Home" points inside a store, as a path under /{prefix}/
// 'home', not empty: the store root (/my/, /gb/) canonicals on to
// /{prefix}/home/, and a breadcrumb item should name the canonical URL rather
// than one that points the signal somewhere else.
if ( ! defined( 'DURIANBIKERS_SEO_BREADCRUMB_HOME_PATH' ) ) {
	define( 'DURIANBIKERS_SEO_BREADCRUMB_HOME_PATH', 'home' );
}

// Force any URL onto the current store prefix, absolute, with no query string
// get_term_link() and get_permalink() normally come back already prefixed
// because CURCY filters home_url(), but "normally" is not a guarantee and a
// breadcrumb item pointing at a redirecting URL is a wasted signal.
// Returns '' when the URL is not ours or cannot be parsed - callers drop it.
if ( ! function_exists( 'durianbikers_seo_store_url' ) ) {
	function durianbikers_seo_store_url( $url ) {

		if ( ! is_string( $url ) || '' === trim( $url ) ) {
			return '';
		}

		$base = durianbikers_seo_site_base();

		if ( '' === $base ) {
			return '';
		}

		$parts = wp_parse_url( $url );

		if ( empty( $parts ) || ! is_array( $parts ) ) {
			return '';
		}

		// Our own host only.
		if ( ! empty( $parts['host'] ) ) {

			$home = wp_parse_url( $base );

			if ( empty( $home['host'] ) || strtolower( $parts['host'] ) !== strtolower( $home['host'] ) ) {
				return '';
			}
		}

		$rest     = ltrim( isset( $parts['path'] ) ? (string) $parts['path'] : '', '/' );
		$prefixes = durianbikers_seo_store_prefixes();
		$first    = strtolower( (string) strtok( $rest, '/' ) );

		// Strip whatever store segment it arrived with, then re-add ours.
		if ( isset( $prefixes[ $first ] ) ) {
			$rest = ltrim( (string) substr( $rest, strlen( $first ) ), '/' );
		}

		$current = durianbikers_seo_current_store_prefix();

		if ( '' !== $current ) {
			$rest = $current . '/' . $rest;
		}

		// Query strings and fragments are deliberately dropped: a breadcrumb
		// item is the clean page, not this particular sorted view of it.
		return $base . '/' . ltrim( $rest, '/' );
	}
}

// The current store's home URL, built from the host - never from home_url()
if ( ! function_exists( 'durianbikers_seo_store_home_url' ) ) {
	function durianbikers_seo_store_home_url() {

		$base = durianbikers_seo_site_base();

		if ( '' === $base ) {
			return '';
		}

		$current = durianbikers_seo_current_store_prefix();
		$path    = trim( (string) DURIANBIKERS_SEO_BREADCRUMB_HOME_PATH, '/' );

		$url = $base . '/';

		if ( '' !== $current ) {
			$url .= $current . '/';
		}

		if ( '' !== $path ) {
			$url .= $path . '/';
		}

		return $url;
	}
}

// A term and its ancestors, top-down, as breadcrumb rows
// Ancestors first so the trail reads Home -> Parent -> Child. Every term call
// is guarded: a broken ancestor list must cost us the crumb, not the page.
if ( ! function_exists( 'durianbikers_seo_breadcrumb_term_trail' ) ) {
	function durianbikers_seo_breadcrumb_term_trail( $term ) {

		$rows = array();

		if ( ! $term instanceof WP_Term || empty( $term->taxonomy ) ) {
			return $rows;
		}

		$chain = array();

		if ( function_exists( 'get_ancestors' ) ) {

			$ancestors = get_ancestors( (int) $term->term_id, $term->taxonomy, 'taxonomy' );

			if ( is_array( $ancestors ) && ! empty( $ancestors ) ) {

				// get_ancestors() returns nearest-parent first.
				foreach ( array_reverse( $ancestors ) as $ancestor_id ) {

					$ancestor = get_term( (int) $ancestor_id, $term->taxonomy );

					if ( $ancestor instanceof WP_Term && ! is_wp_error( $ancestor ) ) {
						$chain[] = $ancestor;
					}
				}
			}
		}

		$chain[] = $term;

		foreach ( $chain as $node ) {

			$name = wp_strip_all_tags( html_entity_decode( (string) $node->name, ENT_QUOTES, 'UTF-8' ) );
			$name = trim( (string) preg_replace( '/\s+/u', ' ', $name ) );

			if ( '' === $name ) {
				continue;
			}

			$link = get_term_link( $node );

			if ( is_wp_error( $link ) || ! is_string( $link ) || '' === $link ) {
				continue;
			}

			$url = durianbikers_seo_store_url( $link );

			if ( '' === $url ) {
				continue;
			}

			$rows[] = array(
				'name' => $name,
				'item' => $url,
			);
		}

		return $rows;
	}
}

// The product's own category crumb: its first product_cat term, plus parents
// WooCommerce has no "primary category" concept without an SEO plugin, so the
// first assigned term is the honest choice. No category at all is fine - the
// trail is then just Home -> product.
if ( ! function_exists( 'durianbikers_seo_breadcrumb_product_term' ) ) {
	function durianbikers_seo_breadcrumb_product_term( $post_id ) {

		$post_id = (int) $post_id;

		if ( $post_id <= 0 || ! function_exists( 'get_the_terms' ) ) {
			return null;
		}

		$terms = get_the_terms( $post_id, 'product_cat' );

		if ( is_wp_error( $terms ) || empty( $terms ) || ! is_array( $terms ) ) {
			return null;
		}

		foreach ( $terms as $term ) {

			if ( $term instanceof WP_Term ) {
				return $term;
			}
		}

		return null;
	}
}

// Priority 5: after hreflang (4), before the taxonomy canonical (6).
// DISABLED 2026-10-13, deliberately. The MotorMania theme already emits a
// BreadcrumbList as microdata in its <section class="breadcrumbs"> block
// (itemtype=schema.org/BreadcrumbList, trail "Home > Products > <item>"), on
// both products and term archives. Registering this JSON-LD as well put TWO
// BreadcrumbList declarations on the same page, and they disagreed: the theme
// says "Home > Products > X", this says "Home > <real category> > X".
// Conflicting structured data for one entity is worse than a plainer single
// source, so the theme's stays and this is left unregistered.
// To switch over properly, the theme's microdata block has to be suppressed
// FIRST - the theme is edited directly on the server and diverges from this
// repo, so that is not a change to make blind.
// add_action( 'wp_head', 'durianbikers_seo_breadcrumb_schema', 5 );

if ( ! function_exists( 'durianbikers_seo_breadcrumb_schema' ) ) {
	function durianbikers_seo_breadcrumb_schema() {

		if ( ! durianbikers_seo_head_allowed() ) {
			return;
		}

		// The site root is the store selector: it has no trail, and it IS Home.
		if ( function_exists( 'is_front_page' ) && is_front_page() ) {
			return;
		}

		$home = durianbikers_seo_store_home_url();

		if ( '' === $home ) {
			return;
		}

		$rows = array(
			array(
				'name' => 'Home',
				'item' => $home,
			),
		);

		if ( is_singular( 'product' ) ) {

			$post = get_queried_object();

			if ( ! $post instanceof WP_Post ) {
				return;
			}

			$term = durianbikers_seo_breadcrumb_product_term( (int) $post->ID );

			if ( $term instanceof WP_Term ) {
				$rows = array_merge( $rows, durianbikers_seo_breadcrumb_term_trail( $term ) );
			}

			$title = durianbikers_seo_title();
			$url   = durianbikers_seo_store_url( durianbikers_seo_canonical_url() );

			if ( '' === trim( (string) $title ) || '' === $url ) {
				return;
			}

			$rows[] = array(
				'name' => $title,
				'item' => $url,
			);

		} elseif ( ( function_exists( 'is_tax' ) && is_tax() ) || is_category() || is_tag() ) {

			$term = get_queried_object();

			if ( ! $term instanceof WP_Term ) {
				return;
			}

			$rows = array_merge( $rows, durianbikers_seo_breadcrumb_term_trail( $term ) );

		} else {

			// Everything else (plain pages, the shop archive, posts) is left
			// alone: inventing a trail for them is guesswork.
			return;
		}

		// Home on its own is not a breadcrumb.
		if ( count( $rows ) < 2 ) {
			return;
		}

		$elements = array();
		$position = 1;

		foreach ( $rows as $row ) {

			$elements[] = array(
				'@type'    => 'ListItem',
				'position' => $position,
				'name'     => $row['name'],
				'item'     => $row['item'],
			);

			$position++;
		}

		$data = array(
			'@context'        => 'https://schema.org',
			'@type'           => 'BreadcrumbList',
			'itemListElement' => $elements,
		);

		$json = wp_json_encode( $data );

		if ( ! is_string( $json ) || '' === $json ) {
			return;
		}

		// wp_json_encode() escapes forward slashes, so no value can close the
		// script tag early.
		echo '<script type="application/ld+json">' . $json . '</script>' . "\n";
	}
}

// ===========================================================================
// 9. rel=canonical on product taxonomy archives ONLY
// CURCY self-canonicals every SINGULAR store-prefixed page, which is why the
// section above adds none - but it does NOT canonical a taxonomy archive, so
// /product-category/helmets/?orderby=price, ?min_price=100 and every other
// sort/filter combination is an indexable duplicate of the clean URL.
//
// Self-referencing PER PAGE on purpose: page 2 canonicals to page 2, not back
// to page 1 - page 2 lists different products and pointing it at page 1 asks
// Google to drop those products. Only the QUERY STRING is stripped, which is
// the whole point: it collapses the sort and filter variants onto the one
// clean paginated URL.
// ===========================================================================

// Which archives get one. Singular is excluded deliberately: two canonical
// tags is worse than one, and CURCY owns that tag on singular pages.
if ( ! function_exists( 'durianbikers_seo_canonical_taxonomies' ) ) {
	function durianbikers_seo_canonical_taxonomies() {

		return array( 'product_cat', 'product_tag', 'product_brand' );
	}
}

// Priority 6: after the breadcrumb, and well clear of core's rel_canonical()
// at 10 - which only ever fires on singular, where we emit nothing.
add_action( 'wp_head', 'durianbikers_seo_taxonomy_canonical', 6 );

if ( ! function_exists( 'durianbikers_seo_taxonomy_canonical' ) ) {
	function durianbikers_seo_taxonomy_canonical() {

		if ( ! durianbikers_seo_head_allowed() ) {
			return;
		}

		// Three independent guards against emitting a second canonical.
		if ( ! function_exists( 'is_tax' ) || ! function_exists( 'is_singular' ) ) {
			return;
		}

		if ( is_singular() ) {
			return;
		}

		if ( function_exists( 'is_front_page' ) && is_front_page() ) {
			return;
		}

		if ( ! is_tax( durianbikers_seo_canonical_taxonomies() ) ) {
			return;
		}

		// A term archive must actually have resolved to a term.
		$term = get_queried_object();

		if ( ! $term instanceof WP_Term ) {
			return;
		}

		$base = durianbikers_seo_site_base();

		if ( '' === $base ) {
			return;
		}

		// REQUEST_URI already carries both the store prefix and the /page/N/
		// segment, and durianbikers_seo_request_path() returns the PATH only -
		// so the query string is gone without having to enumerate parameters.
		$path = durianbikers_seo_request_path();

		if ( '' === ltrim( $path, '/' ) ) {
			return;
		}

		$url = $base . '/' . ltrim( $path, '/' );

		echo '<link rel="canonical" href="' . esc_url( $url ) . '" />' . "\n";
	}
}

// ===========================================================================
// 10. Native lazy-loading for the below-the-fold attachment images
// MEASURED on a product page: 29 <img>, 16 already lazy (4 native, the rest
// through LiteSpeed's data-src/data-lazyloaded JS loader) and 13 with no lazy
// mechanism at all - 8 related/upsell 400x400 thumbnails, 4 footer payment
// icons and the site logo. Only the logo should stay eager.
//
// Done on wp_get_attachment_image_attributes, which is the one place every
// attachment image rendered through wp_get_attachment_image() passes. A
// hardcoded <img> in a template is NOT reachable from here by design: this
// filter must not become an output-buffer rewriter on a store taking
// payments.
//
// The FIRST attachment image on the page is skipped with a static counter
// rather than deferring to core's wp_omit_loading_attr_threshold: that
// threshold is only consulted for images core itself considers to be in the
// main content loop, and several of the images here (logo, footer icons) are
// rendered outside it, so it would not reliably protect the LCP element.
// A plain counter is deterministic and has no dependency on core internals.
//
// Fail safe in every branch: anything unexpected is returned untouched.
// ===========================================================================

add_filter( 'wp_get_attachment_image_attributes', 'durianbikers_lazy_attachment_images', 20 );

if ( ! function_exists( 'durianbikers_lazy_attachment_images' ) ) {
	function durianbikers_lazy_attachment_images( $attr ) {

		if ( ! is_array( $attr ) ) {
			return $attr;
		}

		// Front end only. The admin has its own media handling, and a feed is
		// parsed by readers that do not run our lazy loader.
		if ( is_admin() ) {
			return $attr;
		}

		if ( function_exists( 'is_feed' ) && is_feed() ) {
			return $attr;
		}

		// Never override an explicit decision, including loading="eager".
		if ( isset( $attr['loading'] ) && '' !== trim( (string) $attr['loading'] ) ) {
			return $attr;
		}

		// LiteSpeed already lazy-loads this one in JS. Stacking native lazy on
		// top of a JS loader is redundant and the two can fight.
		foreach ( array( 'data-src', 'data-lazyloaded', 'data-srcset' ) as $litespeed_attr ) {
			if ( isset( $attr[ $litespeed_attr ] ) && '' !== trim( (string) $attr[ $litespeed_attr ] ) ) {
				return $attr;
			}
		}

		// fetchpriority="high" is a declaration that this image is wanted now.
		if ( isset( $attr['fetchpriority'] ) && 'high' === strtolower( trim( (string) $attr['fetchpriority'] ) ) ) {
			return $attr;
		}

		// The site logo is in the header on every page.
		if ( isset( $attr['class'] ) && is_string( $attr['class'] ) && false !== strpos( $attr['class'], 'custom-logo' ) ) {
			return $attr;
		}

		// First attachment image of this request: the likely LCP element.
		static $seen = 0;

		$seen++;

		if ( 1 === $seen ) {
			return $attr;
		}

		$attr['loading'] = 'lazy';

		return $attr;
	}
}
