<?php
/**
 * Tests for BPIGithubUpdater.
 *
 * @package BulkPluginInstaller
 */

namespace BPI\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Testable subclass that overrides the HTTP call.
 */
class TestableGithubUpdater extends \BPIGithubUpdater {

    /** @var array|null Simulated API response body (decoded JSON) or null for failure. */
    public ?array $mockApiResponse = null;

    /** @var bool Whether to simulate an HTTP error. */
    public bool $mockHttpError = false;

    /** @var int Simulated HTTP status code. */
    public int $mockStatusCode = 200;

    /**
     * Override: simulate the GitHub API fetch.
     */
    protected function fetchReleaseFromApi(): ?array {
        if ( $this->mockHttpError ) {
            set_transient( self::CACHE_KEY, array( '_error' => true ), self::FAILURE_TTL );
            return null;
        }

        if ( null === $this->mockApiResponse || 200 !== $this->mockStatusCode ) {
            set_transient( self::CACHE_KEY, array( '_error' => true ), self::FAILURE_TTL );
            return null;
        }

        if ( ! is_array( $this->mockApiResponse ) || empty( $this->mockApiResponse['tag_name'] ) ) {
            return null;
        }

        return $this->mockApiResponse;
    }
}

/**
 * Unit tests for BPIGithubUpdater.
 */
class GithubUpdaterTest extends TestCase {

    private TestableGithubUpdater $updater;

    protected function setUp(): void {
        global $bpi_test_options;
        $bpi_test_options = array();

        // Clear transient cache.
        delete_transient( \BPIGithubUpdater::CACHE_KEY );

        $this->updater = new TestableGithubUpdater( 'bulk-plugin-installer/bulk-plugin-installer.php' );
    }

    protected function tearDown(): void {
        global $bpi_test_options;
        $bpi_test_options = array();
        delete_transient( \BPIGithubUpdater::CACHE_KEY );
    }

    // ------------------------------------------------------------------
    // Constructor / resolveBasename
    // ------------------------------------------------------------------

    public function test_constructor_sets_basename_and_slug(): void {
        $updater = new TestableGithubUpdater( 'my-plugin/my-plugin.php' );
        // Test via addViewDetailsLink which uses slug and basename
        $meta = $updater->addViewDetailsLink( array(), 'my-plugin/my-plugin.php' );
        $this->assertCount( 1, $meta );
        $this->assertStringContainsString( 'plugin=my-plugin', $meta[0] );
    }

    public function test_constructor_uses_constant_when_no_basename(): void {
        $updater = new TestableGithubUpdater( '' );
        // BPI_PLUGIN_BASENAME includes the repo directory name
        $meta = $updater->addViewDetailsLink( array(), 'bulk-plugin-installer-for-wordpress/bulk-plugin-installer.php' );
        $this->assertCount( 1, $meta );
    }

    // ------------------------------------------------------------------
    // registerHooks
    // ------------------------------------------------------------------

    public function test_register_hooks_adds_three_filters(): void {
        global $bpi_test_hooks;
        $bpi_test_hooks = array();

        $this->updater->registerHooks();

        $hook_names = array_column( $bpi_test_hooks, 'hook' );
        $this->assertContains( 'pre_set_site_transient_update_plugins', $hook_names );
        $this->assertContains( 'plugins_api', $hook_names );
        $this->assertContains( 'plugin_row_meta', $hook_names );
    }

    // ------------------------------------------------------------------
    // getLatestRelease / caching
    // ------------------------------------------------------------------

    public function test_get_latest_release_returns_null_on_http_error(): void {
        $this->updater->mockHttpError = true;

        $result = $this->updater->getLatestRelease();
        $this->assertNull( $result );
    }

    public function test_get_latest_release_returns_null_on_non_200(): void {
        $this->updater->mockStatusCode = 403;

        $result = $this->updater->getLatestRelease();
        $this->assertNull( $result );
    }

    public function test_get_latest_release_returns_null_for_missing_tag_name(): void {
        $this->updater->mockApiResponse = array( 'body' => 'test' );

        $result = $this->updater->getLatestRelease();
        $this->assertNull( $result );
    }

    public function test_get_latest_release_returns_null_when_no_zip_asset(): void {
        $this->updater->mockApiResponse = array(
            'tag_name' => 'v2.0.0',
            'assets'   => array(),
            // No zipball_url either.
        );

        $result = $this->updater->getLatestRelease();
        $this->assertNull( $result );
    }

    public function test_get_latest_release_returns_data_with_zipball_fallback(): void {
        $this->updater->mockApiResponse = $this->sampleRelease();

        $result = $this->updater->getLatestRelease();

        $this->assertIsArray( $result );
        $this->assertSame( '2.0.0', $result['version'] );
        $this->assertSame( 'https://github.com/zipball/v2.0.0', $result['package'] );
    }

    public function test_get_latest_release_prefers_uploaded_zip_asset(): void {
        $release = $this->sampleRelease();
        $release['assets'] = array(
            array(
                'name'                 => 'bulk-plugin-installer-v2.0.0.zip',
                'browser_download_url' => 'https://github.com/releases/download/v2.0.0/bulk-plugin-installer-v2.0.0.zip',
            ),
        );
        $this->updater->mockApiResponse = $release;

        $result = $this->updater->getLatestRelease();

        $this->assertSame( 'https://github.com/releases/download/v2.0.0/bulk-plugin-installer-v2.0.0.zip', $result['package'] );
    }

    public function test_get_latest_release_caches_result(): void {
        $this->updater->mockApiResponse = $this->sampleRelease();

        // First call fetches.
        $result1 = $this->updater->getLatestRelease();
        $this->assertNotNull( $result1 );

        // Change mock — second call should use cache.
        $this->updater->mockApiResponse = null;
        $result2 = $this->updater->getLatestRelease();

        $this->assertSame( $result1, $result2 );
    }

    public function test_get_latest_release_returns_null_for_cached_error(): void {
        // Simulate cached error.
        set_transient( \BPIGithubUpdater::CACHE_KEY, array( '_error' => true ), 900 );

        $result = $this->updater->getLatestRelease();
        $this->assertNull( $result );
    }

    public function test_clear_cache_removes_cached_data(): void {
        $this->updater->mockApiResponse = $this->sampleRelease();
        $this->updater->getLatestRelease(); // Populates cache.

        $this->updater->clearCache();

        // After clearing, mock failure — should return null (cache gone).
        $this->updater->mockHttpError = true;
        $result = $this->updater->getLatestRelease();
        $this->assertNull( $result );
    }

    // ------------------------------------------------------------------
    // parseReleaseMetadata
    // ------------------------------------------------------------------

    public function test_parses_tested_up_to_from_body(): void {
        $release = $this->sampleRelease();
        $release['body'] = "## Changes\n- Fix stuff\n\nTested up to: 6.9\nRequires at least: 6.0\nRequires PHP: 8.4";
        $this->updater->mockApiResponse = $release;

        $result = $this->updater->getLatestRelease();

        $this->assertSame( '6.9', $result['tested'] );
        $this->assertSame( '6.0', $result['requires'] );
        $this->assertSame( '8.4', $result['requires_php'] );
    }

    public function test_defaults_when_no_metadata_in_body(): void {
        $release = $this->sampleRelease();
        $release['body'] = '- Just a fix';
        $this->updater->mockApiResponse = $release;

        $result = $this->updater->getLatestRelease();

        $this->assertSame( '5.8', $result['requires'] );
        $this->assertSame( '8.3', $result['requires_php'] );
        $this->assertSame( '', $result['tested'] );
    }

    // ------------------------------------------------------------------
    // checkForUpdate
    // ------------------------------------------------------------------

    public function test_check_for_update_injects_when_newer(): void {
        $this->updater->mockApiResponse = $this->sampleRelease();

        $transient = (object) array(
            'checked'  => array( 'bulk-plugin-installer/bulk-plugin-installer.php' => '1.0.0' ),
            'response' => array(),
        );

        $result = $this->updater->checkForUpdate( $transient );

        $this->assertArrayHasKey( 'bulk-plugin-installer/bulk-plugin-installer.php', $result->response );
        $this->assertSame( '2.0.0', $result->response['bulk-plugin-installer/bulk-plugin-installer.php']->new_version );
    }

    public function test_check_for_update_does_not_inject_when_same_version(): void {
        $this->updater->mockApiResponse = $this->sampleRelease();

        $transient = (object) array(
            'checked'  => array( 'bulk-plugin-installer/bulk-plugin-installer.php' => '2.0.0' ),
            'response' => array(),
        );

        $result = $this->updater->checkForUpdate( $transient );

        $this->assertEmpty( $result->response );
    }

    public function test_check_for_update_does_not_inject_when_newer_local(): void {
        $this->updater->mockApiResponse = $this->sampleRelease();

        $transient = (object) array(
            'checked'  => array( 'bulk-plugin-installer/bulk-plugin-installer.php' => '3.0.0' ),
            'response' => array(),
        );

        $result = $this->updater->checkForUpdate( $transient );

        $this->assertEmpty( $result->response );
    }

    public function test_check_for_update_skips_when_no_checked(): void {
        $transient = (object) array( 'response' => array() );

        $result = $this->updater->checkForUpdate( $transient );

        $this->assertEmpty( $result->response ?? array() );
    }

    // ------------------------------------------------------------------
    // pluginInfo
    // ------------------------------------------------------------------

    public function test_plugin_info_returns_object_for_matching_slug(): void {
        $this->updater->mockApiResponse = $this->sampleRelease();

        $args = (object) array( 'slug' => 'bulk-plugin-installer' );
        $result = $this->updater->pluginInfo( false, 'plugin_information', $args );

        $this->assertIsObject( $result );
        $this->assertSame( 'Bulk Plugin Installer', $result->name );
        $this->assertSame( '2.0.0', $result->version );
        $this->assertArrayHasKey( 'description', $result->sections );
        $this->assertArrayHasKey( 'changelog', $result->sections );
    }

    public function test_plugin_info_returns_false_for_wrong_slug(): void {
        $args = (object) array( 'slug' => 'other-plugin' );
        $result = $this->updater->pluginInfo( false, 'plugin_information', $args );

        $this->assertFalse( $result );
    }

    public function test_plugin_info_returns_false_for_wrong_action(): void {
        $args = (object) array( 'slug' => 'bulk-plugin-installer' );
        $result = $this->updater->pluginInfo( false, 'query_plugins', $args );

        $this->assertFalse( $result );
    }

    public function test_plugin_info_returns_false_when_no_release(): void {
        $this->updater->mockHttpError = true;

        $args = (object) array( 'slug' => 'bulk-plugin-installer' );
        $result = $this->updater->pluginInfo( false, 'plugin_information', $args );

        $this->assertFalse( $result );
    }

    // ------------------------------------------------------------------
    // addViewDetailsLink
    // ------------------------------------------------------------------

    public function test_add_view_details_link_for_matching_plugin(): void {
        $meta = array( 'Version 1.0' );
        $result = $this->updater->addViewDetailsLink( $meta, 'bulk-plugin-installer/bulk-plugin-installer.php' );

        $this->assertCount( 2, $result );
        $this->assertStringContainsString( 'thickbox', $result[1] );
        $this->assertStringContainsString( 'View details', $result[1] );
    }

    public function test_add_view_details_link_ignores_other_plugins(): void {
        $meta = array( 'Version 1.0' );
        $result = $this->updater->addViewDetailsLink( $meta, 'other-plugin/other.php' );

        $this->assertCount( 1, $result );
    }

    // ------------------------------------------------------------------
    // Changelog HTML building
    // ------------------------------------------------------------------

    public function test_changelog_html_contains_version_heading(): void {
        $release = $this->sampleRelease();
        $release['body'] = "## Changes\n- Added feature\n- Fixed bug";
        $this->updater->mockApiResponse = $release;

        $args = (object) array( 'slug' => 'bulk-plugin-installer' );
        $info = $this->updater->pluginInfo( false, 'plugin_information', $args );

        $this->assertStringContainsString( '<h4>2.0.0</h4>', $info->sections['changelog'] );
    }

    public function test_changelog_html_renders_list_items(): void {
        $release = $this->sampleRelease();
        $release['body'] = "- Added feature\n- Fixed bug";
        $this->updater->mockApiResponse = $release;

        $args = (object) array( 'slug' => 'bulk-plugin-installer' );
        $info = $this->updater->pluginInfo( false, 'plugin_information', $args );

        $this->assertStringContainsString( '<ul>', $info->sections['changelog'] );
        $this->assertStringContainsString( '<li>Added feature</li>', $info->sections['changelog'] );
        $this->assertStringContainsString( '<li>Fixed bug</li>', $info->sections['changelog'] );
        $this->assertStringContainsString( '</ul>', $info->sections['changelog'] );
    }

    public function test_changelog_html_renders_headings(): void {
        $release = $this->sampleRelease();
        $release['body'] = "## Bug Fixes\n- Fix one";
        $this->updater->mockApiResponse = $release;

        $args = (object) array( 'slug' => 'bulk-plugin-installer' );
        $info = $this->updater->pluginInfo( false, 'plugin_information', $args );

        $this->assertStringContainsString( '<h4>Bug Fixes</h4>', $info->sections['changelog'] );
    }

    public function test_changelog_strips_metadata_lines(): void {
        $release = $this->sampleRelease();
        $release['body'] = "Tested up to: 6.9\nRequires PHP: 8.3\n- Actual change";
        $this->updater->mockApiResponse = $release;

        $args = (object) array( 'slug' => 'bulk-plugin-installer' );
        $info = $this->updater->pluginInfo( false, 'plugin_information', $args );

        $this->assertStringNotContainsString( 'Tested up to', $info->sections['changelog'] );
        $this->assertStringContainsString( 'Actual change', $info->sections['changelog'] );
    }

    public function test_changelog_skips_separator_lines(): void {
        $release = $this->sampleRelease();
        $release['body'] = "- Change\n---\n**Full Changelog**: http://example.com";
        $this->updater->mockApiResponse = $release;

        $args = (object) array( 'slug' => 'bulk-plugin-installer' );
        $info = $this->updater->pluginInfo( false, 'plugin_information', $args );

        $this->assertStringNotContainsString( '---', $info->sections['changelog'] );
        $this->assertStringNotContainsString( 'Full Changelog', $info->sections['changelog'] );
    }

    public function test_changelog_empty_body_shows_no_changelog(): void {
        $release = $this->sampleRelease();
        $release['body'] = '';
        $this->updater->mockApiResponse = $release;

        $args = (object) array( 'slug' => 'bulk-plugin-installer' );
        $info = $this->updater->pluginInfo( false, 'plugin_information', $args );

        $this->assertStringContainsString( 'No changelog available', $info->sections['changelog'] );
    }

    // ------------------------------------------------------------------
    // Description HTML
    // ------------------------------------------------------------------

    public function test_description_html_contains_features(): void {
        $this->updater->mockApiResponse = $this->sampleRelease();

        $args = (object) array( 'slug' => 'bulk-plugin-installer' );
        $info = $this->updater->pluginInfo( false, 'plugin_information', $args );

        $this->assertStringContainsString( 'Drag-and-drop', $info->sections['description'] );
        $this->assertStringContainsString( '<ul>', $info->sections['description'] );
    }

    // ------------------------------------------------------------------
    // version stripping
    // ------------------------------------------------------------------

    public function test_strips_v_prefix_from_tag(): void {
        $release = $this->sampleRelease();
        $release['tag_name'] = 'v3.1.4';
        $this->updater->mockApiResponse = $release;

        $result = $this->updater->getLatestRelease();
        $this->assertSame( '3.1.4', $result['version'] );
    }

    public function test_strips_uppercase_v_prefix(): void {
        $release = $this->sampleRelease();
        $release['tag_name'] = 'V1.2.3';
        $this->updater->mockApiResponse = $release;

        $result = $this->updater->getLatestRelease();
        $this->assertSame( '1.2.3', $result['version'] );
    }

    public function test_handles_tag_without_prefix(): void {
        $release = $this->sampleRelease();
        $release['tag_name'] = '4.0.0';
        $this->updater->mockApiResponse = $release;

        $result = $this->updater->getLatestRelease();
        $this->assertSame( '4.0.0', $result['version'] );
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function sampleRelease(): array {
        return array(
            'tag_name'     => 'v2.0.0',
            'html_url'     => 'https://github.com/lusky3/bulk-plugin-installer/releases/tag/v2.0.0',
            'published_at' => '2026-04-01T12:00:00Z',
            'body'         => "## What's Changed\n- New feature\n- Bug fix",
            'zipball_url'  => 'https://github.com/zipball/v2.0.0',
            'assets'       => array(),
        );
    }

}
