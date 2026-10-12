<?php
/**
 * Plugin Name: Durian Bikers SEO
 * Description: Fixes the WordPress core sitemap to emit CURCY /my/ store-prefixed URLs instead of bare redirecting ones, removes the author ("users") sitemap, excludes the transactional (cart/checkout/my-account) and work-in-progress pages from the sitemap, and holds the GA4 tag.
 * Version:     1.1.2
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
add_action( 'wp_head', 'durianbikers_seo_build_marker' );

if ( ! function_exists( 'durianbikers_seo_build_marker' ) ) {
	function durianbikers_seo_build_marker() {
		echo "<!-- dbk-seo build 1.1.2 -->\n";
	}
}
