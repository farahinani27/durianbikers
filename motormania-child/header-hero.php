<?php
/**
 * Document shell for the Hero Banner (3D) template.
 *
 * Deliberately self-contained: every asset this page needs is printed here
 * rather than registered in functions.php, so dropping the template into the
 * theme adds nothing to the global asset pipeline and cannot affect any other
 * page. Delete the template files and the site is exactly as it was.
 *
 * The theme's own header.php is bypassed because it prints the site nav and a
 * breadcrumb block, both of which would sit on top of a full-bleed hero.
 * wp_head()/wp_body_open() still fire so plugins behave normally.
 *
 * @package MotorMania Child
 */

defined( 'ABSPATH' ) || exit;

$db_hero_dir = get_stylesheet_directory();
$db_hero_uri = get_stylesheet_directory_uri();

/** Cache-bust on file mtime so a re-upload is picked up immediately. */
$db_hero_ver = static function ( $rel ) use ( $db_hero_dir ) {
	$path = $db_hero_dir . $rel;
	return file_exists( $path ) ? (string) filemtime( $path ) : '1.0.0';
};

/**
 * three.js CDN base. Pinned to an exact version - a floating "latest" would
 * let an upstream release break this page unattended.
 *
 * TODO: self-host alongside GSAP when the CDN dependencies are pulled in-house
 * (see "Known issues" in CLAUDE.md).
 */
$db_hero_three = 'https://cdn.jsdelivr.net/npm/three@0.185.1';
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">

	<link rel="preconnect" href="https://cdn.jsdelivr.net" crossorigin>
	<link rel="preconnect" href="https://fonts.googleapis.com">
	<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
	<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Archivo:wght@400;500;700;800;900&amp;family=Instrument+Serif:ital@0;1&amp;display=swap">

	<link rel="stylesheet" href="<?php echo esc_url( $db_hero_uri . '/assets/css/hero-banner.css?ver=' . $db_hero_ver( '/assets/css/hero-banner.css' ) ); ?>">

	<script type="importmap">
	{
		"imports": {
			"three": "<?php echo esc_url( $db_hero_three . '/build/three.module.js' ); ?>",
			"three/addons/": "<?php echo esc_url( $db_hero_three . '/examples/jsm/' ); ?>"
		}
	}
	</script>

	<?php wp_head(); ?>
</head>

<body <?php body_class( 'db-hero-page' ); ?>>
<?php wp_body_open(); ?>
