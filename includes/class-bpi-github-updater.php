<?php
/**
 * GitHub-based update checker for the Bulk Plugin Installer.
 *
 * Hooks into WordPress's plugin update system to check for new releases
 * on GitHub and displays changelog information in the Plugins UI.
 *
 * @package BulkPluginInstaller
 */

// Abort if this file is called directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class BPIGithubUpdater
 *
 * Checks the GitHub Releases API for new plugin versions and injects
 * update data into the WordPress transient. Also provides plugin
 * information (including changelog) via the plugins_api filter.
 *
 * @since 1.0.0
 */
class BPIGithubUpdater {

    /**
     * GitHub repository owner/name.
     *
     * @var string
     */
    const GITHUB_REPO = 'lusky3/bulk-plugin-installer';

    /**
     * GitHub API URL for latest release.
     *
     * @var string
     */
    const API_URL = 'https://api.github.com/repos/lusky3/bulk-plugin-installer/releases/latest';

    /**
     * Transient key for caching the GitHub release response.
     *
     * @var string
     */
    const CACHE_KEY = 'bpi_github_release';

    /**
     * Cache duration in seconds (6 hours).
     *
     * @var int
     */
    const CACHE_TTL = 21600;

    /**
     * Short cache duration in seconds (15 minutes) for failed lookups.
     *
     * @var int
     */
    const FAILURE_TTL = 900;

    /**
     * Plugin basename (e.g. bulk-plugin-installer/bulk-plugin-installer.php).
     *
     * @var string
     */
    private string $basename;

    /**
     * Plugin slug (directory name).
     *
     * @var string
     */
    private string $slug;

    /**
     * Constructor.
     *
     * @param string $basename Plugin basename from plugin_basename(__FILE__).
     */
    public function __construct( string $basename = '' ) {
        $this->basename = $this->resolveBasename( $basename );
        $this->slug     = dirname( $this->basename );
    }

    /**
     * Resolve the plugin basename from the argument or constant.
     *
     * @param string $basename Explicit basename or empty string.
     * @return string Resolved basename.
     */
    private function resolveBasename( string $basename ): string {
        if ( '' !== $basename ) {
            return $basename;
        }
        return defined( 'BPI_PLUGIN_BASENAME' ) ? BPI_PLUGIN_BASENAME : '';
    }

    /**
     * Register WordPress hooks for update checking and plugin info display.
     *
     * @since 1.0.0
     */
    public function registerHooks(): void {
        add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'checkForUpdate' ) );
        add_filter( 'plugins_api', array( $this, 'pluginInfo' ), 20, 3 );
        add_filter( 'plugin_row_meta', array( $this, 'addViewDetailsLink' ), 10, 2 );
    }

    /**
     * Check GitHub for a newer release and inject into the update transient.
     *
     * Hooked to `pre_set_site_transient_update_plugins`.
     *
     * @since 1.0.0
     *
     * @param object $transient The update_plugins transient object.
     * @return object Modified transient.
     */
    public function checkForUpdate( $transient ) {
        if ( ! empty( $transient->checked ) ) {
            $this->maybeInjectUpdate( $transient );
        }

        return $transient;
    }

    /**
     * Conditionally inject update data into the transient.
     *
     * @param object $transient The update_plugins transient object (modified by reference).
     */
    private function maybeInjectUpdate( object $transient ): void {
        $local_version = $transient->checked[ $this->basename ] ?? '';
        $release       = $this->getLatestRelease();

        if ( null === $release || '' === $local_version ) {
            return;
        }

        if ( version_compare( $local_version, $release['version'], '<' ) ) {
            $transient->response[ $this->basename ] = (object) array(
                'slug'        => $this->slug,
                'plugin'      => $this->basename,
                'new_version' => $release['version'],
                'url'         => $release['url'],
                'package'     => $release['package'],
                'icons'       => array(),
                'tested'      => $release['tested'],
                'requires'    => $release['requires'],
            );
        }
    }

    /**
     * Provide plugin information for the "View details" modal in the Plugins UI.
     *
     * Hooked to `plugins_api`. Returns an object with plugin metadata,
     * description, changelog, and download link so WordPress can render
     * the standard plugin information thickbox.
     *
     * @since 1.0.0
     *
     * @param false|object|array $result The result object or array. Default false.
     * @param string             $action The API action being performed.
     * @param object             $args   Plugin API arguments.
     * @return false|object Plugin info object or false to defer.
     */
    public function pluginInfo( $result, $action, $args ) {
        if ( 'plugin_information' !== $action || ! isset( $args->slug ) || $args->slug !== $this->slug ) {
            return $result;
        }

        $release = $this->getLatestRelease();
        if ( null === $release ) {
            return $result;
        }

        return $this->buildPluginInfoObject( $release );
    }

    /**
     * Build the stdClass plugin info object from release data.
     *
     * @param array $release Release data from getLatestRelease().
     * @return \stdClass Plugin info object.
     */
    private function buildPluginInfoObject( array $release ): \stdClass {
        $info = new \stdClass();

        $info->name           = 'Bulk Plugin Installer';
        $info->slug           = $this->slug;
        $info->version        = $release['version'];
        $info->author         = '<a href="https://github.com/lusky3">Cody (lusky3)</a>';
        $info->author_profile = 'https://github.com/lusky3';
        $info->homepage       = 'https://github.com/' . self::GITHUB_REPO;
        $info->requires       = $release['requires'];
        $info->tested         = $release['tested'];
        $info->requires_php   = $release['requires_php'];
        $info->download_link  = $release['package'];
        $info->trunk          = $release['package'];
        $info->last_updated   = $release['published_at'];
        $info->banners        = array();

        $info->sections = array(
            'description' => $this->buildDescriptionHtml(),
            'changelog'   => $this->buildChangelogHtml( $release ),
        );

        return $info;
    }

    /**
     * Add a "View details" link to the plugin row meta on the Plugins page.
     *
     * @since 1.0.0
     *
     * @param array  $meta       Array of plugin row meta links.
     * @param string $plugin_file Plugin basename.
     * @return array Modified meta links.
     */
    public function addViewDetailsLink( array $meta, string $plugin_file ): array {
        if ( $plugin_file !== $this->basename ) {
            return $meta;
        }

        $url = self_admin_url(
            'plugin-install.php?tab=plugin-information&plugin=' . $this->slug
            . '&TB_iframe=true&width=600&height=550'
        );

        $meta[] = sprintf(
            '<a href="%s" class="thickbox open-plugin-details-modal" aria-label="%s">%s</a>',
            esc_url( $url ),
            esc_attr( sprintf(
                /* translators: %s: Plugin name. */
                __( 'More information about %s', 'bulk-plugin-installer' ),
                'Bulk Plugin Installer'
            ) ),
            __( 'View details', 'bulk-plugin-installer' )
        );

        return $meta;
    }

    /**
     * Fetch the latest release data from GitHub, with caching.
     *
     * @since 1.0.0
     *
     * @return array|null Release data array or null on failure.
     */
    public function getLatestRelease(): ?array {
        $cached = get_transient( self::CACHE_KEY );
        if ( is_array( $cached ) && ! empty( $cached['_error'] ) ) {
            return null;
        }
        if ( false !== $cached && is_array( $cached ) ) {
            return $cached;
        }

        $body = $this->fetchReleaseFromApi();
        if ( null === $body ) {
            return null;
        }

        return $this->processApiResponse( $body );
    }

    /**
     * Perform the HTTP request to the GitHub Releases API.
     *
     * @return array|null Decoded JSON body or null on failure.
     */
    private function fetchReleaseFromApi(): ?array {
        $user_agent = 'BulkPluginInstaller/' . ( defined( 'BPI_VERSION' ) ? BPI_VERSION : '1.0.0' );

        $response = wp_remote_get(
            self::API_URL,
            array(
                'timeout' => 10,
                'headers' => array(
                    'Accept'     => 'application/vnd.github.v3+json',
                    'User-Agent' => $user_agent,
                ),
            )
        );

        if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
            set_transient( self::CACHE_KEY, array( '_error' => true ), self::FAILURE_TTL );
            return null;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( ! is_array( $body ) || empty( $body['tag_name'] ) ) {
            return null;
        }

        return $body;
    }

    /**
     * Process a valid GitHub API response into release data and cache it.
     *
     * @param array $body Decoded GitHub API release response.
     * @return array|null Release data array or null if no ZIP found.
     */
    private function processApiResponse( array $body ): ?array {
        $package = $this->findZipAsset( $body );
        if ( '' === $package ) {
            return null;
        }

        $published = isset( $body['published_at'] )
            ? gmdate( 'Y-m-d', strtotime( $body['published_at'] ) )
            : '';

        $release_data = array(
            'version'      => ltrim( $body['tag_name'], 'vV' ),
            'url'          => $body['html_url'] ?? ( 'https://github.com/' . self::GITHUB_REPO ),
            'package'      => $package,
            'body'         => $body['body'] ?? '',
            'published_at' => $published,
            'requires'     => '5.8',
            'tested'       => '',
            'requires_php' => '8.2',
        );

        $release_data = $this->parseReleaseMetadata( $release_data );

        set_transient( self::CACHE_KEY, $release_data, self::CACHE_TTL );

        return $release_data;
    }

    /**
     * Find the plugin ZIP asset URL from a GitHub release.
     *
     * Looks for an uploaded asset named bulk-plugin-installer*.zip first,
     * then falls back to the zipball_url (source archive).
     *
     * @param array $body Decoded GitHub API release response.
     * @return string Download URL or empty string.
     */
    private function findZipAsset( array $body ): string {
        if ( ! empty( $body['assets'] ) && is_array( $body['assets'] ) ) {
            foreach ( $body['assets'] as $asset ) {
                $name = $asset['name'] ?? '';
                if ( str_starts_with( $name, 'bulk-plugin-installer' ) && str_ends_with( $name, '.zip' ) ) {
                    return $asset['browser_download_url'] ?? '';
                }
            }
        }

        return $body['zipball_url'] ?? '';
    }

    /**
     * Parse optional metadata from the release body markdown.
     *
     * Supports lines like:
     *   Tested up to: 6.9
     *   Requires at least: 5.8
     *   Requires PHP: 8.2
     *
     * @param array $data Current release data.
     * @return array Updated release data.
     */
    private function parseReleaseMetadata( array $data ): array {
        $body = $data['body'];
        if ( empty( $body ) ) {
            return $data;
        }

        $patterns = array(
            'tested'       => '/Tested up to:\s*([\d.]+)/i',
            'requires'     => '/Requires at least:\s*([\d.]+)/i',
            'requires_php' => '/Requires PHP:\s*([\d.]+)/i',
        );

        foreach ( $patterns as $key => $pattern ) {
            if ( preg_match( $pattern, $body, $m ) ) {
                $data[ $key ] = $m[1];
            }
        }

        return $data;
    }

    /**
     * Build the description HTML for the plugin info modal.
     *
     * @return string HTML description.
     */
    private function buildDescriptionHtml(): string {
        $features = array(
            'Drag-and-drop bulk upload of multiple plugin ZIP files',
            'Preview screen with compatibility checks before installation',
            'Automatic rollback on failed updates',
            'Batch rollback to restore all plugins from a batch operation',
            'Installation profiles for repeatable plugin sets',
            'Dry run mode to simulate installations',
            'WP-CLI integration for command-line workflows',
            'WordPress Multisite and Network Admin support',
            'Email notifications for batch operations',
            'Activity logging with filterable log viewer',
        );

        $html  = '<p>Upload and install multiple WordPress plugin ZIP files in a single operation ';
        $html .= 'with preview, rollback, and profile support.</p>';
        $html .= '<h4>Key Features</h4><ul>';

        foreach ( $features as $feature ) {
            $html .= '<li>' . esc_html( $feature ) . '</li>';
        }

        $html .= '</ul>';

        return $html;
    }

    /**
     * Build the changelog HTML from the GitHub release body (markdown).
     *
     * Converts the release body markdown into simple HTML suitable
     * for the WordPress plugin info modal changelog tab.
     *
     * @param array $release Release data with 'body' and 'version' keys.
     * @return string HTML changelog.
     */
    private function buildChangelogHtml( array $release ): string {
        $body    = $release['body'] ?? '';
        $version = $release['version'] ?? '';

        if ( empty( $body ) ) {
            return '<p>' . esc_html__( 'No changelog available for this release.', 'bulk-plugin-installer' ) . '</p>';
        }

        // Strip metadata lines from display.
        $body = preg_replace( '/^(Tested up to|Requires at least|Requires PHP):\s*[\d.]+\s*$/mi', '', $body );

        $lines   = explode( "\n", $body );
        $parsed  = $this->parseMarkdownLines( $lines );

        return '<h4>' . esc_html( $version ) . '</h4>' . implode( "\n", $parsed );
    }

    /**
     * Parse markdown lines into HTML fragments.
     *
     * Handles headings, list items, and plain text. Skips separator
     * lines and "Full Changelog" links.
     *
     * @param array $lines Array of raw markdown lines.
     * @return array Array of HTML strings.
     */
    private function parseMarkdownLines( array $lines ): array {
        $output  = array();
        $in_list = false;

        foreach ( $lines as $line ) {
            $result  = $this->classifyLine( trim( $line ) );
            $in_list = $this->appendParsedLine( $output, $result, $in_list );
        }

        if ( $in_list ) {
            $output[] = '</ul>';
        }

        return $output;
    }

    /**
     * Append a classified line to the output array.
     *
     * @param array  $output  Output array (modified by reference).
     * @param array  $result  Classified line with 'type' and optional 'content'.
     * @param bool   $in_list Whether we are currently inside a <ul>.
     * @return bool Updated $in_list state.
     */
    private function appendParsedLine( array &$output, array $result, bool $in_list ): bool {
        $type    = $result['type'];
        $content = $result['content'] ?? '';

        // Close list for non-list-item types.
        if ( $in_list && 'list_item' !== $type ) {
            $output[] = '</ul>';
            $in_list  = false;
        }

        $tag_map = array(
            'heading'   => 'h4',
            'list_item' => 'li',
            'text'      => 'p',
        );

        if ( isset( $tag_map[ $type ] ) ) {
            if ( 'list_item' === $type && ! $in_list ) {
                $output[] = '<ul>';
                $in_list  = true;
            }
            $tag       = $tag_map[ $type ];
            $output[]  = '<' . $tag . '>' . esc_html( $content ) . '</' . $tag . '>';
        }

        return $in_list;
    }

    /**
     * Classify a single markdown line by type.
     *
     * @param string $line Trimmed line content.
     * @return array Array with 'type' and optional 'content' keys.
     */
    private function classifyLine( string $line ): array {
        if ( '' === $line ) {
            return array( 'type' => 'empty' );
        }

        $skip_patterns = str_starts_with( $line, '---' ) || str_contains( $line, 'Full Changelog' );
        $type          = 'text';
        $content       = $line;

        if ( $skip_patterns ) {
            $type    = 'skip';
            $content = '';
        } elseif ( preg_match( '/^#{1,4}\s+(.+)$/', $line, $m ) ) {
            $type    = 'heading';
            $content = $m[1];
        } elseif ( preg_match( '/^[-*]\s+(.+)$/', $line, $m ) ) {
            $type    = 'list_item';
            $content = $m[1];
        }

        return array( 'type' => $type, 'content' => $content );
    }

    /**
     * Clear the cached release data.
     *
     * Useful after a plugin update completes.
     *
     * @since 1.0.0
     */
    public function clearCache(): void {
        delete_transient( self::CACHE_KEY );
    }
}
