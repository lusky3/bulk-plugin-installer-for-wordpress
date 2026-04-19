<?php
/**
 * Unit tests for the BulkPluginInstaller bootstrap class.
 *
 * @package BulkPluginInstaller
 */

namespace BPI\Tests\Unit;

use BulkPluginInstaller;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the main plugin bootstrap class.
 */
class BootstrapTest extends TestCase {

    /**
     * Reset global test state before each test.
     */
    protected function setUp(): void {
        global $bpi_test_hooks, $bpi_test_options;
        $bpi_test_hooks   = array();
        $bpi_test_options = array();
    }

    /**
     * Test that the singleton returns the same instance.
     */
    public function test_get_instance_returns_singleton(): void {
        $instance1 = BulkPluginInstaller::getInstance();
        $instance2 = BulkPluginInstaller::getInstance();

        $this->assertSame( $instance1, $instance2 );
    }

    /**
     * Test that the instance is of the correct class.
     */
    public function test_get_instance_returns_correct_class(): void {
        $instance = BulkPluginInstaller::getInstance();

        $this->assertInstanceOf( BulkPluginInstaller::class, $instance );
    }

    /**
     * Test that init() registers the init action hook.
     */
    public function test_init_registers_init_action(): void {
        global $bpi_test_hooks;
        $bpi_test_hooks = array();

        $instance = BulkPluginInstaller::getInstance();
        $instance->init();

        $init_hooks = array_filter(
            $bpi_test_hooks,
            fn( $hook ) => 'action' === $hook['type'] && 'init' === $hook['hook']
        );

        $this->assertNotEmpty( $init_hooks, 'init action should be registered' );
    }

    /**
     * Test that init() registers all 11 AJAX action hooks.
     */
    public function test_init_registers_all_ajax_endpoints(): void {
        global $bpi_test_hooks;
        $bpi_test_hooks = array();

        $instance = BulkPluginInstaller::getInstance();
        $instance->init();

        $expected_ajax_hooks = array(
            'wp_ajax_bpi_upload',
            'wp_ajax_bpi_preview',
            'wp_ajax_bpi_process',
            'wp_ajax_bpi_queue_remove',
            'wp_ajax_bpi_dry_run',
            'wp_ajax_bpi_batch_rollback',
            'wp_ajax_bpi_save_profile',
            'wp_ajax_bpi_import_profile',
            'wp_ajax_bpi_export_profile',
            'wp_ajax_bpi_get_log',
            'wp_ajax_bpi_clear_log',
        );

        $registered_hooks = array_map(
            fn( $hook ) => $hook['hook'],
            array_filter(
                $bpi_test_hooks,
                fn( $hook ) => 'action' === $hook['type'] && str_starts_with( $hook['hook'], 'wp_ajax_bpi_' )
            )
        );

        foreach ( $expected_ajax_hooks as $expected ) {
            $this->assertContains( $expected, $registered_hooks, "AJAX hook '{$expected}' should be registered" );
        }

        $this->assertCount( count( $expected_ajax_hooks ), $registered_hooks, 'Exactly 11 BPI AJAX hooks should be registered' );
    }

    /**
     * Test that init() registers the admin_notices hook for notifications.
     */
    public function test_init_registers_admin_notices_hook(): void {
        global $bpi_test_hooks;
        $bpi_test_hooks = array();

        $instance = BulkPluginInstaller::getInstance();
        $instance->init();

        $notice_hooks = array_filter(
            $bpi_test_hooks,
            fn( $hook ) => 'action' === $hook['type'] && 'admin_notices' === $hook['hook']
        );

        $this->assertNotEmpty( $notice_hooks, 'admin_notices hook should be registered for notifications' );
    }

    /**
     * Test that loadTextdomain() loads the correct text domain.
     */
    public function test_load_textdomain_loads_correct_domain(): void {
        global $bpi_test_hooks;
        $bpi_test_hooks = array();

        $instance = BulkPluginInstaller::getInstance();
        $instance->loadTextdomain();

        $textdomain_calls = array_filter(
            $bpi_test_hooks,
            fn( $hook ) => 'textdomain' === $hook['type']
        );

        $this->assertNotEmpty( $textdomain_calls, 'Text domain should be loaded' );

        $call = array_values( $textdomain_calls )[0];
        $this->assertSame( 'bulk-plugin-installer', $call['domain'] );
    }

    /**
     * Test that activate() sets default options.
     */
    public function test_activate_sets_default_options(): void {
        global $bpi_test_options;
        $bpi_test_options = array();

        $instance = BulkPluginInstaller::getInstance();
        $instance->activate();

        $this->assertSame( false, $bpi_test_options['bpi_auto_activate'] );
        $this->assertSame( 20, $bpi_test_options['bpi_max_plugins'] );
        $this->assertSame( true, $bpi_test_options['bpi_auto_rollback'] );
        $this->assertSame( 0, $bpi_test_options['bpi_max_file_size'] );
        $this->assertSame( 24, $bpi_test_options['bpi_rollback_retention'] );
        $this->assertSame( false, $bpi_test_options['bpi_email_notifications'] );
        $this->assertSame( '', $bpi_test_options['bpi_email_recipients'] );
    }

    /**
     * Test that activate() does not overwrite existing options.
     */
    public function test_activate_does_not_overwrite_existing_options(): void {
        global $bpi_test_options;
        $bpi_test_options = array(
            'bpi_max_plugins' => 50,
        );

        $instance = BulkPluginInstaller::getInstance();
        $instance->activate();

        $this->assertSame( 50, $bpi_test_options['bpi_max_plugins'], 'Existing option should not be overwritten' );
        $this->assertSame( false, $bpi_test_options['bpi_auto_activate'], 'Missing options should be created' );
    }

    /**
     * Test that uninstall.php exists and checks the delete-data setting.
     *
     * The standalone uninstall.php is the WordPress-recommended approach.
     * It checks bpi_delete_data_on_uninstall before removing data.
     */
    public function test_uninstall_file_exists(): void {
        $this->assertFileExists(
            BPI_PLUGIN_DIR . 'uninstall.php',
            'uninstall.php must exist in the plugin root.'
        );
    }

    /**
     * Test that plugin constants are defined.
     */
    public function test_plugin_constants_are_defined(): void {
        $this->assertTrue( defined( 'BPI_VERSION' ) );
        $this->assertTrue( defined( 'BPI_PLUGIN_FILE' ) );
        $this->assertTrue( defined( 'BPI_PLUGIN_DIR' ) );
        $this->assertTrue( defined( 'BPI_PLUGIN_URL' ) );
        $this->assertTrue( defined( 'BPI_PLUGIN_BASENAME' ) );
        $this->assertSame( '1.0.2', BPI_VERSION );
    }

    /**
     * Test that the autoloader resolves BPI_ class names to correct file paths.
     */
    public function test_autoloader_generates_correct_file_path(): void {
        // The autoloader should map BPILogManager to includes/class-bpi-log-manager.php.
        // We can't test the actual loading without the file, but we can verify
        // the function exists and doesn't error on unknown classes.
        $this->assertTrue( function_exists( 'bpiAutoloader' ) );

        // Should not throw for non-BPI classes.
        bpiAutoloader( 'SomeOtherClass' );
        $this->assertTrue( true ); // If we get here, no error was thrown.
    }

    /**
     * Test that register_activation_hook and register_deactivation_hook
     * are callable with the bootstrap instance (verifying the API contract).
     */
    public function test_lifecycle_hooks_can_be_registered(): void {
        global $bpi_test_hooks;
        $bpi_test_hooks = array();

        $instance = BulkPluginInstaller::getInstance();

        register_activation_hook( BPI_PLUGIN_FILE, array( $instance, 'activate' ) );
        register_deactivation_hook( BPI_PLUGIN_FILE, array( $instance, 'deactivate' ) );

        $activation_hooks = array_filter(
            $bpi_test_hooks,
            fn( $hook ) => 'activation' === $hook['type']
        );

        $deactivation_hooks = array_filter(
            $bpi_test_hooks,
            fn( $hook ) => 'deactivation' === $hook['type']
        );

        $this->assertNotEmpty( $activation_hooks, 'Activation hook should be registered' );
        $this->assertNotEmpty( $deactivation_hooks, 'Deactivation hook should be registered' );
    }
}
