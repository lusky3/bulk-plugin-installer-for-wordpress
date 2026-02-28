<?php
/**
 * Plugin Name:       Bulk Plugin Installer
 * Plugin URI:        https://github.com/lusky3/bulk-plugin-installer
 * Description:       Upload and install multiple WordPress plugin ZIP files in a single operation with preview, rollback, and profile support.
 * Version:           1.0.1
 * Requires at least: 5.8
 * Requires PHP:      8.2
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
define( 'BPI_VERSION', '1.0.1' );
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

        // Instantiate shared dependencies.
        $settings_manager = new BPISettingsManager();
        $log_manager      = new BPILogManager();
        $rollback_manager = new BPIRollbackManager();

        // Notification manager.
        $notification_manager = new BPINotificationManager( $settings_manager );
        $notification_manager->registerHooks();

        // Admin page: registers admin_menu, plugin_install_action_links, and wp_ajax_bpi_preview.
        $admin_page = new BPIAdminPage();
        $admin_page->registerHooks();

        // Settings manager: registers settings page (must run on admin_init).
        add_action( 'admin_init', array( $settings_manager, 'registerSettings' ) );

        // Bulk uploader: wp_ajax_bpi_upload.
        $bulk_uploader = new BPIBulkUploader();
        add_action( 'wp_ajax_bpi_upload', array( $bulk_uploader, 'handleUpload' ) );

        // Queue manager: wp_ajax_bpi_queue_remove.
        $queue_manager = new BPIQueueManager();
        add_action( 'wp_ajax_bpi_queue_remove', array( $queue_manager, 'handleQueueRemove' ) );

        // Plugin processor: wp_ajax_bpi_process, wp_ajax_bpi_dry_run.
        $plugin_processor = new BPIPluginProcessor( $rollback_manager, $log_manager, $settings_manager );
        $plugin_processor->registerAjaxHandler();

        // Batch rollback manager: wp_ajax_bpi_batch_rollback.
        $batch_rollback_manager = new BPIBatchRollbackManager( $rollback_manager, $settings_manager, $log_manager );
        $batch_rollback_manager->registerAjaxHandler();

        // Wire notification manager into processor and batch rollback.
        $plugin_processor->setNotificationManager( $notification_manager );
        $plugin_processor->setBatchRollbackManager( $batch_rollback_manager );
        $batch_rollback_manager->setNotificationManager( $notification_manager );

        // Profile manager: wp_ajax_bpi_save_profile, wp_ajax_bpi_import_profile, wp_ajax_bpi_export_profile.
        $profile_manager = new BPIProfileManager();
        $profile_manager->registerAjaxHandlers();

        // Log manager AJAX: wp_ajax_bpi_get_log, wp_ajax_bpi_clear_log.
        add_action( 'wp_ajax_bpi_get_log', array( $log_manager, 'handleGetLog' ) );
        add_action( 'wp_ajax_bpi_clear_log', array( $log_manager, 'handleClearLog' ) );

        // GitHub update checker: notifies of new versions and shows changelog in Plugins UI.
        $github_updater = new BPIGithubUpdater();
        $github_updater->registerHooks();

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
        if ( class_exists( 'BPIAdminPage' ) ) {
            $admin_page = new BPIAdminPage();
            $admin_page->registerNetworkMenu();
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
     * Creates the log database table and sets default option values.
     */
    public function activate(): void {
        // Create the activity log table.
        if ( class_exists( 'BPILogManager' ) ) {
            $log_manager = new BPILogManager();
            $log_manager->createTable();
        }

        // Set default options if they don't already exist.
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
                add_option( $key, $value );
            }
        }
    }

    /**
     * Plugin deactivation handler.
     *
     * Cleans up transients and temporary files.
     */
    public function deactivate(): void {
        // Clean up user queue transients.
        global $wpdb;

        // Delete all BPI queue transients.
        $wpdb->query(
            "DELETE FROM {$wpdb->options}
             WHERE option_name LIKE '_transient_bpi_queue_%'
                OR option_name LIKE '_transient_timeout_bpi_queue_%'
                OR option_name LIKE '_transient_bpi_batch_%'
                OR option_name LIKE '_transient_timeout_bpi_batch_%'
                OR option_name LIKE '_transient_bpi_admin_notice_%'
                OR option_name LIKE '_transient_timeout_bpi_admin_notice_%'"
        );

        // Remove temporary backup files.
        $backup_dir = WP_CONTENT_DIR . '/bpi-backups';
        if ( is_dir( $backup_dir ) ) {
            $this->recursiveRmdir( $backup_dir );
        }
    }

    /**
     * Plugin uninstall handler.
     *
     * Removes all options, drops the log table, and deletes backup files.
     */

    /**
     * Recursively remove a directory and its contents.
     *
     * @param string $dir Path to the directory to remove.
     */
    private function recursiveRmdir( string $dir ): void {
        if ( ! is_dir( $dir ) ) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator( $dir, \RecursiveDirectoryIterator::SKIP_DOTS ),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

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
    $bpi_cli = new BPICLIInterface(
        new BPIBulkUploader(),
        new BPIQueueManager(),
        new BPICompatibilityChecker(),
        new BPIPluginProcessor(
            new BPIRollbackManager(),
            new BPILogManager(),
            new BPISettingsManager()
        ),
        new BPIProfileManager()
    );
    $bpi_cli->registerCommands();
}
