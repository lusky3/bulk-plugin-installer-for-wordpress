<?php
/**
 * Uninstall handler for Bulk Plugin Installer.
 *
 * Fired when the plugin is deleted via the WordPress admin.
 * Checks the bpi_delete_data_on_uninstall option to decide
 * whether to remove all plugin data or preserve it.
 *
 * @package BulkPluginInstaller
 */

// Abort if not called by WordPress.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

// If the user chose to keep data, do nothing.
if ( ! get_option( 'bpi_delete_data_on_uninstall', false ) ) {
    return;
}

// Remove all plugin options.
$bpi_options = array(
    'bpi_auto_activate',
    'bpi_max_plugins',
    'bpi_auto_rollback',
    'bpi_max_file_size',
    'bpi_rollback_retention',
    'bpi_email_notifications',
    'bpi_email_recipients',
    'bpi_delete_data_on_uninstall',
    'bpi_profiles',
    'bpi_active_batches',
);

foreach ( $bpi_options as $option ) {
    delete_option( $option );
}

// Drop the log table.
global $wpdb;
$table_name = $wpdb->prefix . 'bpi_log';
$wpdb->query( "DROP TABLE IF EXISTS {$table_name}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

// Delete all BPI transients.
$wpdb->query( $wpdb->prepare(
    "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
    $wpdb->esc_like( '_transient_bpi_queue_' ) . '%',
    $wpdb->esc_like( '_transient_timeout_bpi_queue_' ) . '%',
    $wpdb->esc_like( '_transient_bpi_batch_' ) . '%',
    $wpdb->esc_like( '_transient_timeout_bpi_batch_' ) . '%',
    $wpdb->esc_like( '_transient_bpi_admin_notice' ) . '%',
    $wpdb->esc_like( '_transient_timeout_bpi_admin_notice' ) . '%'
) );

// Remove backup files.
$backup_dir = WP_CONTENT_DIR . '/bpi-backups';
if ( is_dir( $backup_dir ) ) {
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator( $backup_dir, RecursiveDirectoryIterator::SKIP_DOTS ),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    $items->setMaxDepth( 50 );

    foreach ( $items as $item ) {
        if ( $item->isDir() ) {
            rmdir( $item->getRealPath() );
        } else {
            unlink( $item->getRealPath() );
        }
    }

    rmdir( $backup_dir );
}

// Remove temporary upload files.
$upload_dir = wp_upload_dir();
$tmp_dir    = trailingslashit( $upload_dir['basedir'] ) . 'bpi-tmp';
if ( is_dir( $tmp_dir ) ) {
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator( $tmp_dir, RecursiveDirectoryIterator::SKIP_DOTS ),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    $items->setMaxDepth( 50 );

    foreach ( $items as $item ) {
        if ( $item->isDir() ) {
            rmdir( $item->getRealPath() );
        } else {
            unlink( $item->getRealPath() );
        }
    }

    rmdir( $tmp_dir );
}
