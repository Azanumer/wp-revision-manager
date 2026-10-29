<?php
/**
 * Plugin Name:       Revision Manager
 * Plugin URI:        https://github.com/Azanumer/wp-revision-manager
 * Description:       Per-post-type revision limits, a one-click old-revision cleanup, and a weekly prune — without touching wp-config.php.
 * Version:           1.0.0
 * Author:            Azan Umer
 * License:           MIT
 * Text Domain:       wrm
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'WRM_VERSION', '1.0.0' );

/** Default settings: 0 = keep WordPress default for that type. */
function wrm_defaults() {
    return array(
        'limits'      => array( 'post' => 10, 'page' => 10 ),
        'auto_prune'  => 1, // weekly cron keeps only the newest N per post
    );
}

function wrm_settings() {
    $saved = get_option( 'wrm_settings', array() );
    if ( ! is_array( $saved ) ) {
        $saved = array();
    }
    return wp_parse_args( $saved, wrm_defaults() );
}

/**
 * Enforce the limit at save time: when a new revision is created, delete
 * revisions older than the newest N for that post. Filterable per type.
 */
add_action( 'wp_insert_post', function ( $post_id, $post ) {
    if ( wp_is_post_revision( $post_id ) || 'revision' === $post->post_type ) {
        return;
    }
    $settings = wrm_settings();
    $type     = $post->post_type;
    $limit    = isset( $settings['limits'][ $type ] ) ? (int) $settings['limits'][ $type ] : 0;
    $limit    = (int) apply_filters( 'wrm_revision_limit', $limit, $type );
    if ( $limit <= 0 ) {
        return;
    }
    wrm_prune_post( $post->ID, $limit );
}, 10, 2 );

/**
 * Delete revisions of $post_id beyond the newest $limit, oldest first.
 * Returns the number deleted. Autosaves are never touched.
 */
function wrm_prune_post( $post_id, $limit ) {
    $revisions = wp_get_post_revisions( $post_id, array( 'order' => 'DESC' ) );
    // wp_get_post_revisions returns newest first; skip autosaves in the count.
    $kept    = 0;
    $deleted = 0;
    foreach ( $revisions as $revision ) {
        if ( false !== strpos( $revision->post_name, 'autosave' ) ) {
            continue;
        }
        $kept++;
        if ( $kept > $limit ) {
            wp_delete_post_revision( $revision->ID );
            $deleted++;
        }
    }
    return $deleted;
}

/** Count all revisions site-wide (for the Tools page). */
function wrm_revision_count() {
    global $wpdb;
    return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'revision'" );
}

/** Weekly cron: prune every post to its type limit. */
add_action( 'wrm_weekly_prune', function () {
    $settings = wrm_settings();
    if ( empty( $settings['auto_prune'] ) ) {
        return;
    }
    global $wpdb;
    $post_ids = $wpdb->get_col( "SELECT DISTINCT post_parent FROM {$wpdb->posts} WHERE post_type = 'revision' AND post_parent > 0" );
    foreach ( $post_ids as $post_id ) {
        $type  = get_post_type( $post_id );
        $limit = isset( $settings['limits'][ $type ] ) ? (int) $settings['limits'][ $type ] : 0;
        if ( $limit > 0 ) {
            wrm_prune_post( (int) $post_id, $limit );
        }
    }
} );

register_activation_hook( __FILE__, function () {
    if ( ! wp_next_scheduled( 'wrm_weekly_prune' ) ) {
        wp_schedule_event( time(), 'weekly', 'wrm_weekly_prune' );
    }
} );

register_deactivation_hook( __FILE__, function () {
    wp_clear_scheduled_hook( 'wrm_weekly_prune' );
} );

/** Tools → Revision Manager: stats + one-click prune + per-type limits. */
add_action( 'admin_menu', function () {
    add_management_page( 'Revision Manager', 'Revision Manager', 'manage_options', 'wrm-tools', 'wrm_tools_page' );
} );

function wrm_tools_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'You do not have permission to access this page.' ) );
    }
    $message = '';
    if ( isset( $_POST['wrm_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wrm_nonce'] ) ), 'wrm_save' ) ) {
        if ( isset( $_POST['wrm_prune_now'] ) ) {
            // One-click cleanup: delete every revision older than the newest N per post.
            global $wpdb;
            $settings = wrm_settings();
            $total    = 0;
            $post_ids = $wpdb->get_col( "SELECT DISTINCT post_parent FROM {$wpdb->posts} WHERE post_type = 'revision' AND post_parent > 0" );
            foreach ( $post_ids as $post_id ) {
                $type  = get_post_type( $post_id );
                $limit = isset( $settings['limits'][ $type ] ) ? (int) $settings['limits'][ $type ] : 10;
                $total += wrm_prune_post( (int) $post_id, max( 0, $limit ) );
            }
            $message = sprintf( '%d old revisions deleted.', $total );
        } else {
            $limits = array();
            $raw    = isset( $_POST['wrm_limits'] ) && is_array( $_POST['wrm_limits'] ) ? wp_unslash( $_POST['wrm_limits'] ) : array();
            foreach ( $raw as $type => $value ) {
                $limits[ sanitize_key( $type ) ] = max( 0, (int) $value );
            }
            $settings               = wrm_settings();
            $settings['limits']     = $limits;
            $settings['auto_prune'] = isset( $_POST['wrm_auto_prune'] ) ? 1 : 0;
            update_option( 'wrm_settings', $settings );
            $message = 'Settings saved.';
        }
    }
    $settings = wrm_settings();
    $types    = get_post_types( array( 'public' => true ), 'objects' );
    ?>
    <div class="wrap"><h1>Revision Manager</h1>
    <?php if ( $message ) : ?><div class="updated"><p><?php echo esc_html( $message ); ?></p></div><?php endif; ?>
    <p><strong><?php echo esc_html( number_format_i18n( wrm_revision_count() ) ); ?></strong> revisions stored site-wide.</p>
    <form method="post">
        <?php wp_nonce_field( 'wrm_save', 'wrm_nonce' ); ?>
        <table class="form-table">
            <?php foreach ( $types as $type ) : ?>
                <tr><th><?php echo esc_html( $type->labels->singular_name ); ?> limit</th>
                <td><input type="number" min="0" name="wrm_limits[<?php echo esc_attr( $type->name ); ?>]" value="<?php echo esc_attr( $settings['limits'][ $type->name ] ?? 10 ); ?>"> revisions kept (0 = WordPress default)</td></tr>
            <?php endforeach; ?>
            <tr><th>Weekly prune</th><td><label><input type="checkbox" name="wrm_auto_prune" value="1" <?php checked( $settings['auto_prune'] ); ?>> Automatically prune to the limits once a week</label></td></tr>
        </table>
        <?php submit_button( 'Save limits' ); ?>
        <p><button type="submit" name="wrm_prune_now" value="1" class="button button-secondary" onclick="return confirm('Delete all revisions beyond the limits? This cannot be undone.');">Prune old revisions now</button></p>
    </form></div>
    <?php
}
