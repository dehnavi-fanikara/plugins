<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Show Users Profile Avatar
 * [FNK_USERS_PROFILE_AVATAR]
 */
function fnk_users_profile_avatar() {
    // Build image URL
    $user_image_address = jet_engine()->listings->data->get_option( 'fanikara-options::fu-base-profile-address' );
    $user_image_id      = get_post_meta( get_the_ID(), 'fnk_usr_cpt_info_profile_img_url', true );

    // Fallback image — strictly from your option; no hardcoded default
    $user_image_fallback = jet_engine()->listings->data->get_option( 'fanikara-options::fu-base-profile-fallback' );

    // Final image URL
    $user_image_url = $user_image_address . $user_image_id;
    $user_name      = get_the_title( get_the_ID() );
    $user_link = get_permalink();

    ob_start();
    ?>
    <a href="<?php echo esc_url( $user_link ); ?>" class="fnk-users-profile-avatar-link">
        <img
                src="<?php echo esc_url( $user_image_url ); ?>"
                <?php if ( ! empty( $user_image_fallback ) ) : ?>
                    onerror="this.onerror=null; this.src='<?php echo esc_url( $user_image_fallback ); ?>';"
                <?php endif; ?>
                class="fnk-users-profile-avatar"
                alt="<?php echo esc_attr( $user_name ); ?>"
        >
    </a>
    <?php
    return ob_get_clean();
}
add_shortcode( 'FNK_USERS_PROFILE_AVATAR', 'fnk_users_profile_avatar' );

/**
* Show custom terms from custom taxonomy in users card.
* CPT => Fanikara
* Taxonomy => fnk-features
* [FNK_USERS_FEATURE_BADGES]
*/
function fnk_users_features_badges() {
$post_id = get_the_ID();

if ( get_post_type( $post_id ) !== 'fanikar' ) {
    return ''; // or return a message
}

$terms = wp_get_post_terms( $post_id, 'fnk-features' );
if ( empty( $terms ) || is_wp_error( $terms ) ) {
    return '';
}

ob_start();
echo '<ul class="fnk-users-features-badges">';
foreach ( $terms as $term ) {
    $bg_color = get_term_meta( $term->term_id, 'fnk_features_pills_bg', true );
    //$bg_color = sanitize_hex_color( $bg_color ) ?: '#f0f0f0'; // fallback color

    echo '<li style="' . 'background-color: ' . esc_attr( $bg_color ) . '; ' . '">';
    //echo '<li>';
    echo esc_html($term->name);
    echo '</li>';
}
echo '</ul>';

return ob_get_clean();
}
add_shortcode( 'FNK_USERS_FEATURE_BADGES', 'fnk_users_features_badges' );


/**
 * User Single Page
 * [FNK_USERS_ACTIVITY_IN_MONTH]
 */
function fnk_users_activity_in_month_shortcode() {
    $post_id = get_the_ID();
    if ( ! $post_id ) {
        return '—';
    }

    $stored_date_string = get_post_meta( $post_id, 'fnk_usr_reg_date', true );

    // Check if value exists and matches YYYY-MM-DD format
    if ( ! $stored_date_string || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $stored_date_string ) ) {
        return '—';
    }

    // Create DateTime object from Y-m-d string
    $stored_date = DateTime::createFromFormat( 'Y-m-d', $stored_date_string );
    if ( ! $stored_date ) {
        return '—';
    }

    $now = new DateTime();
    $now->setTime( 0, 0, 0 ); // Normalize time to avoid day issues

    $current_year  = (int) $now->format('Y');
    $current_month = (int) $now->format('m');
    $current_day   = (int) $now->format('d');

    $stored_year   = (int) $stored_date->format('Y');
    $stored_month  = (int) $stored_date->format('m');
    $stored_day    = (int) $stored_date->format('d');

    $total_months = ( $current_year - $stored_year ) * 12 + ( $current_month - $stored_month );

    // If current day is less than stored day, one full month hasn't passed
    if ( $current_day < $stored_day ) {
        $total_months--;
    }

    // Avoid negative results (e.g., future dates)
    if ( $total_months < 0 ) {
        $total_months = 0;
    }

    return (string) $total_months;
}
add_shortcode( 'FNK_USERS_ACTIVITY_IN_MONTH', 'fnk_users_activity_in_month_shortcode' );