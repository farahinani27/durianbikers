<?php
/**
 * Document close for the Hero Banner (3D) template.
 *
 * @package MotorMania Child
 */

defined( 'ABSPATH' ) || exit;

$db_hero_dir = get_stylesheet_directory();
$db_hero_js  = '/assets/js/hero-banner.js';
$db_hero_ver = file_exists( $db_hero_dir . $db_hero_js ) ? (string) filemtime( $db_hero_dir . $db_hero_js ) : '1.0.0';
?>
<script type="module" src="<?php echo esc_url( get_stylesheet_directory_uri() . $db_hero_js . '?ver=' . $db_hero_ver ); ?>"></script>

<?php wp_footer(); ?>
</body>
</html>
