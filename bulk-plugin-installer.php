<?php
/**
 * Plugin Name:       Bulk Plugin Installer
 * Plugin URI:        https://github.com/lusky3/bulk-plugin-installer
 * Description:       Upload and install multiple WordPress plugin ZIP files in a single operation with preview, rollback, and profile support.
 * Version:           1.0.2
 * Requires at least: 5.8
 * Requires PHP:      8.3
 * Author:            Cody (lusky3) + Bulk Plugin Installer Contributors
 * Author URI:        https://github.com/lusky3
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       bulk-plugin-installer
 * Domain Path:       /languages
 *
 * @package BulkPluginInstaller
 */

// Abort if this file is called directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Plugin constants.
define( 'BPI_VERSION', '1.0.2' );
define( 'BPI_PLUGIN_FILE', __FILE__ );
define( 'BPI_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'BPI_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'BPI_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

// Autoloader for classes in the includes/ directory.
spl_autoload_register( 'bpiAutoloader' );

/**
 * Autoload classes from the includes/ directory.
 *
 * Maps PascalCase class names prefixed with "BPI" to files in includes/.
 * Example: BPILogManager => includes/class-bpi-log-manager.php
 *
 * @param string $class_name The fully-qualified class name.
 */
function bpiAutoloader( string $class_name ): void {
    // Only handle classes starting with "BPI" or the main bootstrap class.
    if ( ! str_starts_with( $class_name, 'BPI' ) && $class_name !== 'BulkPluginInstaller' ) {
        return;
    }

    // Convert PascalCase to kebab-case: BPILogManager => bpi-log-manager
    $kebab = strtolower( preg_replace( '/(?<!^)(?=[A-Z])/', '-', $class_name ) );
    // Fix "b-p-i" prefix to "bpi"
    $kebab = preg_replace( '/^b-p-i-/', 'bpi-', $kebab );
    // Fix "c-l-i" to "cli"
    $kebab = str_replace( '-c-l-i-', '-cli-', $kebab );

    $file_name = 'class-' . $kebab . '.php';
    $file_path = BPI_PLUGIN_DIR . 'includes/' . $file_name;

    if ( file_exists( $file_path ) ) {
        require_once $file_path;
    }
}

/**
 * Main plugin bootstrap class.
 *
 * Coordinates component initialization, registers lifecycle hooks,
 * and loads the text domain for internationalization.
 *
 * @package BulkPluginInstaller
 */
class BulkPluginInstaller {

    /**
     * Singleton instance.
     *
     * @var BulkPluginInstaller|null
     */
    private static ?BulkPluginInstaller $instance = null;

    /**
     * @var BPISettingsManager|null
     */
    private ?BPISettingsManager $settings_manager = null;

    /**
     * @var BPILogManager|null
     */
    private ?BPILogManager $log_manager = null;

    /**
     * @var BPIRollbackManager|null
     */
    private ?BPIRollbackManager $rollback_manager = null;

    /**
     * @var BPINotificationManager|null
     */
    private ?BPINotificationManager $notification_manager = null;

    /**
     * @var BPIAdminPage|null
     */
    private ?BPIAdminPage $admin_page = null;

    /**
     * @var BPIPluginProcessor|null
     */
    private ?BPIPluginProcessor $plugin_processor = null;

    /**
     * @var BPIBatchRollbackManager|null
     */
    private ?BPIBatchRollbackManager $batch_rollback_manager = null;

    /**
     * @var BPIBulkUploader|null
     */
    private ?BPIBulkUploader $bulk_uploader = null;

    /**
     * @var BPIQueueManager|null
     */
    private ?BPIQueueManager $queue_manager = null;

    /**
     * @var BPIProfileManager|null
     */
    private ?BPIProfileManager $profile_manager = null;

    /**
     * @var BPIGithubUpdater|null
     */
    private ?BPIGithubUpdater $github_updater = null;

    /**
     * Get the singleton instance.
     *
     * @return BulkPluginInstaller
     */
    public static function getInstance(): BulkPluginInstaller {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Private constructor to enforce singleton pattern.
     */
    private function __construct() {}

    /**
     * Register all hooks and load components.
     *
     * Hooks into WordPress init to load the text domain and
     * initializes all plugin components. When running in a
     * Multisite environment, also registers the network_admin_menu
     * hook so the bulk upload UI appears in Network Admin.
     */
    public function init(): void {
        add_action( 'init', array( $this, 'loadTextdomain' ) );

        // Eagerly instantiate shared dependencies needed for admin_init / menu hooks.
        $this->settings_manager = new BPISettingsManager();
        $this->log_manager      = new BPILogManager();
        $this->rollback_manager = new BPIRollbackManager();

        // Notification manager (eager — registers admin_notices).
        $this->notification_manager = new BPINotificationManager( $this->settings_manager );
        $this->notification_manager->registerHooks();

        // Admin page: registers admin_menu, plugin_install_action_links, and wp_ajax_bpi_preview (eager).
        $this->admin_page = new BPIAdminPage();
        $this->admin_page->registerHooks();

        // Settings manager: registers settings page (must run on admin_init).
        add_action( 'admin_init', array( $this->settings_manager, 'registerSettings' ) );
        add_action( 'admin_menu', array( $this->settings_manager, 'addMenuPage' ) );

        // Bulk uploader: wp_ajax_bpi_upload (deferred).
        add_action( 'wp_ajax_bpi_upload', function () {
            if ( null === $this->bulk_uploader ) {
                $this->bulk_uploader = new BPIBulkUploader();
            }
            $this->bulk_uploader->handleUpload();
        } );

        // Queue manager: wp_ajax_bpi_queue_remove (deferred).
        add_action( 'wp_ajax_bpi_queue_remove', function () {
            if ( null === $this->queue_manager ) {
                $this->queue_manager = new BPIQueueManager();
            }
            $this->queue_manager->handleQueueRemove();
        } );

        // Plugin processor: wp_ajax_bpi_process, wp_ajax_bpi_dry_run (deferred).
        $lazy_processor = function () {
            if ( null === $this->plugin_processor ) {
                $this->plugin_processor = new BPIPluginProcessor( $this->rollback_manager, $this->log_manager, $this->settings_manager );
                $this->plugin_processor->setNotificationManager( $this->notification_manager );
                if ( null === $this->batch_rollback_manager ) {
                    $this->batch_rollback_manager = new BPIBatchRollbackManager( $this->rollback_manager, $this->settings_manager, $this->log_manager );
                    $this->batch_rollback_manager->setNotificationManager( $this->notification_manager );
                }
                $this->plugin_processor->setBatchRollbackManager( $this->batch_rollback_manager );
            }
            return $this->plugin_processor;
        };
        add_action( 'wp_ajax_bpi_process', function () use ( $lazy_processor ) {
            $lazy_processor()->handleAjaxProcess();
        } );
        add_action( 'wp_ajax_bpi_dry_run', function () use ( $lazy_processor ) {
            $lazy_processor()->handleAjaxDryRun();
        } );

        // Batch rollback manager: wp_ajax_bpi_batch_rollback (deferred).
        add_action( 'wp_ajax_bpi_batch_rollback', function () {
            if ( null === $this->batch_rollback_manager ) {
                $this->batch_rollback_manager = new BPIBatchRollbackManager( $this->rollback_manager, $this->settings_manager, $this->log_manager );
                $this->batch_rollback_manager->setNotificationManager( $this->notification_manager );
            }
            $this->batch_rollback_manager->handleAjaxRollback();
        } );

        // Profile manager: wp_ajax_bpi_save_profile, wp_ajax_bpi_import_profile, wp_ajax_bpi_export_profile (deferred).
        $lazy_profile = function () {
            if ( null === $this->profile_manager ) {
                $this->profile_manager = new BPIProfileManager();
            }
            return $this->profile_manager;
        };
        add_action( 'wp_ajax_bpi_save_profile', function () use ( $lazy_profile ) {
            $lazy_profile()->handleAjaxSaveProfile();
        } );
        add_action( 'wp_ajax_bpi_import_profile', function () use ( $lazy_profile ) {
            $lazy_profile()->handleAjaxImportProfile();
        } );
        add_action( 'wp_ajax_bpi_export_profile', function () use ( $lazy_profile ) {
            $lazy_profile()->handleAjaxExportProfile();
        } );

        // Log manager AJAX: wp_ajax_bpi_get_log, wp_ajax_bpi_clear_log.
        add_action( 'wp_ajax_bpi_get_log', array( $this->log_manager, 'handleGetLog' ) );
        add_action( 'wp_ajax_bpi_clear_log', array( $this->log_manager, 'handleClearLog' ) );

        // GitHub update checker: notifies of new versions and shows changelog in Plugins UI (eager).
        $this->github_updater = new BPIGithubUpdater();
        $this->github_updater->registerHooks();

        // Expired backup cleanup cron.
        add_action( 'bpi_cleanup_expired_backups', function () {
            if ( null === $this->batch_rollback_manager ) {
                $this->batch_rollback_manager = new BPIBatchRollbackManager( $this->rollback_manager, $this->settings_manager, $this->log_manager );
            }
            $this->batch_rollback_manager->cleanupExpired();
        } );

        // Register network admin menu hook for multisite support.
        if ( function_exists( 'is_multisite' ) && is_multisite() ) {
            add_action( 'network_admin_menu', array( $this, 'registerNetworkAdminHooks' ) );
        }
    }

    /**
     * Callback for network_admin_menu hook.
     *
     * Delegates to the admin page component to register the
     * bulk upload menu under Network Admin > Plugins.
     */
    public function registerNetworkAdminHooks(): void {
        if ( null !== $this->admin_page ) {
            $this->admin_page->registerNetworkMenu();
        }
    }

    /**
     * Load the plugin text domain for translations.
     */
    public function loadTextdomain(): void {
        load_plugin_textdomain(
            'bulk-plugin-installer',
            false,
            dirname( BPI_PLUGIN_BASENAME ) . '/languages'
        );
    }

    /**
     * Plugin activation handler.
     *
     * Creates the log database table, sets default option values,
     * and schedules the expired backup cleanup cron.
     */
    public function activate(): void {
        // Create the activity log table.
        if ( class_exists( 'BPILogManager' ) ) {
            $log_manager = new BPILogManager();
            $log_manager->createTable();
        }

        // Set default options if they don't already exist (autoload disabled).
        $defaults = array(
            'bpi_auto_activate'            => false,
            'bpi_max_plugins'              => 20,
            'bpi_auto_rollback'            => true,
            'bpi_max_file_size'            => 0, // 0 means use server default.
            'bpi_rollback_retention'       => 24,
            'bpi_email_notifications'      => false,
            'bpi_email_recipients'         => '',
            'bpi_delete_data_on_uninstall' => false,
        );

        foreach ( $defaults as $key => $value ) {
            if ( false === get_option( $key ) ) {
                add_option( $key, $value, '', false );
            }
        }

        // Schedule expired backup cleanup cron if not already scheduled.
        if ( ! wp_next_scheduled( 'bpi_cleanup_expired_backups' ) ) {
            wp_schedule_event( time(), 'hourly', 'bpi_cleanup_expired_backups' );
        }
    }

    /**
     * Plugin deactivation handler.
     *
     * Cleans up transients, temporary files, and scheduled cron events.
     */
    public function deactivate(): void {
        global $wpdb;

        // Delete all BPI queue transients using prepared LIKE queries.
        $patterns = array(
            '_transient_bpi_queue_',
            '_transient_timeout_bpi_queue_',
            '_transient_bpi_batch_',
            '_transient_timeout_bpi_batch_',
            '_transient_bpi_admin_notice_',
            '_transient_timeout_bpi_admin_notice_',
        );

        $where_clauses = array();
        $values        = array();
        foreach ( $patterns as $prefix ) {
            $where_clauses[] = 'option_name LIKE %s';
            $values[]        = $wpdb->esc_like( $prefix ) . '%';
        }

        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE " . implode( ' OR ', $where_clauses ),
                ...$values
            )
        );

        // Remove temporary backup files.
        $backup_dir = WP_CONTENT_DIR . '/bpi-backups';
        if ( is_dir( $backup_dir ) ) {
            $this->recursiveRmdir( $backup_dir );
        }

        // Clean up bpi-tmp upload directory.
        $tmp_dir = wp_upload_dir()['basedir'] . '/bpi-tmp';
        if ( is_dir( $tmp_dir ) ) {
            $this->recursiveRmdir( $tmp_dir );
        }

        // Clear the expired backup cleanup cron.
        wp_clear_scheduled_hook( 'bpi_cleanup_expired_backups' );
    }

    /**
     * Plugin uninstall handler.
     *
     * Removes all options, drops the log table, and deletes backup files.
     */

    /**
     * Recursively remove a directory and its contents.
     *
     * @param string $dir   Path to the directory to remove.
     * @param int    $depth Current recursion depth (safety limit: 50).
     */
    private function recursiveRmdir( string $dir, int $depth = 0 ): void {
        if ( $depth > 50 || ! is_dir( $dir ) ) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator( $dir, \RecursiveDirectoryIterator::SKIP_DOTS ),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        $items->setMaxDepth( 50 - $depth );

        foreach ( $items as $item ) {
            if ( $item->isDir() ) {
                rmdir( $item->getRealPath() );
            } else {
                unlink( $item->getRealPath() );
            }
        }

        rmdir( $dir );
    }
}

// Initialize the plugin (only when running within WordPress).
if ( function_exists( 'add_action' ) ) {
    $bpi_plugin = BulkPluginInstaller::getInstance();
    $bpi_plugin->init();

    // Register activation and deactivation hooks.
    register_activation_hook( __FILE__, array( $bpi_plugin, 'activate' ) );
    register_deactivation_hook( __FILE__, array( $bpi_plugin, 'deactivate' ) );
}

// Register WP-CLI commands when WP-CLI is available.
if ( defined( 'WP_CLI' ) && WP_CLI ) {
    $bpi = BulkPluginInstaller::getInstance();
    $bpi->init();

    $settings_manager = new BPISettingsManager();
    $log_manager      = new BPILogManager();
    $rollback_manager = new BPIRollbackManager();

    $notification_manager = new BPINotificationManager( $settings_manager );
    $batch_rollback_mgr   = new BPIBatchRollbackManager( $rollback_manager, $settings_manager, $log_manager );
    $batch_rollback_mgr->setNotificationManager( $notification_manager );

    $processor = new BPIPluginProcessor( $rollback_manager, $log_manager, $settings_manager );
    $processor->setNotificationManager( $notification_manager );
    $processor->setBatchRollbackManager( $batch_rollback_mgr );

    $bpi_cli = new BPICLIInterface(
        new BPIBulkUploader(),
        new BPIQueueManager(),
        new BPICompatibilityChecker(),
        $processor,
        new BPIProfileManager()
    );
    $bpi_cli->registerCommands();
}
