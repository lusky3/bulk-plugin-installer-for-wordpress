<?php
/**
 * CLI Interface for Bulk Plugin Installer.
 *
 * Provides WP-CLI integration for bulk plugin operations from the command line.
 * Registers `wp bulk-plugin install` command with support for file paths,
 * profiles, dry-run mode, and non-interactive confirmation.
 *
 * @package BulkPluginInstaller
 */

// Abort if this file is called directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * WP-CLI command handler for bulk plugin installation.
 *
 * Integrates with BPIBulkUploader, BPIQueueManager, BPICompatibilityChecker,
 * BPIPluginProcessor, and BPIProfileManager to provide CLI-based bulk operations.
 *
 * @since 1.0.0
 */
class BPICLIInterface {

    /**
     * Bulk uploader instance.
     *
     * @var BPIBulkUploader
     */
    private BPIBulkUploader $uploader;

    /**
     * Queue manager instance.
     *
     * @var BPIQueueManager
     */
    private BPIQueueManager $queue;

    /**
     * Compatibility checker instance.
     *
     * @var BPICompatibilityChecker
     */
    private BPICompatibilityChecker $checker;

    /**
     * Plugin processor instance.
     *
     * @var BPIPluginProcessor
     */
    private BPIPluginProcessor $processor;

    /**
     * Profile manager instance.
     *
     * @var BPIProfileManager
     */
    private BPIProfileManager $profiles;


    /**
     * Constructor.
     *
     * @param BPIBulkUploader        $uploader  Bulk uploader.
     * @param BPIQueueManager        $queue     Queue manager.
     * @param BPICompatibilityChecker $checker  Compatibility checker.
     * @param BPIPluginProcessor     $processor Plugin processor.
     * @param BPIProfileManager      $profiles  Profile manager.
     */
    public function __construct(
        BPIBulkUploader $uploader,
        BPIQueueManager $queue,
        BPICompatibilityChecker $checker,
        BPIPluginProcessor $processor,
        BPIProfileManager $profiles
    ) {
        $this->uploader  = $uploader;
        $this->queue     = $queue;
        $this->checker   = $checker;
        $this->processor = $processor;
        $this->profiles  = $profiles;
    }

    /**
     * Register WP-CLI commands.
     *
     * Registers the `wp bulk-plugin install` command with WP-CLI.
     *
     * @since 1.0.0
     */
    public function registerCommands(): void {
        if ( ! class_exists( 'WP_CLI' ) ) {
            return;
        }

        \WP_CLI::add_command( 'bulk-plugin install', array( $this, 'install' ), array(
            'shortdesc' => __( 'Install or update multiple plugins from ZIP files.', 'bulk-plugin-installer' ),
            'synopsis'  => array(
                array(
                    'type'        => 'positional',
                    'name'        => 'file',
                    'description' => __( 'One or more paths to plugin ZIP files.', 'bulk-plugin-installer' ),
                    'optional'    => true,
                    'repeating'   => true,
                ),
                array(
                    'type'        => 'assoc',
                    'name'        => 'profile',
                    'description' => __( 'Load a saved plugin profile by name.', 'bulk-plugin-installer' ),
                    'optional'    => true,
                ),
                array(
                    'type'        => 'flag',
                    'name'        => 'dry-run',
                    'description' => __( 'Simulate installation without making changes.', 'bulk-plugin-installer' ),
                    'optional'    => true,
                ),
                array(
                    'type'        => 'flag',
                    'name'        => 'yes',
                    'description' => __( 'Skip confirmation prompt.', 'bulk-plugin-installer' ),
                    'optional'    => true,
                ),
            ),
        ) );
    }

    /**
     * Handle the `wp bulk-plugin install` command.
     *
     * Validates file paths, uploads to queue, displays preview table,
     * prompts for confirmation, processes plugins, and outputs summary.
     *
     * @since 1.0.0
     *
     * @param array $args       Positional arguments (file paths).
     * @param array $assoc_args Associative arguments (--profile, --dry-run, --yes).
     */
    public function install( array $args, array $assoc_args ): void {
        $dry_run      = isset( $assoc_args['dry-run'] );
        $skip_confirm = isset( $assoc_args['yes'] );
        $profile_name = $assoc_args['profile'] ?? '';

        $plugins = $this->resolvePlugins( $args, $profile_name );
        if ( null === $plugins ) {
            return;
        }

        // Run compatibility checks.
        $plugins = $this->checker->checkAll( $plugins );

        // Display preview table.
        $this->displayPreviewTable( $plugins );

        // Prompt for confirmation unless --yes is set.
        if ( ! $skip_confirm && ! $dry_run ) {
            \WP_CLI::line( '' );
            \WP_CLI::line(
                sprintf(
                    /* translators: %d: number of plugins */
                    __( 'About to process %d plugin(s).', 'bulk-plugin-installer' ),
                    count( $plugins )
                )
            );
            \WP_CLI::line( __( 'Use --yes to skip this prompt.', 'bulk-plugin-installer' ) );
            \WP_CLI::halt( 0 );
            return;
        }

        if ( $dry_run ) {
            \WP_CLI::line( '' );
            \WP_CLI::log( __( 'Running in dry-run mode. No changes will be made.', 'bulk-plugin-installer' ) );
        }

        // Process plugins.
        $exit_code = $this->processWithProgress( $plugins, $dry_run );

        \WP_CLI::halt( $exit_code );
    }

    /**
     * Resolve plugins from file arguments or a profile name.
     *
     * @param array  $args         Positional arguments (file paths).
     * @param string $profile_name Profile name to load (empty for file-based).
     * @return array|null Array of plugin data, or null on failure.
     */
    private function resolvePlugins( array $args, string $profile_name ): ?array {
        if ( '' !== $profile_name ) {
            return $this->loadFromProfile( $profile_name );
        }

        $plugins = ! empty( $args ) ? $this->validateFiles( $args ) : null;
        if ( empty( $plugins ) ) {
            $message = empty( $args )
                ? __( 'No plugin ZIP files specified.', 'bulk-plugin-installer' )
                : __( 'No valid plugins to process.', 'bulk-plugin-installer' );
            \WP_CLI::error( $message );
            \WP_CLI::halt( 2 );
            return null;
        }

        return $plugins;
    }


    /**
     * Display a preview table of plugins to be processed.
     *
     * Shows columns: Name, Version, Action, Installed Version.
     *
     * @since 1.0.0
     *
     * @param array $plugins Array of plugin data arrays.
     */
    public function displayPreviewTable( array $plugins ): void {
        $items = array();

        foreach ( $plugins as $plugin ) {
            $action = ( 'update' === ( $plugin['action'] ?? 'install' ) )
                ? __( 'Update', 'bulk-plugin-installer' )
                : __( 'New Install', 'bulk-plugin-installer' );

            $installed_version = $plugin['installed_version'] ?? '';
            if ( '' === $installed_version || null === $installed_version ) {
                $installed_version = __( '—', 'bulk-plugin-installer' );
            }

            $items[] = array(
                'Name'              => $plugin['plugin_name'] ?? $plugin['slug'] ?? __( 'Unknown', 'bulk-plugin-installer' ),
                'Version'           => $plugin['plugin_version'] ?? __( 'Unknown', 'bulk-plugin-installer' ),
                'Action'            => $action,
                'Installed Version' => $installed_version,
            );
        }

        \WP_CLI\Utils\format_items( 'table', $items, array( 'Name', 'Version', 'Action', 'Installed Version' ) );
    }

    /**
     * Process plugins with a progress bar and per-plugin status output.
     *
     * @since 1.0.0
     *
     * @param array $plugins Array of plugin data arrays.
     * @param bool  $dry_run Whether to simulate without making changes.
     * @return int Exit code: 0 = all success, 1 = partial failures, 2 = all failed.
     */
    public function processWithProgress( array $plugins, bool $dry_run ): int {
        $total     = count( $plugins );
        $successes = 0;
        $failures  = 0;

        $progress = \WP_CLI\Utils\make_progress_bar(
            sprintf(
                /* translators: %d: number of plugins */
                __( 'Processing %d plugin(s)', 'bulk-plugin-installer' ),
                $total
            ),
            $total
        );

        $results = $this->processor->processBatch( $plugins, $dry_run );

        foreach ( $results as $result ) {
            $progress->tick();

            if ( 'success' === ( $result['status'] ?? 'failed' ) ) {
                $successes++;
            } else {
                $failures++;
            }

            $this->logResultLine( $result, $dry_run );
        }

        $progress->finish();

        // Display summary.
        \WP_CLI::line( '' );
        \WP_CLI::line(
            sprintf(
                /* translators: 1: successes, 2: failures, 3: total */
                __( 'Summary: %1$d succeeded, %2$d failed, %3$d total.', 'bulk-plugin-installer' ),
                $successes,
                $failures,
                $total
            )
        );

        // Determine exit code.
        if ( $failures === 0 ) {
            return 0;
        }
        return $successes > 0 ? 1 : 2;
    }

    /**
     * Log a single plugin result to the CLI output.
     *
     * @param array $result  Plugin processing result.
     * @param bool  $dry_run Whether this is a dry run.
     */
    private function logResultLine( array $result, bool $dry_run ): void {
        $name   = $result['plugin_name'] ?? $result['slug'] ?? __( 'Unknown', 'bulk-plugin-installer' );
        $status = $result['status'] ?? 'failed';

        if ( 'success' === $status ) {
            $action_label = $result['action'] ?? 'install';
            if ( $dry_run ) {
                \WP_CLI::log(
                    sprintf(
                        /* translators: 1: action, 2: plugin name */
                        __( 'Dry run: would %1$s "%2$s".', 'bulk-plugin-installer' ),
                        $action_label,
                        $name
                    )
                );
            } else {
                \WP_CLI::success(
                    sprintf(
                        /* translators: 1: plugin name, 2: action */
                        __( '%1$s — %2$s successful.', 'bulk-plugin-installer' ),
                        $name,
                        $action_label
                    )
                );
            }
            return;
        }

        $messages = $result['messages'] ?? array();
        $reason   = ! empty( $messages ) ? implode( ' ', $messages ) : __( 'Unknown error.', 'bulk-plugin-installer' );

        if ( 'incompatible' === $status ) {
            $reason = ! empty( $messages ) ? implode( ' ', $messages ) : __( 'Incompatible.', 'bulk-plugin-installer' );
        }

        $label = 'incompatible' === $status ? 'skipped' : 'failed';
        if ( ! empty( $result['rolled_back'] ) ) {
            $label = 'failed and rolled back';
        }

        \WP_CLI::warning(
            sprintf(
                /* translators: 1: plugin name, 2: label, 3: reason */
                '%1$s — %2$s: %3$s',
                $name,
                $label,
                $reason
            )
        );
    }


    /**
     * Validate file paths and build plugin data arrays.
     *
     * @param array $file_paths Array of file path strings.
     * @return array|null Array of plugin data, or null on complete failure.
     */
    private function validateFiles( array $file_paths ): ?array {
        $plugins = array();

        foreach ( $file_paths as $path ) {
            $plugin = $this->validateSingleFile( $path );
            if ( null !== $plugin ) {
                $plugins[] = $plugin;
            }
        }

        if ( empty( $plugins ) ) {
            \WP_CLI::error( __( 'No valid plugin ZIP files found.', 'bulk-plugin-installer' ) );
            \WP_CLI::halt( 2 );
            return null;
        }

        return $plugins;
    }

    /**
     * Validate a single ZIP file and extract plugin data.
     *
     * @param string $path File path to validate.
     * @return array|null Plugin data array or null if invalid.
     */
    private function validateSingleFile( string $path ): ?array {
        if ( ! file_exists( $path ) ) {
            \WP_CLI::warning(
                sprintf(
                    /* translators: %s: file path */
                    __( 'File not found: %s', 'bulk-plugin-installer' ),
                    $path
                )
            );
            return null;
        }

        $validation = $this->uploader->validateZip( $path );
        if ( is_wp_error( $validation ) ) {
            \WP_CLI::warning(
                sprintf(
                    /* translators: 1: file path, 2: error message */
                    __( 'Invalid file "%1$s": %2$s', 'bulk-plugin-installer' ),
                    basename( $path ),
                    $validation->get_error_message()
                )
            );
            return null;
        }

        return $this->buildPluginDataFromFile( $path );
    }

    /**
     * Build plugin data array from a validated ZIP file.
     *
     * @param string $path Path to the validated ZIP file.
     * @return array|null Plugin data array or null if no valid header found.
     */
    private function buildPluginDataFromFile( string $path ): ?array {
        $headers = $this->uploader->extractPluginHeaders( $path );
        if ( empty( $headers['plugin_name'] ) ) {
            \WP_CLI::warning(
                sprintf(
                    /* translators: %s: file path */
                    __( 'No valid plugin header found in "%s".', 'bulk-plugin-installer' ),
                    basename( $path )
                )
            );
            return null;
        }

        $slug = $this->uploader->getPluginSlug( $path );

        // Determine action (install vs update).
        $installed_plugins = function_exists( 'get_plugins' ) ? get_plugins() : array();
        $action            = 'install';
        $installed_version = null;
        $plugin_file       = $slug . '/' . $slug . '.php';

        foreach ( $installed_plugins as $file => $data ) {
            if ( strpos( $file, $slug . '/' ) === 0 ) {
                $action            = 'update';
                $installed_version = $data['Version'] ?? null;
                $plugin_file       = $file;
                break;
            }
        }

        return array(
            'slug'              => $slug,
            'file_path'         => $path,
            'file_name'         => basename( $path ),
            'file_size'         => filesize( $path ),
            'plugin_name'       => $headers['plugin_name'] ?? '',
            'plugin_version'    => $headers['plugin_version'] ?? '',
            'plugin_author'     => $headers['plugin_author'] ?? '',
            'plugin_description' => $headers['plugin_description'] ?? '',
            'requires_php'      => $headers['requires_php'] ?? '',
            'requires_wp'       => $headers['requires_wp'] ?? '',
            'action'            => $action,
            'installed_version' => $installed_version,
            'plugin_file'       => $plugin_file,
        );
    }

    /**
     * Load plugins from a saved profile.
     *
     * @param string $profile_name Profile name to load.
     * @return array|null Array of plugin data, or null on failure.
     */
    private function loadFromProfile( string $profile_name ): ?array {
        $all_profiles = $this->profiles->getAllProfiles();
        $profile      = null;

        foreach ( $all_profiles as $p ) {
            if ( ( $p['name'] ?? '' ) === $profile_name ) {
                $profile = $p;
                break;
            }
        }

        if ( null === $profile ) {
            \WP_CLI::error(
                sprintf(
                    /* translators: %s: profile name */
                    __( "Profile '%s' not found.", 'bulk-plugin-installer' ),
                    $profile_name
                )
            );
            \WP_CLI::halt( 2 );
            return null;
        }

        $profile_plugins = $profile['plugins'] ?? array();
        if ( empty( $profile_plugins ) ) {
            \WP_CLI::error(
                sprintf(
                    /* translators: %s: profile name */
                    __( "Profile '%s' contains no plugins.", 'bulk-plugin-installer' ),
                    $profile_name
                )
            );
            \WP_CLI::halt( 2 );
            return null;
        }

        $plugins = array();
        $installed_plugins = function_exists( 'get_plugins' ) ? get_plugins() : array();
        foreach ( $profile_plugins as $pp ) {
            $slug = $pp['slug'] ?? '';
            $name = $pp['name'] ?? $slug;
            $version = $pp['version'] ?? '';

            // Determine action (install vs update).
            $action            = 'install';
            $installed_version = null;
            $plugin_file       = $slug . '/' . $slug . '.php';

            foreach ( $installed_plugins as $file => $data ) {
                if ( strpos( $file, $slug . '/' ) === 0 ) {
                    $action            = 'update';
                    $installed_version = $data['Version'] ?? null;
                    $plugin_file       = $file;
                    break;
                }
            }

            $plugins[] = array(
                'slug'              => $slug,
                'file_path'         => '',
                'file_name'         => '',
                'file_size'         => 0,
                'plugin_name'       => $name,
                'plugin_version'    => $version,
                'plugin_author'     => '',
                'plugin_description' => '',
                'requires_php'      => '',
                'requires_wp'       => '',
                'action'            => $action,
                'installed_version' => $installed_version,
                'plugin_file'       => $plugin_file,
            );
        }

        \WP_CLI::log(
            sprintf(
                /* translators: 1: count, 2: profile name */
                __( 'Loaded %1$d plugin(s) from profile "%2$s".', 'bulk-plugin-installer' ),
                count( $plugins ),
                $profile_name
            )
        );

        return $plugins;
    }
}
