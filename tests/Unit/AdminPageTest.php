<?php
/**
 * Unit tests for BPIAdminPage.
 *
 * @package BulkPluginInstaller
 */

use PHPUnit\Framework\TestCase;

/**
 * Class AdminPageTest
 *
 * Tests for the BPIAdminPage class covering menu registration,
 * bulk upload link injection, page rendering, and asset enqueueing.
 */
class AdminPageTest extends TestCase {

    /**
     * Instance under test.
     *
     * @var BPIAdminPage
     */
    private BPIAdminPage $adminPage;

    /**
     * Set up test fixtures.
     */
    protected function setUp(): void {
        parent::setUp();

        global $bpi_test_hooks, $bpi_test_submenu_pages, $bpi_test_user_can;
        global $bpi_test_enqueued_styles, $bpi_test_enqueued_scripts, $bpi_test_localized_scripts;

        $bpi_test_hooks              = array();
        $bpi_test_submenu_pages      = array();
        $bpi_test_user_can           = true;
        $bpi_test_enqueued_styles    = array();
        $bpi_test_enqueued_scripts   = array();
        $bpi_test_localized_scripts  = array();

        $this->adminPage = new BPIAdminPage();
    }

    /**
     * Test that registerHooks adds admin_menu action and plugin_install_action_links filter.
     */
    public function test_register_hooks_adds_admin_menu_action(): void {
        $this->adminPage->registerHooks();

        global $bpi_test_hooks;

        $admin_menu_hooks = array_filter( $bpi_test_hooks, function ( $hook ) {
            return 'action' === $hook['type'] && 'admin_menu' === $hook['hook'];
        } );

        $this->assertNotEmpty( $admin_menu_hooks, 'admin_menu action should be registered' );
    }

    /**
     * Test that registerHooks adds the plugin_install_action_links filter.
     */
    public function test_register_hooks_adds_plugin_install_action_links_filter(): void {
        $this->adminPage->registerHooks();

        global $bpi_test_hooks;

        $filter_hooks = array_filter( $bpi_test_hooks, function ( $hook ) {
            return 'filter' === $hook['type'] && 'plugin_install_action_links' === $hook['hook'];
        } );

        $this->assertNotEmpty( $filter_hooks, 'plugin_install_action_links filter should be registered' );
    }

    /**
     * Test that registerMenu adds a submenu page under plugins.php.
     */
    public function test_register_menu_adds_submenu_page(): void {
        $this->adminPage->registerMenu();

        global $bpi_test_submenu_pages;

        $this->assertArrayHasKey( 'bpi-bulk-upload', $bpi_test_submenu_pages );

        $page = $bpi_test_submenu_pages['bpi-bulk-upload'];
        $this->assertSame( 'plugins.php', $page['parent_slug'] );
        $this->assertSame( 'install_plugins', $page['capability'] );
        $this->assertSame( 'bpi-bulk-upload', $page['menu_slug'] );
    }

    /**
     * Test that registerMenu sets the correct page title.
     */
    public function test_register_menu_page_title(): void {
        $this->adminPage->registerMenu();

        global $bpi_test_submenu_pages;

        $page = $bpi_test_submenu_pages['bpi-bulk-upload'];
        $this->assertSame( 'Bulk Upload Plugins', $page['page_title'] );
        $this->assertSame( 'Bulk Upload', $page['menu_title'] );
    }

    /**
     * Test that registerMenu sets the render callback.
     */
    public function test_register_menu_callback(): void {
        $this->adminPage->registerMenu();

        global $bpi_test_submenu_pages;

        $page = $bpi_test_submenu_pages['bpi-bulk-upload'];
        $this->assertIsCallable( $page['callback'] );
    }

    /**
     * Test that addBulkUploadLink appends a link when user has capability.
     */
    public function test_add_bulk_upload_link_appends_link(): void {
        global $bpi_test_user_can;
        $bpi_test_user_can = true;

        $existing_links = array( '<a href="#">Existing Link</a>' );
        $result = $this->adminPage->addBulkUploadLink( $existing_links );

        $this->assertCount( 2, $result );
        $this->assertSame( '<a href="#">Existing Link</a>', $result[0] );
        $this->assertStringContainsString( 'bpi-bulk-upload', $result[1] );
        $this->assertStringContainsString( 'Bulk Upload', $result[1] );
    }

    /**
     * Test that addBulkUploadLink does not add link when user lacks capability.
     */
    public function test_add_bulk_upload_link_hidden_without_capability(): void {
        global $bpi_test_user_can;
        $bpi_test_user_can = false;

        $existing_links = array( '<a href="#">Existing Link</a>' );
        $result = $this->adminPage->addBulkUploadLink( $existing_links );

        $this->assertCount( 1, $result );
    }

    /**
     * Test that addBulkUploadLink preserves existing links.
     */
    public function test_add_bulk_upload_link_preserves_existing(): void {
        global $bpi_test_user_can;
        $bpi_test_user_can = true;

        $existing_links = array(
            '<a href="#">Link 1</a>',
            '<a href="#">Link 2</a>',
        );
        $result = $this->adminPage->addBulkUploadLink( $existing_links );

        $this->assertCount( 3, $result );
        $this->assertSame( '<a href="#">Link 1</a>', $result[0] );
        $this->assertSame( '<a href="#">Link 2</a>', $result[1] );
    }

    /**
     * Test that addBulkUploadLink generates a proper URL.
     */
    public function test_add_bulk_upload_link_url(): void {
        global $bpi_test_user_can;
        $bpi_test_user_can = true;

        $result = $this->adminPage->addBulkUploadLink( array() );

        $this->assertStringContainsString( 'plugins.php?page=bpi-bulk-upload', $result[0] );
    }

    /**
     * Test that addBulkUploadLink includes the CSS class.
     */
    public function test_add_bulk_upload_link_has_css_class(): void {
        global $bpi_test_user_can;
        $bpi_test_user_can = true;

        $result = $this->adminPage->addBulkUploadLink( array() );

        $this->assertStringContainsString( 'bpi-bulk-upload-link', $result[0] );
    }

    /**
     * Test that renderPage outputs the wrapper div.
     */
    public function test_render_page_outputs_wrapper(): void {
        global $bpi_test_user_can;
        $bpi_test_user_can = true;

        ob_start();
        $this->adminPage->renderPage();
        $output = ob_get_clean();

        $this->assertStringContainsString( 'id="bpi-bulk-upload-wrap"', $output );
        $this->assertStringContainsString( 'class="wrap"', $output );
    }

    /**
     * Test that renderPage outputs the heading.
     */
    public function test_render_page_outputs_heading(): void {
        global $bpi_test_user_can;
        $bpi_test_user_can = true;

        ob_start();
        $this->adminPage->renderPage();
        $output = ob_get_clean();

        $this->assertStringContainsString( '<h1>', $output );
        $this->assertStringContainsString( 'Bulk Upload Plugins', $output );
    }

    /**
     * Test that renderPage outputs the nonce field.
     */
    public function test_render_page_outputs_nonce(): void {
        global $bpi_test_user_can;
        $bpi_test_user_can = true;

        ob_start();
        $this->adminPage->renderPage();
        $output = ob_get_clean();

        $this->assertStringContainsString( 'bpi_bulk_upload_nonce', $output );
    }

    /**
     * Test that renderPage outputs the app container div.
     */
    public function test_render_page_outputs_app_container(): void {
        global $bpi_test_user_can;
        $bpi_test_user_can = true;

        ob_start();
        $this->adminPage->renderPage();
        $output = ob_get_clean();

        $this->assertStringContainsString( 'id="bpi-bulk-upload-app"', $output );
    }

    /**
     * Test that renderPage outputs nothing when user lacks capability.
     */
    public function test_render_page_hidden_without_capability(): void {
        global $bpi_test_user_can;
        $bpi_test_user_can = false;

        ob_start();
        $this->adminPage->renderPage();
        $output = ob_get_clean();

        $this->assertEmpty( $output );
    }

    /**
     * Test that enqueueAssets enqueues CSS on the correct page.
     */
    public function test_enqueue_assets_enqueues_css(): void {
        $this->adminPage->enqueueAssets( 'plugins_page_bpi-bulk-upload' );

        global $bpi_test_enqueued_styles;

        $this->assertArrayHasKey( 'bpi-admin', $bpi_test_enqueued_styles );
        $this->assertStringContainsString( 'bpi-admin', $bpi_test_enqueued_styles['bpi-admin']['src'] );
        $this->assertStringContainsString( '.css', $bpi_test_enqueued_styles['bpi-admin']['src'] );
    }

    /**
     * Test that enqueueAssets enqueues JS on the correct page.
     */
    public function test_enqueue_assets_enqueues_js(): void {
        $this->adminPage->enqueueAssets( 'plugins_page_bpi-bulk-upload' );

        global $bpi_test_enqueued_scripts;

        $this->assertArrayHasKey( 'bpi-admin', $bpi_test_enqueued_scripts );
        $this->assertStringContainsString( 'bpi-admin', $bpi_test_enqueued_scripts['bpi-admin']['src'] );
        $this->assertStringContainsString( '.js', $bpi_test_enqueued_scripts['bpi-admin']['src'] );
    }

    /**
     * Test that enqueueAssets localizes the script with AJAX data.
     */
    public function test_enqueue_assets_localizes_script(): void {
        $this->adminPage->enqueueAssets( 'plugins_page_bpi-bulk-upload' );

        global $bpi_test_localized_scripts;

        $this->assertArrayHasKey( 'bpi-admin', $bpi_test_localized_scripts );
        $this->assertSame( 'bpiAdmin', $bpi_test_localized_scripts['bpi-admin']['object_name'] );
        $this->assertArrayHasKey( 'ajaxUrl', $bpi_test_localized_scripts['bpi-admin']['data'] );
        $this->assertArrayHasKey( 'nonce', $bpi_test_localized_scripts['bpi-admin']['data'] );
    }

    /**
     * Test that enqueueAssets does nothing on a different admin page.
     */
    public function test_enqueue_assets_skips_other_pages(): void {
        $this->adminPage->enqueueAssets( 'settings_page_some-other-plugin' );

        global $bpi_test_enqueued_styles, $bpi_test_enqueued_scripts;

        $this->assertEmpty( $bpi_test_enqueued_styles );
        $this->assertEmpty( $bpi_test_enqueued_scripts );
    }

    /**
     * Test that enqueueAssets sets jQuery as a dependency.
     */
    public function test_enqueue_assets_js_depends_on_jquery(): void {
        $this->adminPage->enqueueAssets( 'plugins_page_bpi-bulk-upload' );

        global $bpi_test_enqueued_scripts;

        $this->assertContains( 'jquery', $bpi_test_enqueued_scripts['bpi-admin']['deps'] );
    }

    /**
     * Test that the MENU_SLUG constant is defined.
     */
    public function test_menu_slug_constant(): void {
        $this->assertSame( 'bpi-bulk-upload', BPIAdminPage::MENU_SLUG );
    }

    /**
     * Test that the NONCE_ACTION constant is defined.
     */
    public function test_nonce_action_constant(): void {
        $this->assertSame( 'bpi_bulk_upload', BPIAdminPage::NONCE_ACTION );
    }

    /**
     * Test that enqueueAssets includes processNonce in localized data.
     */
    public function test_enqueue_assets_includes_process_nonce(): void {
        $this->adminPage->enqueueAssets( 'plugins_page_bpi-bulk-upload' );

        global $bpi_test_localized_scripts;

        $this->assertArrayHasKey( 'bpi-admin', $bpi_test_localized_scripts );
        $data = $bpi_test_localized_scripts['bpi-admin']['data'];
        $this->assertArrayHasKey( 'processNonce', $data );
        $this->assertSame( 'nonce_bpi_process', $data['processNonce'] );
    }

    /**
     * Test that enqueueAssets does not include processNonce on wrong page.
     */
    public function test_enqueue_assets_no_process_nonce_on_wrong_page(): void {
        $this->adminPage->enqueueAssets( 'settings_page_some-other-plugin' );

        global $bpi_test_localized_scripts;

        $this->assertEmpty( $bpi_test_localized_scripts );
    }
}
