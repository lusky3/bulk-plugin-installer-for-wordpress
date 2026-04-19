<?php
/**
 * Log Manager for Bulk Plugin Installer.
 *
 * Manages the activity log in a custom database table, providing
 * CRUD operations for recording and retrieving bulk operation history.
 *
 * @package BulkPluginInstaller
 */

// Abort if this file is called directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Handles activity logging for bulk plugin operations.
 *
 * Stores log entries in a dedicated database table ({prefix}_bpi_log)
 * for efficient querying and pagination. Each entry records the action
 * type, plugin details, outcome, and whether it was a dry run.
 *
 * @since 1.0.0
 */
class BPILogManager {

    /**
     * Security verification failure message.
     */
    private const MSG_SECURITY_FAILED = 'Security verification failed. Please refresh the page and try again.';

    /**
     * Get the full table name including the WordPress prefix.
     *
     * @return string Table name.
     */
    private function getTableName(): string {
        global $wpdb;
        return $wpdb->prefix . 'bpi_log';
    }

    /**
     * Create the log table on plugin activation.
     *
     * Uses dbDelta() for safe table creation and schema updates.
     *
     * @since 1.0.0
     */
    public function createTable(): void {
        global $wpdb;

        $table_name      = $this->getTableName();
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table_name} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            timestamp DATETIME NOT NULL,
            user_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            action VARCHAR(50) NOT NULL DEFAULT '',
            batch_id VARCHAR(100) NOT NULL DEFAULT '',
            plugin_slug VARCHAR(200) NOT NULL DEFAULT '',
            plugin_name VARCHAR(200) NOT NULL DEFAULT '',
            from_version VARCHAR(50) NOT NULL DEFAULT '',
            to_version VARCHAR(50) NOT NULL DEFAULT '',
            status VARCHAR(20) NOT NULL DEFAULT '',
            message TEXT NOT NULL,
            is_dry_run TINYINT(1) NOT NULL DEFAULT 0,
            PRIMARY KEY  (id),
            KEY idx_timestamp (timestamp),
            KEY idx_batch_id (batch_id),
            KEY idx_plugin_slug (plugin_slug)
        ) {$charset_collate};";

        if ( ! function_exists( 'dbDelta' ) ) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php'; // NOSONAR - WordPress bootstrap requires file inclusion
        }

        dbDelta( $sql );
    }

    /**
     * Drop the log table on plugin uninstall.
     *
     * @since 1.0.0
     */
    public function dropTable(): void {
        global $wpdb;

        $table_name = $this->getTableName();
        $wpdb->query( "DROP TABLE IF EXISTS {$table_name}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    }

    /**
     * Write a log entry.
     *
     * @since 1.0.0
     *
     * @param string $action  The action type (install, update, rollback, batch_rollback, dry_run).
     * @param array  $details {
     *     Log entry details.
     *
     *     @type string $batch_id     Batch identifier.
     *     @type string $plugin_slug  Plugin slug.
     *     @type string $plugin_name  Plugin display name.
     *     @type string $from_version Previous version (for updates).
     *     @type string $to_version   New version.
     *     @type string $status       Outcome: success, failed, rolled_back.
     *     @type string $message      Error message or details.
     *     @type bool   $is_dry_run   Whether this was a dry run.
     * }
     */
    public function log( string $action, array $details ): void {
        global $wpdb;

        $table_name = $this->getTableName();

        $wpdb->insert(
            $table_name,
            array(
                'timestamp'    => current_time( 'mysql' ),
                'user_id'      => get_current_user_id(),
                'action'       => sanitize_text_field( $action ),
                'batch_id'     => sanitize_text_field( $details['batch_id'] ?? '' ),
                'plugin_slug'  => sanitize_text_field( $details['plugin_slug'] ?? '' ),
                'plugin_name'  => sanitize_text_field( $details['plugin_name'] ?? '' ),
                'from_version' => sanitize_text_field( $details['from_version'] ?? '' ),
                'to_version'   => sanitize_text_field( $details['to_version'] ?? '' ),
                'status'       => sanitize_text_field( $details['status'] ?? '' ),
                'message'      => sanitize_textarea_field( $details['message'] ?? '' ),
                'is_dry_run'   => ! empty( $details['is_dry_run'] ) ? 1 : 0,
            ),
            array( '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d' )
        );
    }

    /**
     * Get log entries with pagination.
     *
     * @since 1.0.0
     *
     * @param int $limit  Maximum number of entries to return. Default 50.
     * @param int $offset Number of entries to skip. Default 0.
     * @return array List of log entry objects ordered by timestamp descending.
     */
    public function getEntries( int $limit = 50, int $offset = 0 ): array {
        global $wpdb;

        $table_name = $this->getTableName();
        $limit      = absint( $limit );
        $offset     = absint( $offset );

        $query = $wpdb->prepare(
            "SELECT * FROM {$table_name} ORDER BY timestamp DESC LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $limit,
            $offset
        );

        $results = $wpdb->get_results( $query );

        return is_array( $results ) ? $results : array();
    }

    /**
     * Clear all log entries.
     *
     * @since 1.0.0
     */
    public function clear(): void {
        global $wpdb;

        $table_name = $this->getTableName();
        $wpdb->query( "TRUNCATE TABLE {$table_name}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    }

    /**
     * AJAX handler for wp_ajax_bpi_get_log.
     *
     * Returns log entries as JSON. Verifies nonce and capability.
     *
     * @since 1.0.0
     */
    public function handleGetLog(): void {
        if ( ! isset( $_GET['_wpnonce'] ) && ! isset( $_POST['_wpnonce'] ) ) {
            wp_send_json_error(
                array( 'message' => __( self::MSG_SECURITY_FAILED, 'bulk-plugin-installer' ) ),
                403
            );
            return;
        }

        $nonce = isset( $_GET['_wpnonce'] ) ? wp_unslash( $_GET['_wpnonce'] ) : wp_unslash( $_POST['_wpnonce'] );
        if ( ! wp_verify_nonce( $nonce, 'bpi_get_log' ) ) {
            wp_send_json_error(
                array( 'message' => __( self::MSG_SECURITY_FAILED, 'bulk-plugin-installer' ) ),
                403
            );
            return;
        }

        if ( ! current_user_can( 'install_plugins' ) ) {
            wp_send_json_error(
                array( 'message' => __( 'You do not have permission to view the log.', 'bulk-plugin-installer' ) ),
                403
            );
            return;
        }

        $limit  = isset( $_GET['limit'] ) ? absint( $_GET['limit'] ) : 50;
        $offset = isset( $_GET['offset'] ) ? absint( $_GET['offset'] ) : 0;

        $entries = $this->getEntries( $limit, $offset );

        wp_send_json_success( array( 'entries' => $entries ) );
    }

    /**
     * AJAX handler for wp_ajax_bpi_clear_log.
     *
     * Clears all log entries. Verifies nonce and capability.
     *
     * @since 1.0.0
     */
    public function handleClearLog(): void {
        if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( wp_unslash( $_POST['_wpnonce'] ), 'bpi_clear_log' ) ) {
            wp_send_json_error(
                array( 'message' => __( self::MSG_SECURITY_FAILED, 'bulk-plugin-installer' ) ),
                403
            );
            return;
        }

        if ( ! current_user_can( 'install_plugins' ) ) {
            wp_send_json_error(
                array( 'message' => __( 'You do not have permission to clear the log.', 'bulk-plugin-installer' ) ),
                403
            );
            return;
        }

        $this->clear();

        wp_send_json_success(
            array( 'message' => __( 'Activity log cleared.', 'bulk-plugin-installer' ) )
        );
    }
}
