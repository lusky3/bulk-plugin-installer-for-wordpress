<?php
/**
 * Plugin Processor for Bulk Plugin Installer.
 *
 * Orchestrates installation/update of each plugin using WordPress
 * Plugin_Upgrader, with rollback support, activation management,
 * and operation logging.
 *
 * @package BulkPluginInstaller
 */

// Abort if this file is called directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Processes plugin installs and updates in batch.
 *
 * Iterates selected plugins sequentially, calling Plugin_Upgrader
 * for each. Supports backup/rollback for updates, partial install
 * cleanup for failed new installs, and per-plugin activation control.
 *
 * @since 1.0.0
 */
class BPIPluginProcessor {

    /**
     * Rollback manager instance.
     *
     * @var BPIRollbackManager
     */
    private BPIRollbackManager $rollback;

    /**
     * Log manager instance.
     *
     * @var BPILogManager
     */
    private BPILogManager $logger;

    /**
     * Settings manager instance.
     *
     * @var BPISettingsManager
     */
    private BPISettingsManager $settings;

    /**
     * Notification manager instance (optional, set via setter).
     *
     * @var BPINotificationManager|null
     */
    private ?BPINotificationManager $notificationManager = null;

    /**
     * Batch rollback manager instance (optional, set via setter).
     *
     * @var BPIBatchRollbackManager|null
     */
    private ?BPIBatchRollbackManager $batchRollbackManager = null;

    /**
     * Results from the current batch.
     *
     * @var array
     */
    private array $results = array();

    /**
     * Current batch ID.
     *
     * @var string
     */
    private string $batchId = '';

    /**
     * Constructor.
     *
     * @param BPIRollbackManager $rollback Rollback manager.
     * @param BPILogManager      $logger   Log manager.
     * @param BPISettingsManager $settings Settings manager.
     *
     * @since 1.0.0
     */
    public function __construct(
        BPIRollbackManager $rollback,
        BPILogManager $logger,
        BPISettingsManager $settings
    ) {
        $this->rollback = $rollback;
        $this->logger   = $logger;
        $this->settings = $settings;
    }

    /**
     * Set the notification manager for post-batch notifications.
     *
     * @param BPINotificationManager $notificationManager Notification manager instance.
     *
     * @since 1.0.0
     */
    public function setNotificationManager( BPINotificationManager $notificationManager ): void {
        $this->notificationManager = $notificationManager;
    }

    /**
     * Set the batch rollback manager for recording batch manifests.
     *
     * @param BPIBatchRollbackManager $batchRollbackManager Batch rollback manager instance.
     *
     * @since 1.0.0
     */
    public function setBatchRollbackManager( BPIBatchRollbackManager $batchRollbackManager ): void {
        $this->batchRollbackManager = $batchRollbackManager;
    }

    /**
     * Process a batch of selected plugins.
     *
     * Generates a unique batch ID, iterates through plugins sequentially,
     * and tracks results. Continues processing even if individual plugins fail.
     *
     * @param array $selected_plugins Array of plugin data arrays.
     * @param bool  $dry_run          Whether to simulate without making changes.
     * @return array Array of per-plugin result arrays.
     *
     * @since 1.0.0
     */
    public function processBatch( array $selected_plugins, bool $dry_run = false ): array {
        $this->results  = array();
        $this->batchId = 'bpi_' . wp_generate_uuid4();

        do_action( 'bpi_before_process_batch', $selected_plugins, $dry_run );

        foreach ( $selected_plugins as $plugin_data ) {
            $result          = $this->processPlugin( $plugin_data, $dry_run );
            $this->results[] = $result;
        }

        do_action( 'bpi_after_process_batch', $this->results, $dry_run );

        return $this->results;
    }

    /**
     * Process a single plugin install or update.
     *
     * Determines the action (install/update), handles backup for updates,
     * performs the operation, and manages rollback/cleanup on failure.
     *
     * @param array $plugin_data Plugin data with slug, action, file_path, etc.
     * @param bool  $dry_run     Whether to simulate without making changes.
     * @return array Result array with status, messages, etc.
     *
     * @since 1.0.0
     */
    public function processPlugin( array $plugin_data, bool $dry_run = false ): array {
        $slug        = $plugin_data['slug'] ?? '';
        $action      = $plugin_data['action'] ?? 'install';
        $plugin_name = $plugin_data['plugin_name'] ?? $slug;
        $new_version = $plugin_data['plugin_version'] ?? '';
        $old_version = $plugin_data['installed_version'] ?? '';

        // Early validation: slug is required.
        if ( '' === $slug ) {
            return array(
                'slug'        => '',
                'plugin_name' => $plugin_name,
                'action'      => $action,
                'status'      => 'failed',
                'messages'    => array( __( 'Plugin slug is missing.', 'bulk-plugin-installer' ) ),
                'activated'   => false,
                'rolled_back' => false,
            );
        }

        $log_ctx = array(
            'action' => $action, 'slug' => $slug, 'plugin_name' => $plugin_name,
            'old_version' => $old_version, 'new_version' => $new_version,
        );

        $result = array(
            'slug'        => $slug,
            'plugin_name' => $plugin_name,
            'action'      => $action,
            'status'      => 'pending',
            'messages'    => array(),
            'activated'   => false,
            'rolled_back' => false,
        );

        if ( $dry_run ) {
            $result = $this->simulateDryRun( $plugin_data, $result, $log_ctx );
        } else {
            $result = $this->executePluginOperation( $plugin_data, $result, $log_ctx );
        }

        $result = apply_filters( 'bpi_process_plugin_result', $result, $plugin_data, $dry_run );

        return $result;
    }


    /**
     * Get the batch summary with counts.
     *
     * @return array Summary with total, installed, updated, failed, rolled_back counts.
     *
     * @since 1.0.0
     */
    public function getBatchSummary(): array {
        $summary = array(
            'total'        => count( $this->results ),
            'installed'    => 0,
            'updated'      => 0,
            'failed'       => 0,
            'rolled_back'  => 0,
            'incompatible' => 0,
        );

        foreach ( $this->results as $result ) {
            if ( 'failed' === $result['status'] ) {
                $summary['failed']++;
                if ( ! empty( $result['rolled_back'] ) ) {
                    $summary['rolled_back']++;
                }
            } elseif ( 'incompatible' === $result['status'] ) {
                $summary['incompatible']++;
            } elseif ( 'success' === $result['status'] ) {
                if ( 'update' === $result['action'] ) {
                    $summary['updated']++;
                } else {
                    $summary['installed']++;
                }
            }
        }

        return $summary;
    }

    /**
     * Register the AJAX handlers for processing and dry run.
     *
     * @since 1.0.0
     */
    public function registerAjaxHandler(): void {
        add_action( 'wp_ajax_bpi_process', array( $this, 'handleAjaxProcess' ) );
        add_action( 'wp_ajax_bpi_dry_run', array( $this, 'handleAjaxDryRun' ) );
    }

    /**
     * AJAX handler for wp_ajax_bpi_process.
     *
     * Verifies nonce and capability, then processes the batch.
     * In Network Admin context, checks `manage_network_plugins`.
     *
     * @since 1.0.0
     */
    public function handleAjaxProcess(): void {
        if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( wp_unslash( $_POST['_wpnonce'] ), 'bpi_process' ) ) {
            wp_send_json_error(
                array( 'message' => __( 'Security verification failed. Please refresh the page and try again.', 'bulk-plugin-installer' ) ),
                403
            );
            return;
        }

        $required_cap = $this->getRequiredCapability();
        if ( ! current_user_can( $required_cap ) ) {
            wp_send_json_error(
                array( 'message' => __( 'You do not have permission to install plugins.', 'bulk-plugin-installer' ) ),
                403
            );
            return;
        }

        $selected = isset( $_POST['selected_plugins'] ) ? wp_unslash( $_POST['selected_plugins'] ) : array();
        $dry_run  = ! empty( $_POST['dry_run'] );

        if ( ! is_array( $selected ) || empty( $selected ) ) {
            wp_send_json_error(
                array( 'message' => __( 'No plugins selected for processing.', 'bulk-plugin-installer' ) )
            );
            return;
        }

        // Sanitize and validate each plugin entry.
        $selected = $this->sanitizeSelectedPlugins( $selected );

        $results = $this->processBatch( $selected, $dry_run );
        $summary = $this->getBatchSummary();

        // Record batch manifest for rollback (skip for dry runs).
        if ( ! $dry_run && null !== $this->batchRollbackManager ) {
            $this->batchRollbackManager->recordBatch( $this->batchId, array(
                'batch_id'  => $this->batchId,
                'user_id'   => get_current_user_id(),
                'timestamp' => current_time( 'mysql' ),
                'plugins'   => $results,
                'summary'   => $summary,
            ) );
        }

        // Send notification email after batch completion (skip for dry runs).
        if ( ! $dry_run && null !== $this->notificationManager ) {
            $this->notificationManager->sendBatchEmail( array(
                'timestamp' => current_time( 'mysql' ),
                'user_id'   => get_current_user_id(),
                'plugins'   => $results,
                'summary'   => $summary,
            ) );
            $this->notificationManager->queueAdminNotice(
                sprintf(
                    /* translators: %1$d: total count, %2$d: success count, %3$d: failure count */
                    __( 'Bulk operation complete: %1$d plugins processed (%2$d succeeded, %3$d failed).', 'bulk-plugin-installer' ),
                    $summary['total'] ?? 0,
                    ( $summary['installed'] ?? 0 ) + ( $summary['updated'] ?? 0 ),
                    $summary['failed'] ?? 0
                ),
                ( $summary['failed'] ?? 0 ) > 0 ? 'warning' : 'success'
            );
        }

        wp_send_json_success(
            array(
                'results'  => $results,
                'summary'  => $summary,
                'batch_id' => $this->batchId,
            )
        );
    }

    /**
     * AJAX handler for wp_ajax_bpi_dry_run.
     *
     * Dedicated dry run endpoint. Verifies nonce and capability,
     * then processes the batch in dry run mode. The queue remains
     * intact so the user can proceed to actual installation.
     * In Network Admin context, checks `manage_network_plugins`.
     *
     * @since 1.0.0
     */
    public function handleAjaxDryRun(): void {
        if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( wp_unslash( $_POST['_wpnonce'] ), 'bpi_process' ) ) {
            wp_send_json_error(
                array( 'message' => __( 'Security verification failed. Please refresh the page and try again.', 'bulk-plugin-installer' ) ),
                403
            );
            return;
        }

        $required_cap = $this->getRequiredCapability();
        if ( ! current_user_can( $required_cap ) ) {
            wp_send_json_error(
                array( 'message' => __( 'You do not have permission to install plugins.', 'bulk-plugin-installer' ) ),
                403
            );
            return;
        }

        $selected = isset( $_POST['selected_plugins'] ) ? wp_unslash( $_POST['selected_plugins'] ) : array();

        if ( ! is_array( $selected ) || empty( $selected ) ) {
            wp_send_json_error(
                array( 'message' => __( 'No plugins selected for dry run.', 'bulk-plugin-installer' ) )
            );
            return;
        }

        // Sanitize and validate each plugin entry.
        $selected = $this->sanitizeSelectedPlugins( $selected );

        $results = $this->processBatch( $selected, true );
        $summary = $this->getBatchSummary();

        wp_send_json_success(
            array(
                'results'    => $results,
                'summary'    => $summary,
                'batch_id'   => $this->batchId,
                'is_dry_run' => true,
                'message'    => __( 'Dry run complete. No changes were made to your WordPress installation.', 'bulk-plugin-installer' ),
            )
        );
    }

    // ------------------------------------------------------------------
    // Protected methods (overridable for testing)
    // ------------------------------------------------------------------

    /**
     * Run the Plugin_Upgrader for install or update.
     *
     * @param string $action      'install' or 'update'.
     * @param string $file_path   Path to the ZIP file.
     * @param string $plugin_file Plugin file path for updates.
     * @return true|\WP_Error True on success, WP_Error on failure.
     */
    protected function runUpgrader( string $action, string $file_path, string $plugin_file ): true|\WP_Error {
        $skin     = new \WP_Ajax_Upgrader_Skin();
        $upgrader = new \Plugin_Upgrader( $skin );

        if ( 'update' === $action ) {
            $result = $upgrader->upgrade( $plugin_file, array( 'package' => $file_path ) );
        } else {
            $result = $upgrader->install( $file_path );
        }

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        if ( true !== $result && null !== $result ) {
            return new \WP_Error(
                'install_failed',
                __( 'Plugin installation returned an unexpected result.', 'bulk-plugin-installer' )
            );
        }

        return true;
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

    /**
     * Check if a plugin is currently active.
     *
     * @param string $plugin_file Plugin file path.
     * @return bool True if active.
     */
    protected function isPluginActive( string $plugin_file ): bool {
        return is_plugin_active( $plugin_file );
    }

    /**
     * Activate a plugin via WordPress API.
     *
     * Supports network-wide activation when the `network_wide` flag
     * is set in the plugin data context.
     *
     * @param string $plugin_file  Plugin file path.
     * @param bool   $network_wide Whether to activate network-wide.
     * @return \WP_Error|null WP_Error on failure, null on success.
     */
    protected function wpActivatePlugin( string $plugin_file, bool $network_wide = false ): \WP_Error|null {
        return activate_plugin( $plugin_file, '', $network_wide );
    }

    // ------------------------------------------------------------------
    // Private helpers
    // ------------------------------------------------------------------

    /**
     * Determine if a plugin should be activated after install.
     *
     * Per-plugin toggle overrides the global bpi_auto_activate setting.
     *
     * @param array $plugin_data Plugin data array.
     * @return bool True if the plugin should be activated.
     */
    private function shouldActivate( array $plugin_data ): bool {
        // Per-plugin toggle takes precedence if set.
        if ( isset( $plugin_data['activate'] ) ) {
            return (bool) $plugin_data['activate'];
        }

        // Fall back to global setting.
        return (bool) $this->settings->getOption( 'bpi_auto_activate' );
    }

    /**
     * Execute the actual install/update operation for a plugin.
     *
     * @param array $plugin_data Plugin data array.
     * @param array $result      Result array to populate.
     * @param array $log_ctx     Logging context.
     * @return array Populated result array.
     */
    private function executePluginOperation( array $plugin_data, array $result, array $log_ctx ): array {
        $action      = $plugin_data['action'] ?? 'install';
        $slug        = $plugin_data['slug'] ?? '';
        $plugin_name = $plugin_data['plugin_name'] ?? $slug;
        $file_path   = $plugin_data['file_path'] ?? '';
        $plugin_file = $plugin_data['plugin_file'] ?? $slug . '/' . $slug . '.php';

        $result['status'] = 'installing';
        $backup_path      = '';

        // For updates: create a backup before proceeding.
        if ( 'update' === $action ) {
            $backup_path = $this->rollback->createBackup( $this->getPluginDir( $slug ) );

            if ( is_wp_error( $backup_path ) ) {
                $result['status']     = 'failed';
                $result['messages'][] = sprintf(
                    __( 'Failed to create backup for "%1$s": %2$s', 'bulk-plugin-installer' ),
                    $plugin_name,
                    $backup_path->get_error_message()
                );
                $this->logOperation( $log_ctx + array( 'status' => 'failed', 'messages' => $result['messages'] ) );
                return $result;
            }
        }

        // Perform the install or update.
        $install_result = $this->runUpgrader( $action, $file_path, $plugin_file );

        if ( is_wp_error( $install_result ) || true !== $install_result ) {
            return $this->handleInstallFailure( $install_result, $result, $backup_path, $log_ctx );
        }

        // Success — clean up backup if it was an update.
        if ( 'update' === $action && '' !== $backup_path ) {
            $this->rollback->cleanupBackup( $backup_path );
        }

        $result['status']     = 'success';
        $result['messages'][] = sprintf(
            __( 'Successfully %1$s "%2$s".', 'bulk-plugin-installer' ),
            'update' === $action ? 'updated' : 'installed',
            $plugin_name
        );

        $this->handleActivation( $plugin_data, $plugin_file, $result );

        $this->logOperation( $log_ctx + array( 'status' => 'success', 'messages' => $result['messages'] ) );
        return $result;
    }

    /**
     * Simulate a dry run for a plugin.
     *
     * @param array $plugin_data Plugin data array.
     * @param array $result      Result array to populate.
     * @param array $log_ctx     Logging context.
     * @return array Populated result array.
     */
    private function simulateDryRun( array $plugin_data, array $result, array $log_ctx ): array {
        $action      = $plugin_data['action'] ?? 'install';
        $plugin_name = $plugin_data['plugin_name'] ?? ( $plugin_data['slug'] ?? '' );

        // Check compatibility during dry run.
        $requires_php = $plugin_data['requires_php'] ?? '';
        if ( '' !== $requires_php && version_compare( PHP_VERSION, $requires_php, '<' ) ) {
            $result['status']     = 'incompatible';
            $result['is_dry_run'] = true;
            $result['compatibility_issues'] = array(
                sprintf(
                    __( 'Requires PHP %1$s (current: %2$s).', 'bulk-plugin-installer' ),
                    $requires_php,
                    PHP_VERSION
                ),
            );
            $result['messages'][] = sprintf(
                __( 'Skipped "%s" due to compatibility issues.', 'bulk-plugin-installer' ),
                $plugin_name
            );
            $result['messages'][] = __( 'No changes were made.', 'bulk-plugin-installer' );
            $this->logOperation( $log_ctx + array( 'status' => 'incompatible', 'messages' => $result['messages'], 'is_dry_run' => true ) );
            return $result;
        }

        $result['status']     = 'success';
        $result['is_dry_run'] = true;
        $result['messages'][] = sprintf(
            __( 'Dry run: would %1$s "%2$s".', 'bulk-plugin-installer' ),
            'update' === $action ? 'update' : 'install',
            $plugin_name
        );

        if ( $this->shouldActivate( $plugin_data ) ) {
            $result['messages'][] = sprintf(
                __( 'Would activate "%s" after installation.', 'bulk-plugin-installer' ),
                $plugin_name
            );
        }

        $result['messages'][] = __( 'No changes were made.', 'bulk-plugin-installer' );

        $this->logOperation( $log_ctx + array( 'status' => 'success', 'messages' => $result['messages'], 'is_dry_run' => true ) );
        return $result;
    }

    /**
     * Handle a failed install/update operation.
     *
     * @param mixed  $install_result The failed result from runUpgrader.
     * @param array  $result         Result array to populate.
     * @param string $backup_path    Backup path for rollback (empty for installs).
     * @param array  $log_ctx        Logging context.
     * @return array Populated result array.
     */
    private function handleInstallFailure( mixed $install_result, array $result, string $backup_path, array $log_ctx ): array {
        $error_message = is_wp_error( $install_result )
            ? $install_result->get_error_message()
            : __( 'Plugin installation returned an unexpected result.', 'bulk-plugin-installer' );

        $result['status']     = 'failed';
        $result['messages'][] = sprintf(
            __( 'Failed to %1$s "%2$s": %3$s', 'bulk-plugin-installer' ),
            $result['action'] ?? 'install',
            $result['plugin_name'] ?? '',
            $error_message
        );

        $slug = $result['slug'] ?? '';
        $plugin_dir = $this->getPluginDir( $slug );

        // Attempt rollback for updates, cleanup for new installs.
        if ( '' !== $backup_path ) {
            $restore_result = $this->rollback->restoreBackup( $backup_path, $plugin_dir );
            $result['rolled_back'] = ! is_wp_error( $restore_result );
        } else {
            $this->rollback->removePartialInstall( $plugin_dir );
        }

        $this->logOperation( $log_ctx + array( 'status' => 'failed', 'messages' => $result['messages'] ) );
        return $result;
    }

    /**
     * Handle plugin activation after successful install/update.
     *
     * @param array  $plugin_data Plugin data array.
     * @param string $plugin_file Plugin file path.
     * @param array  &$result     Result array (modified by reference).
     */
    private function handleActivation( array $plugin_data, string $plugin_file, array &$result ): void {
        // For updates: if the plugin is already active, report it as activated
        // without calling wpActivatePlugin again.
        $action = $plugin_data['action'] ?? 'install';
        if ( 'update' === $action && $this->isPluginActive( $plugin_file ) ) {
            $result['activated'] = true;
            return;
        }

        if ( ! $this->shouldActivate( $plugin_data ) ) {
            return;
        }

        $network_wide = ! empty( $plugin_data['network_activate'] );
        $activate_result = $this->wpActivatePlugin( $plugin_file, $network_wide );

        if ( is_wp_error( $activate_result ) ) {
            $result['messages'][] = sprintf(
                __( '"%1$s" could not be activated: %2$s', 'bulk-plugin-installer' ),
                $result['plugin_name'] ?? '',
                $activate_result->get_error_message()
            );
        } else {
            $result['activated']  = true;
            $result['messages'][] = sprintf(
                __( 'Activated "%s".', 'bulk-plugin-installer' ),
                $result['plugin_name'] ?? ''
            );
        }
    }



    /**
     * Log an operation via the Log_Manager.
     *
     * @param array $context {
     *     Operation context.
     *
     *     @type string $action      Action type.
     *     @type string $slug        Plugin slug.
     *     @type string $plugin_name Plugin display name.
     *     @type string $old_version Previous version.
     *     @type string $new_version New version.
     *     @type string $status      Outcome status.
     *     @type array  $messages    Messages array.
     *     @type bool   $is_dry_run  Whether this is a dry run operation.
     * }
     */
    private function logOperation( array $context ): void {
        $this->logger->log(
            $context['action'] ?? '',
            array(
                'batch_id'     => $this->batchId,
                'plugin_slug'  => $context['slug'] ?? '',
                'plugin_name'  => $context['plugin_name'] ?? '',
                'from_version' => $context['old_version'] ?? '',
                'to_version'   => $context['new_version'] ?? '',
                'status'       => $context['status'] ?? '',
                'message'      => implode( ' ', $context['messages'] ?? array() ),
                'is_dry_run'   => $context['is_dry_run'] ?? false,
            )
        );
    }

    /**
     * Sanitize and validate an array of selected plugin data from user input.
     *
     * @param array $selected Raw plugin data from $_POST.
     * @return array Sanitized plugin data.
     */
    private function sanitizeSelectedPlugins( array $selected ): array {
        $upload_dir = wp_upload_dir();
        $bpi_tmp    = trailingslashit( $upload_dir['basedir'] ) . 'bpi-tmp/';

        return array_map( function ( $p ) use ( $bpi_tmp ) {
            $sanitized = array(
                'slug'              => sanitize_text_field( $p['slug'] ?? '' ),
                'plugin_name'       => sanitize_text_field( $p['plugin_name'] ?? '' ),
                'plugin_version'    => sanitize_text_field( $p['plugin_version'] ?? '' ),
                'action'            => in_array( $p['action'] ?? '', array( 'install', 'update' ), true ) ? $p['action'] : 'install',
                'installed_version' => sanitize_text_field( $p['installed_version'] ?? '' ),
                'plugin_file'       => sanitize_text_field( $p['plugin_file'] ?? '' ),
                'activate'          => ! empty( $p['activate'] ),
                'network_activate'  => ! empty( $p['network_activate'] ),
            );
            $file_path = $p['file_path'] ?? '';
            if ( '' !== $file_path ) {
                $real_path = realpath( $file_path );
                $real_tmp  = realpath( $bpi_tmp );
                if ( false !== $real_path && false !== $real_tmp && str_starts_with( $real_path, $real_tmp ) ) {
                    $sanitized['file_path'] = $real_path;
                } else {
                    $sanitized['file_path'] = '';
                }
            } else {
                $sanitized['file_path'] = '';
            }
            return $sanitized;
        }, $selected );
    }

    /**
     * Determine the required capability for the current context.
     *
     * In Network Admin context (multisite), requires `manage_network_plugins`.
     * On single sites or individual sites within multisite, requires `install_plugins`.
     *
     * @return string The required capability name.
     */
    private function getRequiredCapability(): string {
        if ( function_exists( 'is_multisite' ) && is_multisite()
            && function_exists( 'is_network_admin' ) && is_network_admin() ) {
            return 'manage_network_plugins';
        }
        return 'install_plugins';
    }
}
