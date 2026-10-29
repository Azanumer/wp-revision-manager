<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}
delete_option( 'wrm_settings' );
wp_clear_scheduled_hook( 'wrm_weekly_prune' );
