<?php
/**
 * Batch Rollback Manager for Bulk Plugin Installer.
 *
 * Manages batch-level rollback with manifest tracking. Stores batch
 * manifests in WordPress transients and coordinates rollback of entire
 * batches using the per-plugin Rollback_Manager.
 *
 * @package BulkPluginInstaller
 */

// Abort if this file is called directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Manages batch-level rollback operations.
 *
 * Records batch manifests after processing, supports rolling back
 * entire batches (restoring updated plugins and removing new installs),
 * tracks active batches, and cleans up expired ones.
 *
 * @since 1.0.0
 */
class BPIBatchRollbackManager {

    /**
     * Transient key prefix for batch manifests.
     *
     * @var string
     */
    private const BATCH_TRANSIENT_PREFIX = 'bpi_batch_';

    /**
     * Option key for tracking active batch IDs.
     *
     * @var string
     */
    private const ACTIVE_BATCHES_KEY = 'bpi_active_batches';

    /**
     * Success message for batch rollback completion.
     */
    private const MSG_ROLLBACK_SUCCESS = 'Batch rollback completed successfully.';

    /**
     * Per-plugin rollback manager.
     *
     * @var BPIRollbackManager
     */
    private BPIRollbackManager $rollback;

    /**
     * Settings manager.
     *
     * @var BPISettingsManager
     */
    private BPISettingsManager $settings;

    /**
     * Log manager.
     *
     * @var BPILogManager
     */
    private BPILogManager $logger;

    /**
     * Notification manager instance (optional, set via setter).
     *
     * @var BPINotificationManager|null
     */
    private ?BPINotificationManager $notificationManager = null;

    /**
     * Constructor.
     *
     * @param BPIRollbackManager $rollback Per-plugin rollback manager.
     * @param BPISettingsManager $settings Settings manager.
     * @param BPILogManager      $logger   Log manager.
     */
    public function __construct(
        BPIRollbackManager $rollback,
        BPISettingsManager $settings,
        BPILogManager $logger
    ) {
        $this->rollback = $rollback;
        $this->settings = $settings;
        $this->logger   = $logger;
    }

    /**
     * Set the notification manager for post-rollback notifications.
     *
     * @since 1.0.0
     *
     * @param BPINotificationManager $notificationManager Notification manager instance.
     */
    public function setNotificationManager( BPINotificationManager $notificationManager ): void {
        $this->notificationManager = $notificationManager;
    }

    /**
     * Record a batch manifest after processing.
     *
     * Stores the manifest in a transient with expiration based on
     * the `bpi_rollback_retention` setting, and tracks the batch ID
     * in the active batches list.
     *
     * @since 1.0.0
     *
     * @param string $batch_id Unique batch identifier.
     * @param array  $manifest Batch manifest data.
     */
    public function recordBatch( string $batch_id, array $manifest ): void {
        $retention_hours = (int) $this->settings->getOption( 'bpi_rollback_retention' );
        if ( $retention_hours < 1 ) {
            $retention_hours = 24;
        }
        $expiration = $retention_hours * 3600;

        // Ensure the manifest has required metadata.
        $manifest['batch_id']   = $batch_id;
        $manifest['expires_at'] = gmdate( 'Y-m-d\TH:i:s\Z', time() + $expiration );

        if ( ! isset( $manifest['timestamp'] ) ) {
            $manifest['timestamp'] = gmdate( 'Y-m-d\TH:i:s\Z' );
        }

        if ( ! isset( $manifest['user_id'] ) ) {
            $manifest['user_id'] = get_current_user_id();
        }

        set_transient( self::BATCH_TRANSIENT_PREFIX . $batch_id, $manifest, $expiration );

        // Track this batch ID in the active batches list.
        $active = $this->getActiveBatchIds();
        if ( ! in_array( $batch_id, $active, true ) ) {
            $active[] = $batch_id;
        }
        update_option( self::ACTIVE_BATCHES_KEY, $active, false );
    }

    /**
     * Rollback an entire batch.
     *
     * Iterates plugins in the manifest: restores updated plugins from
     * backup and removes newly installed plugins. Continues on individual
     * failures and collects results.
     *
     * @since 1.0.0
     *
     * @param string $batch_id Batch identifier to rollback.
     * @return array Rollback results with 'success', 'failures', and 'results' keys.
     */
    public function rollbackBatch( string $batch_id ): array {
        $manifest = $this->getBatchManifest( $batch_id );

        if ( empty( $manifest ) ) {
            return array(
                'success'  => false,
                'failures' => array( __( 'Batch manifest not found.', 'bulk-plugin-installer' ) ),
                'results'  => array(),
            );
        }

        $plugins  = $manifest['plugins'] ?? array();
        $results  = array();
        $failures = array();

        do_action( 'bpi_before_batch_rollback', $batch_id, $manifest );

        foreach ( $plugins as $plugin ) {
            $this->rollbackSinglePlugin( $plugin, $results, $failures );
        }

        do_action( 'bpi_after_batch_rollback', $batch_id, $results, $failures );

        // Log the batch rollback operation.
        $this->logger->log(
            'batch_rollback',
            array(
                'batch_id'    => $batch_id,
                'plugin_slug' => '',
                'plugin_name' => '',
                'status'      => empty( $failures ) ? 'success' : 'partial',
                'message'     => empty( $failures )
                    ? __( self::MSG_ROLLBACK_SUCCESS, 'bulk-plugin-installer' )
                    : sprintf(
                        /* translators: %s: list of failures */
                        __( 'Batch rollback completed with errors: %s', 'bulk-plugin-installer' ),
                        implode( '; ', $failures )
                    ),
            )
        );

        // Clean up the batch transient after rollback.
        delete_transient( self::BATCH_TRANSIENT_PREFIX . $batch_id );
        $this->removeBatchId( $batch_id );

        return array(
            'success'  => empty( $failures ),
            'failures' => $failures,
            'results'  => $results,
        );
    }

    /**
     * Rollback a single plugin within a batch.
     *
     * @param array $plugin   Plugin data from the batch manifest.
     * @param array &$results Results array (modified by reference).
     * @param array &$failures Failures array (modified by reference).
     */
    private function rollbackSinglePlugin( array $plugin, array &$results, array &$failures ): void {
        $slug   = $plugin['slug'] ?? '';
        $action = $plugin['action'] ?? '';
        $status = $plugin['status'] ?? '';

        // Skip plugins that already failed during processing.
        if ( 'failed' === $status ) {
            $results[] = array(
                'slug'   => $slug,
                'action' => 'skipped',
                'status' => 'skipped',
                'message' => __( 'Plugin was not successfully processed; skipping rollback.', 'bulk-plugin-installer' ),
            );
            return;
        }

        $plugin_dir = $this->getPluginDir( $slug );

        if ( 'update' === $action ) {
            $this->rollbackUpdatedPlugin( $plugin, $slug, $plugin_dir, $results, $failures );
        } elseif ( 'install' === $action ) {
            $this->rollback->removePartialInstall( $plugin_dir );
            $results[] = array(
                'slug'    => $slug,
                'action'  => 'remove',
                'status'  => 'success',
                'message' => sprintf(
                    /* translators: %s: plugin slug */
                    __( 'Removed newly installed "%s".', 'bulk-plugin-installer' ),
                    $slug
                ),
            );
        }
    }

    /**
     * Rollback an updated plugin by restoring from backup.
     *
     * @param array  $plugin     Plugin data from the batch manifest.
     * @param string $slug       Plugin slug.
     * @param string $plugin_dir Plugin directory path.
     * @param array  &$results   Results array (modified by reference).
     * @param array  &$failures  Failures array (modified by reference).
     */
    private function rollbackUpdatedPlugin( array $plugin, string $slug, string $plugin_dir, array &$results, array &$failures ): void {
        $backup_path = $plugin['backup_path'] ?? '';
        if ( empty( $backup_path ) ) {
            $failures[] = sprintf(
                /* translators: %s: plugin slug */
                __( 'No backup path for "%s".', 'bulk-plugin-installer' ),
                $slug
            );
            $results[]  = array(
                'slug'    => $slug,
                'action'  => 'restore',
                'status'  => 'failed',
                'message' => __( 'No backup path available.', 'bulk-plugin-installer' ),
            );
            return;
        }

        $restore_result = $this->rollback->restoreBackup( $backup_path, $plugin_dir );

        if ( is_wp_error( $restore_result ) ) {
            $failures[] = sprintf(
                /* translators: 1: plugin slug, 2: error message */
                __( 'Failed to restore "%1$s": %2$s', 'bulk-plugin-installer' ),
                $slug,
                $restore_result->get_error_message()
            );
            $results[]  = array(
                'slug'    => $slug,
                'action'  => 'restore',
                'status'  => 'failed',
                'message' => $restore_result->get_error_message(),
            );
        } else {
            $results[] = array(
                'slug'    => $slug,
                'action'  => 'restore',
                'status'  => 'success',
                'message' => sprintf(
                    /* translators: %s: plugin slug */
                    __( 'Restored "%s" to previous version.', 'bulk-plugin-installer' ),
                    $slug
                ),
            );
        }
    }

    /**
     * Get all active batches within the retention period.
     *
     * @since 1.0.0
     *
     * @return array Array of batch manifests that are still valid.
     */
    public function getActiveBatches(): array {
        $batch_ids = $this->getActiveBatchIds();
        $batches   = array();

        foreach ( $batch_ids as $batch_id ) {
            $manifest = $this->getBatchManifest( $batch_id );
            if ( ! empty( $manifest ) ) {
                $batches[] = $manifest;
            }
        }

        return $batches;
    }

    /**
     * Clean up expired batch backups and transients.
     *
     * Checks active batch IDs and removes any whose transient has expired.
     * Also cleans up backup directories for expired batches.
     *
     * @since 1.0.0
     */
    public function cleanupExpired(): void {
        $batch_ids     = $this->getActiveBatchIds();
        $still_active  = array();

        foreach ( $batch_ids as $batch_id ) {
            $manifest = get_transient( self::BATCH_TRANSIENT_PREFIX . $batch_id );

            if ( false === $manifest ) {
                // Transient expired — clean up any remaining backup directories.
                // We can't know the backup paths without the manifest, so just
                // remove the batch ID from tracking.
                continue;
            }

            // Check if the batch has explicitly expired.
            if ( isset( $manifest['expires_at'] ) ) {
                $expires = strtotime( $manifest['expires_at'] );
                if ( false !== $expires && time() > $expires ) {
                    // Expired — clean up backups.
                    $this->cleanupBatchBackups( $manifest );
                    delete_transient( self::BATCH_TRANSIENT_PREFIX . $batch_id );
                    continue;
                }
            }

            $still_active[] = $batch_id;
        }

        update_option( self::ACTIVE_BATCHES_KEY, $still_active, false );
    }

    /**
     * Get the manifest for a specific batch.
     *
     * @since 1.0.0
     *
     * @param string $batch_id Batch identifier.
     * @return array Batch manifest or empty array if not found.
     */
    public function getBatchManifest( string $batch_id ): array {
        $manifest = get_transient( self::BATCH_TRANSIENT_PREFIX . $batch_id );

        if ( false === $manifest || ! is_array( $manifest ) ) {
            return array();
        }

        return $manifest;
    }

    /**
     * Register the AJAX handler for batch rollback.
     *
     * @since 1.0.0
     */
    public function registerAjaxHandler(): void {
        add_action( 'wp_ajax_bpi_batch_rollback', array( $this, 'handleAjaxRollback' ) );
    }

    /**
     * AJAX handler for wp_ajax_bpi_batch_rollback.
     *
     * Verifies nonce and capability, then performs the batch rollback.
     *
     * @since 1.0.0
     *
     * @SuppressWarnings(PHPMD.NPathComplexity)
     */
    public function handleAjaxRollback(): void {
        if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( wp_unslash( $_POST['_wpnonce'] ), 'bpi_batch_rollback' ) ) {
            wp_send_json_error(
                array( 'message' => __( 'Security verification failed. Please refresh the page and try again.', 'bulk-plugin-installer' ) ),
                403
            );
            return;
        }

        $required_cap = 'install_plugins';
        if ( function_exists( 'is_multisite' ) && is_multisite()
            && function_exists( 'is_network_admin' ) && is_network_admin() ) {
            $required_cap = 'manage_network_plugins';
        }

        if ( ! current_user_can( $required_cap ) ) {
            wp_send_json_error(
                array( 'message' => __( 'You do not have permission to install plugins.', 'bulk-plugin-installer' ) ),
                403
            );
            return;
        }

        $batch_id = isset( $_POST['batch_id'] ) ? sanitize_text_field( wp_unslash( $_POST['batch_id'] ) ) : '';

        if ( empty( $batch_id ) ) {
            wp_send_json_error(
                array( 'message' => __( 'No batch ID provided.', 'bulk-plugin-installer' ) )
            );
            return;
        }

        $result = $this->rollbackBatch( $batch_id );

        // Send rollback notification email.
        if ( null !== $this->notificationManager ) {
            $this->notificationManager->sendRollbackEmail( array(
                'batch_id'  => $batch_id,
                'timestamp' => current_time( 'mysql' ),
                'user_id'   => get_current_user_id(),
                'plugins'   => $result['results'] ?? array(),
                'reason'    => __( 'Manual batch rollback triggered by admin.', 'bulk-plugin-installer' ),
            ) );
            $this->notificationManager->queueAdminNotice(
                $result['success']
                    ? __( self::MSG_ROLLBACK_SUCCESS, 'bulk-plugin-installer' )
                    : __( 'Batch rollback completed with errors.', 'bulk-plugin-installer' ),
                $result['success'] ? 'success' : 'warning'
            );
        }

        if ( $result['success'] ) {
            wp_send_json_success(
                array(
                    'message' => __( self::MSG_ROLLBACK_SUCCESS, 'bulk-plugin-installer' ),
                    'results' => $result['results'],
                )
            );
        } else {
            wp_send_json_error(
                array(
                    'message'  => __( 'Batch rollback completed with errors.', 'bulk-plugin-installer' ),
                    'failures' => $result['failures'],
                    'results'  => $result['results'],
                )
            );
        }
    }

    // ------------------------------------------------------------------
    // Private helpers
    // ------------------------------------------------------------------

    /**
     * Get the list of active batch IDs from the option.
     *
     * @return array Array of batch ID strings.
     */
    private function getActiveBatchIds(): array {
        $active = get_option( self::ACTIVE_BATCHES_KEY, array() );
        return is_array( $active ) ? $active : array();
    }

    /**
     * Remove a batch ID from the active batches list.
     *
     * @param string $batch_id Batch ID to remove.
     */
    private function removeBatchId( string $batch_id ): void {
        $active = $this->getActiveBatchIds();
        $active = array_values( array_filter( $active, fn( $id ) => $id !== $batch_id ) );
        update_option( self::ACTIVE_BATCHES_KEY, $active, false );
    }

    /**
     * Clean up backup directories for a batch manifest.
     *
     * @param array $manifest Batch manifest data.
     */
    private function cleanupBatchBackups( array $manifest ): void {
        $plugins = $manifest['plugins'] ?? array();

        foreach ( $plugins as $plugin ) {
            $backup_path = $plugin['backup_path'] ?? '';
            if ( ! empty( $backup_path ) ) {
                $this->rollback->cleanupBackup( $backup_path );
            }
        }
    }

    /**
     * Get the plugin directory path.
     *
     * @param string $slug Plugin slug.
     * @return string Absolute path to the plugin directory.
     */
    protected function getPluginDir( string $slug ): string {
        return WP_CONTENT_DIR . '/plugins/' . $slug;
    }
}
