<?php
/**
 * Admin page for the Bulk Plugin Installer.
 *
 * Registers the submenu page under Plugins, adds a "Bulk Upload" link
 * on the Plugins > Add New screen, and renders the admin page shell.
 *
 * @package BulkPluginInstaller
 */

// Abort if this file is called directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class BPIAdminPage
 *
 * Handles admin menu registration, the "Bulk Upload" link on the
 * Add New Plugins screen, page rendering, and asset enqueueing.
 *
 * @since 1.0.0
 */
class BPIAdminPage {

    /**
     * Menu slug for the bulk upload admin page.
     *
     * @var string
     */
    const MENU_SLUG = 'bpi-bulk-upload';

    /**
     * Page title for the bulk upload admin page.
     */
    const PAGE_TITLE = 'Bulk Upload Plugins';

    /**
     * Menu title for the bulk upload admin page.
     */
    const MENU_TITLE = 'Bulk Upload';

    /**
     * Nonce action for the bulk upload page.
     *
     * @var string
     */
    const NONCE_ACTION = 'bpi_bulk_upload';

    /**
     * Nonce action for the preview AJAX endpoint.
     *
     * @var string
     */
    const PREVIEW_NONCE_ACTION = 'bpi_preview';

    /**
     * Register hooks for the admin page.
     *
     * @since 1.0.0
     */
    public function registerHooks(): void {
        add_action( 'admin_menu', array( $this, 'registerMenu' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueueAssets' ) );
        add_filter( 'plugin_install_action_links', array( $this, 'addBulkUploadLink' ), 10, 1 );
        add_action( 'wp_ajax_bpi_preview', array( $this, 'handlePreview' ) );
    }

    /**
     * Register the submenu page under Plugins.
     *
     * Only adds the page if the current user has the `install_plugins` capability.
     *
     * @since 1.0.0
     */
    public function registerMenu(): void {
        add_submenu_page(
            'plugins.php',
            __( 'Bulk Upload Plugins', 'bulk-plugin-installer' ),
            __( 'Bulk Upload', 'bulk-plugin-installer' ),
            'install_plugins',
            self::MENU_SLUG,
            array( $this, 'renderPage' )
        );
    }

    /**
     * Register the submenu page under Network Admin > Plugins.
     *
     * Only adds the page if the current user has the `manage_network_plugins` capability.
     * Called from the bootstrap class on the `network_admin_menu` hook.
     *
     * @since 1.0.0
     */
    public function registerNetworkMenu(): void {
        add_submenu_page(
            'plugins.php',
            __( 'Bulk Upload Plugins', 'bulk-plugin-installer' ),
            __( 'Bulk Upload', 'bulk-plugin-installer' ),
            'manage_network_plugins',
            self::MENU_SLUG,
            array( $this, 'renderPage' )
        );
    }

    /**
     * Add a "Bulk Upload" link to the Plugins > Add New screen.
     *
     * Filters the plugin install action links to append a link
     * pointing to the bulk upload admin page.
     *
     * @since 1.0.0
     *
     * @param array $action_links Existing action links.
     * @return array Modified action links with "Bulk Upload" appended.
     */
    public function addBulkUploadLink( array $action_links ): array {
        if ( ! current_user_can( 'install_plugins' ) ) {
            return $action_links;
        }

        $url = admin_url( 'plugins.php?page=' . self::MENU_SLUG );

        $action_links[] = sprintf(
            '<a href="%s" class="bpi-bulk-upload-link">%s</a>',
            esc_url( $url ),
            esc_html__( 'Bulk Upload', 'bulk-plugin-installer' )
        );

        return $action_links;
    }

    /**
     * Render the bulk upload admin page.
     *
     * Outputs the page wrapper with a heading, nonce field, and
     * container div that JavaScript will populate.
     * In Network Admin context, checks `manage_network_plugins` capability.
     *
     * @since 1.0.0
     */
    public function renderPage(): void {
        $required_cap = $this->getRequiredCapability();
        if ( ! current_user_can( $required_cap ) ) {
            return;
        }

        echo '<div class="wrap" id="bpi-bulk-upload-wrap">';
        echo '<h1>' . esc_html__( 'Bulk Upload Plugins', 'bulk-plugin-installer' ) . '</h1>';
        wp_nonce_field( self::NONCE_ACTION, 'bpi_bulk_upload_nonce' );
        echo '<div id="bpi-bulk-upload-app"></div>';
        echo '</div>';
    }

    /**
     * Enqueue JavaScript and CSS assets for the admin page.
     *
     * @since 1.0.0
     *
     * @param string $hook_suffix The current admin page hook suffix.
     */
    public function enqueueAssets( string $hook_suffix ): void {
        if ( 'plugins_page_' . self::MENU_SLUG !== $hook_suffix ) {
            return;
        }

        wp_enqueue_style(
            'bpi-admin',
            BPI_PLUGIN_URL . 'assets/css/bpi-admin.css',
            array(),
            BPI_VERSION
        );

        wp_enqueue_script(
            'bpi-admin',
            BPI_PLUGIN_URL . 'assets/js/bpi-admin.js',
            array( 'jquery' ),
            BPI_VERSION,
            true
        );

        $is_network_admin = function_exists( 'is_multisite' ) && is_multisite()
            && function_exists( 'is_network_admin' ) && is_network_admin();

        $localize_data = array(
            'ajaxUrl'          => admin_url( 'admin-ajax.php' ),
            'nonce'            => wp_create_nonce( self::NONCE_ACTION ),
            'uploadNonce'      => wp_create_nonce( 'bpi_upload' ),
            'queueRemoveNonce' => wp_create_nonce( 'bpi_queue_remove' ),
            'previewNonce'     => wp_create_nonce( self::PREVIEW_NONCE_ACTION ),
            'processNonce'       => wp_create_nonce( 'bpi_process' ),
            'dryRunNonce'        => wp_create_nonce( 'bpi_process' ),
            'rollbackNonce'      => wp_create_nonce( 'bpi_batch_rollback' ),
            'saveProfileNonce'   => wp_create_nonce( 'bpi_save_profile' ),
            'deleteProfileNonce' => wp_create_nonce( 'bpi_delete_profile' ),
            'isNetworkAdmin'   => $is_network_admin,
            'maxFileSize'      => (int) get_option( 'bpi_max_file_size', 0 ),
            'maxPlugins'       => (int) get_option( 'bpi_max_plugins', 20 ),
            'i18n'             => array(
                'dropZoneLabel'          => __( 'Drop ZIP files here or click to browse', 'bulk-plugin-installer' ),
                'dropZoneText'           => __( 'Drag & drop plugin ZIP files here', 'bulk-plugin-installer' ),
                'dropZoneSubtext'        => __( 'or click to browse — only .zip files accepted', 'bulk-plugin-installer' ),
                'selectFilesLabel'       => __( 'Select plugin ZIP files', 'bulk-plugin-installer' ),
                'uploadQueue'            => __( 'Upload Queue', 'bulk-plugin-installer' ),
                'queuedFilesLabel'       => __( 'Queued plugin files', 'bulk-plugin-installer' ),
                'addMoreLabel'           => __( 'Add more plugin ZIP files to the queue', 'bulk-plugin-installer' ),
                'addMoreFiles'           => __( 'Add More Files', 'bulk-plugin-installer' ),
                'continuePreviewLabel'   => __( 'Continue to preview and confirm plugin installation', 'bulk-plugin-installer' ),
                'continueToPreview'      => __( 'Continue to Preview', 'bulk-plugin-installer' ),
                'loadingPreview'         => __( 'Loading Preview…', 'bulk-plugin-installer' ),
                'failedLoadPreview'      => __( 'Failed to load preview.', 'bulk-plugin-installer' ),
                'networkErrorPreview'    => __( 'Network error loading preview.', 'bulk-plugin-installer' ),
                'backToQueueLabel'       => __( 'Go back to the upload queue', 'bulk-plugin-installer' ),
                'backToQueue'            => __( '← Back to Queue', 'bulk-plugin-installer' ),
                'previewConfirm'         => __( 'Preview & Confirm', 'bulk-plugin-installer' ),
                'selectAllLabel'         => __( 'Select all plugins', 'bulk-plugin-installer' ),
                'selectAll'              => __( 'Select All', 'bulk-plugin-installer' ),
                'deselectAllLabel'       => __( 'Deselect all plugins', 'bulk-plugin-installer' ),
                'deselectAll'            => __( 'Deselect All', 'bulk-plugin-installer' ),
                'pluginsListLabel'       => __( 'Plugins to install or update', 'bulk-plugin-installer' ),
                'installSelectedLabel'   => __( 'Install selected plugins', 'bulk-plugin-installer' ),
                'installSelected'        => __( 'Install Selected', 'bulk-plugin-installer' ),
                'dryRunLabel'            => __( 'Simulate installation without making changes', 'bulk-plugin-installer' ),
                'dryRunBtn'              => __( 'Dry Run', 'bulk-plugin-installer' ),
                /* translators: %s: plugin name */
                'selectPlugin'           => __( 'Select %s', 'bulk-plugin-installer' ),
                'installed'              => __( 'Installed:', 'bulk-plugin-installer' ),
                'by'                     => __( 'By', 'bulk-plugin-installer' ),
                'lastUpdated'            => __( 'Last Updated:', 'bulk-plugin-installer' ),
                'testedUpTo'             => __( 'Tested up to:', 'bulk-plugin-installer' ),
                /* translators: %s: plugin name */
                'overrideCompatLabel'    => __( 'Override incompatibility warning for %s', 'bulk-plugin-installer' ),
                'installAnyway'          => __( 'Install anyway (override)', 'bulk-plugin-installer' ),
                /* translators: %s: plugin name */
                'toggleChangelogLabel'   => __( 'Toggle changelog for %s', 'bulk-plugin-installer' ),
                'changelog'              => __( 'Changelog', 'bulk-plugin-installer' ),
                'noChangelog'            => __( 'No changelog available', 'bulk-plugin-installer' ),
                /* translators: %s: plugin name */
                'networkActivateLabel'   => __( 'Network Activate %s', 'bulk-plugin-installer' ),
                'networkActivate'        => __( 'Network Activate', 'bulk-plugin-installer' ),
                /* translators: %s: plugin name */
                'activateAfterLabel'     => __( 'Activate %s after install', 'bulk-plugin-installer' ),
                'activate'               => __( 'Activate', 'bulk-plugin-installer' ),
                'allSelected'            => __( 'All plugins selected', 'bulk-plugin-installer' ),
                'allDeselected'          => __( 'All plugins deselected', 'bulk-plugin-installer' ),
                'overrideApplied'        => __( 'Incompatibility override applied', 'bulk-plugin-installer' ),
                /* translators: 1: number selected, 2: total number */
                'selectedCount'          => __( '%1$s of %2$s selected', 'bulk-plugin-installer' ),
                'pending'                => __( 'Pending', 'bulk-plugin-installer' ),
                'installing'             => __( 'Installing…', 'bulk-plugin-installer' ),
                'success'                => __( 'Success', 'bulk-plugin-installer' ),
                'failed'                 => __( 'Failed', 'bulk-plugin-installer' ),
                'processingStatusLabel'  => __( 'Plugin processing status', 'bulk-plugin-installer' ),
                'dryRunInProgress'       => __( 'Dry Run in Progress…', 'bulk-plugin-installer' ),
                'installingPlugins'      => __( 'Installing Plugins…', 'bulk-plugin-installer' ),
                /* translators: %s: plugin name */
                'installingPlugin'       => __( 'Installing %s', 'bulk-plugin-installer' ),
                'processingError'        => __( 'Processing Error', 'bulk-plugin-installer' ),
                'processingFailed'       => __( 'Processing failed.', 'bulk-plugin-installer' ),
                'networkErrorProcessing' => __( 'Network error during processing.', 'bulk-plugin-installer' ),
                'backToUploadLabel'      => __( 'Return to the upload screen', 'bulk-plugin-installer' ),
                'backToUpload'           => __( 'Back to Upload', 'bulk-plugin-installer' ),
                'dryRunComplete'         => __( 'Dry Run Complete', 'bulk-plugin-installer' ),
                'processingComplete'     => __( 'Processing Complete', 'bulk-plugin-installer' ),
                'dryRunNotice'           => __( 'This was a dry run. No changes were made to your WordPress installation.', 'bulk-plugin-installer' ),
                'batchSummaryLabel'      => __( 'Batch summary', 'bulk-plugin-installer' ),
                'installedLabel'         => __( 'Installed', 'bulk-plugin-installer' ),
                'updatedLabel'           => __( 'Updated', 'bulk-plugin-installer' ),
                'failedLabel'            => __( 'Failed', 'bulk-plugin-installer' ),
                'perPluginResultsLabel'  => __( 'Per-plugin results', 'bulk-plugin-installer' ),
                'rolledBack'             => __( 'Rolled back', 'bulk-plugin-installer' ),
                'rollbackBatchLabel'     => __( 'Rollback entire batch', 'bulk-plugin-installer' ),
                'rollbackBatch'          => __( 'Rollback Entire Batch', 'bulk-plugin-installer' ),
                'saveProfileLabel'       => __( 'Save installed plugins as a profile', 'bulk-plugin-installer' ),
                'saveAsProfile'          => __( 'Save as Profile', 'bulk-plugin-installer' ),
                'onlyZipAccepted'        => __( 'Only .zip files are accepted. Skipped:', 'bulk-plugin-installer' ),
                /* translators: %s: file name */
                'uploaded'               => __( 'Uploaded %s', 'bulk-plugin-installer' ),
                'uploadFailedInvalid'    => __( 'Upload failed: invalid server response.', 'bulk-plugin-installer' ),
                'uploadFailed'           => __( 'Upload failed.', 'bulk-plugin-installer' ),
                'networkErrorUpload'     => __( 'Network error during upload.', 'bulk-plugin-installer' ),
                /* translators: %s: plugin slug */
                'duplicateDetected'      => __( "Duplicate plugin '%s' detected. Only one copy was kept in the queue.", 'bulk-plugin-installer' ),
                /* translators: %s: plugin slug */
                'removedFromQueue'       => __( 'Removed %s from queue', 'bulk-plugin-installer' ),
                'uploadedStatus'         => __( 'Uploaded', 'bulk-plugin-installer' ),
                'remove'                 => __( 'Remove', 'bulk-plugin-installer' ),
                /* translators: %s: file name */
                'removeFromQueueLabel'   => __( 'Remove %s from queue', 'bulk-plugin-installer' ),
                /* translators: %s: file name */
                'uploadProgressLabel'    => __( 'Upload progress for %s', 'bulk-plugin-installer' ),
                /* translators: 1: installed count, 2: updated count, 3: failed count */
                'summaryAnnounce'        => __( '%1$s installed, %2$s updated, %3$s failed', 'bulk-plugin-installer' ),
                'dryRunCompleteAnnounce' => __( 'Dry run complete.', 'bulk-plugin-installer' ),
                /* translators: 1: count, 2: formatted size */
                'queueSummary'           => __( '%1$s file(s) — %2$s', 'bulk-plugin-installer' ),
                'confirmRollback'        => __( 'Are you sure you want to rollback the entire batch? This will revert all installed/updated plugins.', 'bulk-plugin-installer' ),
                'enterProfileName'       => __( 'Enter a name for this profile:', 'bulk-plugin-installer' ),
                /* translators: %d: max plugins allowed */
                'queueLimitExceeded'     => __( 'Queue limit of %d plugins would be exceeded.', 'bulk-plugin-installer' ),
                /* translators: %d: max file size in MB */
                'fileTooLarge'           => __( 'exceeds %dMB limit', 'bulk-plugin-installer' ),
            ),
        );

        if ( $is_network_admin ) {
            $localize_data['networkActivateNonce'] = wp_create_nonce( 'bpi_network_activate' );
        }

        wp_localize_script(
            'bpi-admin',
            'bpiAdmin',
            $localize_data
        );
    }

    /**
     * AJAX handler for wp_ajax_bpi_preview.
     *
     * Verifies nonce and capability, fetches queued items, runs compatibility
     * checks, determines install vs. update action, extracts changelog data,
     * and returns JSON preview data. In Network Admin context, adds a
     * `network_activate` field to each preview item.
     *
     * @since 1.0.0
     */
    public function handlePreview(): void {
        // Verify nonce.
        $nonce = isset( $_POST['_wpnonce'] ) ? wp_unslash( $_POST['_wpnonce'] ) : '';
        if ( ! wp_verify_nonce( $nonce, self::PREVIEW_NONCE_ACTION ) ) {
            wp_send_json_error(
                array( 'message' => __( 'Security verification failed. Please refresh the page and try again.', 'bulk-plugin-installer' ) ),
                403
            );
            return;
        }

        // Verify capability (network context uses manage_network_plugins).
        $required_cap = $this->getRequiredCapability();
        if ( ! current_user_can( $required_cap ) ) {
            wp_send_json_error(
                array( 'message' => __( 'You do not have permission to install plugins.', 'bulk-plugin-installer' ) ),
                403
            );
            return;
        }

        // Determine if we are in network admin context.
        $is_network_admin = $this->isNetworkAdminContext();

        // Get queued items.
        $queue_manager = new BPIQueueManager();
        $queue         = $queue_manager->getAll();

        if ( empty( $queue ) ) {
            wp_send_json_error(
                array( 'message' => __( 'No plugins in the upload queue.', 'bulk-plugin-installer' ) ),
                400
            );
            return;
        }

        // Run compatibility checks.
        $compat_checker = new BPICompatibilityChecker();
        $queue          = $compat_checker->checkAll( $queue );

        // Get installed plugins for action labeling.
        $installed_plugins = function_exists( 'get_plugins' ) ? get_plugins() : array();
        $installed_by_slug = array();
        foreach ( $installed_plugins as $plugin_file => $plugin_info ) {
            $slug = dirname( $plugin_file );
            if ( '.' !== $slug ) {
                $installed_by_slug[ $slug ] = array(
                    'version'     => $plugin_info['Version'] ?? '',
                    'plugin_file' => $plugin_file,
                );
            }
        }

        // Build preview data for each plugin.
        $changelog_extractor = new BPIChangelogExtractor();
        $preview_items       = array();

        foreach ( $queue as $item ) {
            $preview_items[] = $this->buildPreviewItem( $item, $installed_by_slug, $changelog_extractor, $is_network_admin );
        }

        $preview_items = apply_filters( 'bpi_preview_items', $preview_items, $queue );

        wp_send_json_success( array( 'plugins' => $preview_items ) );
    }

    /**
     * Build a single preview item for a queued plugin.
     *
     * @param array                $item                Queued plugin data.
     * @param array                $installed_by_slug   Installed plugins indexed by slug.
     * @param BPIChangelogExtractor $changelog_extractor Changelog extractor instance.
     * @param bool                 $is_network_admin    Whether in network admin context.
     * @return array Preview item data.
     */
    private function buildPreviewItem( array $item, array $installed_by_slug, BPIChangelogExtractor $changelog_extractor, bool $is_network_admin ): array {
        $slug = $item['slug'] ?? '';

        // Determine action: install or update.
        $action            = 'install';
        $installed_version = null;
        $update_type       = '';
        if ( isset( $installed_by_slug[ $slug ] ) ) {
            $action            = 'update';
            $installed_version = $installed_by_slug[ $slug ]['version'];
            $update_type       = $changelog_extractor->classifyUpdate(
                $installed_version,
                $item['plugin_version'] ?? ''
            );
        }

        $changelog_data = $this->extractChangelogData( $item, $action, $installed_version, $changelog_extractor );
        $is_compatible  = empty( $item['compatibility_issues'] );

        return array(
            'slug'                 => $slug,
            'plugin_name'          => $item['plugin_name'] ?? $slug,
            'plugin_version'       => $item['plugin_version'] ?? '',
            'plugin_author'        => $item['plugin_author'] ?? '',
            'plugin_description'   => $item['plugin_description'] ?? '',
            'action'               => $action,
            'action_label'         => 'update' === $action
                ? __( 'Update', 'bulk-plugin-installer' )
                : __( 'New Install', 'bulk-plugin-installer' ),
            'installed_version'    => $installed_version,
            'update_type'          => $update_type,
            'compatible'           => $is_compatible,
            'compatibility_issues' => $item['compatibility_issues'] ?? array(),
            'changelog'            => $changelog_data,
            'checked'              => $is_compatible,
            'network_activate'     => $is_network_admin,
        );
    }

    /**
     * Extract changelog data for a preview item.
     *
     * @param array                 $item                Plugin queue item.
     * @param string                $action              'install' or 'update'.
     * @param string|null           $installed_version   Currently installed version.
     * @param BPIChangelogExtractor $changelog_extractor Changelog extractor instance.
     * @return array Changelog data array.
     */
    private function extractChangelogData( array $item, string $action, ?string $installed_version, BPIChangelogExtractor $changelog_extractor ): array {
        $file_path = $item['file_path'] ?? '';
        if ( 'update' !== $action || '' === $file_path || ! file_exists( $file_path ) ) {
            return array();
        }

        $raw_changelog = $changelog_extractor->extract( $file_path );
        $filtered_entries = array();

        if ( ! empty( $raw_changelog['entries'] ) && null !== $installed_version ) {
            $filtered_entries = $changelog_extractor->getEntriesBetween(
                $raw_changelog['entries'],
                $installed_version,
                $item['plugin_version'] ?? ''
            );
        }

        return array(
            'entries'      => $filtered_entries,
            'last_updated' => $raw_changelog['last_updated'] ?? '',
            'tested_up_to' => $raw_changelog['tested_up_to'] ?? '',
        );
    }

    /**
     * Determine the required capability for the current context.
     *
     * In Network Admin context (multisite), requires `manage_network_plugins`.
     * On single sites or individual sites within multisite, requires `install_plugins`.
     *
     * @since 1.0.0
     *
     * @return string The required capability name.
     */
    public function getRequiredCapability(): string {
        if ( $this->isNetworkAdminContext() ) {
            return 'manage_network_plugins';
        }
        return 'install_plugins';
    }

    /**
     * Check if the current context is Network Admin within a multisite.
     *
     * @since 1.0.0
     *
     * @return bool True if in Network Admin context.
     */
    public function isNetworkAdminContext(): bool {
        return function_exists( 'is_multisite' ) && is_multisite()
            && function_exists( 'is_network_admin' ) && is_network_admin();
    }
}
