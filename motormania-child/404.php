<?php
/**
 * The template for displaying 404 pages (not found)
 *
 * @link https://codex.wordpress.org/Creating_an_Error_404_Page
 *
 * @package motormania 
 */
global $motormania_opt;

$motormania_error_title = !empty( $motormania_opt['motormania_error_title'] ) ? $motormania_opt['motormania_error_title'] : __( 'You&rsquo;ve Ridden Off the Map.', 'motormania' );
$motormania_error_text = !empty( $motormania_opt['motormania_error_text'] ) ? $motormania_opt['motormania_error_text'] : __( 'No signs, no signal, no page here. Let&rsquo;s get you back on the road.', 'motormania' );

get_header(); ?>

<div class="container error-404-page">
	<div class="row">
		<div class="col-sm-12">
			<div class="error-404">
				<h1><?php echo esc_html( $motormania_error_title ); ?></h1>
				<p><?php echo esc_html( $motormania_error_text ); ?></p>
				<?php
				$motormania_btn_text  = __( 'Ride Back Home', 'motormania' );
				$motormania_btn_chars = preg_split( '//u', $motormania_btn_text, -1, PREG_SPLIT_NO_EMPTY );
				?>
				<a href="<?php echo esc_url( get_home_url() ); ?>" class="db-btn db-btn--accent db-btn--lg" aria-label="<?php echo esc_attr( $motormania_btn_text ); ?>">
					<span class="db-btn__swap" aria-hidden="true">
						<span class="db-btn__line"><?php
						foreach ( $motormania_btn_chars as $i => $ch ) {
							printf( '<span class="db-btn__ch" style="--i:%d">%s</span>', (int) $i, ' ' === $ch ? '&nbsp;' : esc_html( $ch ) );
						}
						?></span>
						<span class="db-btn__line db-btn__line--in"><?php
						foreach ( $motormania_btn_chars as $i => $ch ) {
							printf( '<span class="db-btn__ch" style="--i:%d">%s</span>', (int) $i, ' ' === $ch ? '&nbsp;' : esc_html( $ch ) );
						}
						?></span>
					</span>
				</a>
			</div>
		</div>
	</div>
</div>

<?php get_footer();