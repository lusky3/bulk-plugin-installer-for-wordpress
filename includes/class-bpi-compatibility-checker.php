<?php
/**
 * Compatibility Checker for Bulk Plugin Installer.
 *
 * Validates PHP version, WordPress version, and slug conflicts
 * for queued plugins before installation.
 *
 * @package BulkPluginInstaller
 */

// Abort if this file is called directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Checks plugin compatibility against the current environment.
 *
 * Reads `Requires PHP` and `Requires at least` headers from plugin
 * data and compares them against the running PHP and WordPress versions.
 * Also detects slug conflicts where two queued plugins target the same
 * directory.
 *
 * @since 1.0.0
 */
class BPICompatibilityChecker {

    /**
     * Check a single plugin for compatibility issues.
     *
     * Reads `requires_php` and `requires_wp` from the plugin data array
     * and compares against the current environment.
     *
     * @param array $plugin_data Plugin metadata with `requires_php` and `requires_wp` keys.
     * @return array Array of issue arrays, each with 'type', 'required', 'current', and 'message'.
     *
     * @since 1.0.0
     */
    public function checkPlugin( array $plugin_data ): array {
        $issues = array();

        $requires_php = $plugin_data['requires_php'] ?? '';
        if ( '' !== $requires_php && ! $this->checkPhpVersion( $requires_php ) ) {
            $issues[] = array(
                'type'     => 'php_version',
                'required' => $requires_php,
                'current'  => PHP_VERSION,
                'message'  => sprintf(
                    /* translators: 1: required PHP version, 2: current PHP version */
                    __( 'Requires PHP %1$s or higher. Current version: %2$s.', 'bulk-plugin-installer' ),
                    $requires_php,
                    PHP_VERSION
                ),
            );
        }

        $requires_wp = $plugin_data['requires_wp'] ?? '';
        if ( '' !== $requires_wp && ! $this->checkWpVersion( $requires_wp ) ) {
            $current_wp = $this->getWpVersion();
            $issues[]   = array(
                'type'     => 'wp_version',
                'required' => $requires_wp,
                'current'  => $current_wp,
                'message'  => sprintf(
                    /* translators: 1: required WordPress version, 2: current WordPress version */
                    __( 'Requires WordPress %1$s or higher. Current version: %2$s.', 'bulk-plugin-installer' ),
                    $requires_wp,
                    $current_wp
                ),
            );
        }

        return $issues;
    }

    /**
     * Check all queued plugins for compatibility issues.
     *
     * Runs `checkPlugin()` on each item and also checks for slug conflicts.
     * Each queue item gets a `compatibility_issues` key added with any found issues.
     *
     * @param array $queue Array of queue items (plugin data arrays).
     * @return array The queue with `compatibility_issues` populated on each item.
     *
     * @since 1.0.0
     */
    public function checkAll( array $queue ): array {
        $slug_conflicts = $this->checkSlugConflicts( $queue );

        foreach ( $queue as &$item ) {
            $issues = $this->checkPlugin( $item );

            // Merge any slug conflict issues for this item.
            $slug = $item['slug'] ?? '';
            if ( isset( $slug_conflicts[ $slug ] ) ) {
                $issues = array_merge( $issues, $slug_conflicts[ $slug ] );
            }

            $item['compatibility_issues'] = $issues;
        }
        unset( $item );

        return $queue;
    }

    /**
     * Check if the current PHP version meets the requirement.
     *
     * @param string $required Minimum required PHP version string.
     * @return bool True if the current PHP version is >= the required version.
     *
     * @since 1.0.0
     */
    public function checkPhpVersion( string $required ): bool {
        return version_compare( PHP_VERSION, $required, '>=' );
    }

    /**
     * Check if the current WordPress version meets the requirement.
     *
     * @param string $required Minimum required WordPress version string.
     * @return bool True if the current WordPress version is >= the required version.
     *
     * @since 1.0.0
     */
    public function checkWpVersion( string $required ): bool {
        return version_compare( $this->getWpVersion(), $required, '>=' );
    }

    /**
     * Check for slug conflicts in the queue.
     *
     * Detects when two or more queued plugins would install to the same
     * directory (i.e., share the same slug).
     *
     * @param array $queue Array of queue items.
     * @return array Associative array keyed by slug, each value is an array of issue arrays.
     *
     * @since 1.0.0
     */
    public function checkSlugConflicts( array $queue ): array {
        $slug_counts = array();
        $conflicts   = array();

        // Count occurrences of each slug.
        foreach ( $queue as $item ) {
            $slug = $item['slug'] ?? '';
            if ( '' === $slug ) {
                continue;
            }
            if ( ! isset( $slug_counts[ $slug ] ) ) {
                $slug_counts[ $slug ] = 0;
            }
            $slug_counts[ $slug ]++;
        }

        // Build conflict issues for slugs appearing more than once.
        foreach ( $slug_counts as $slug => $count ) {
            if ( $count > 1 ) {
                $issue = array(
                    'type'     => 'slug_conflict',
                    'required' => '',
                    'current'  => '',
                    'slug'     => $slug,
                    'count'    => $count,
                    'message'  => sprintf(
                        /* translators: 1: plugin slug, 2: number of occurrences */
                        __( 'Slug conflict: %1$d plugins would install to the same directory "%2$s".', 'bulk-plugin-installer' ),
                        $count,
                        $slug
                    ),
                );
                $conflicts[ $slug ] = array( $issue );
            }
        }

        return $conflicts;
    }

    /**
     * Get the current WordPress version.
     *
     * @return string WordPress version string.
     */
    private function getWpVersion(): string {
        return get_bloginfo( 'version' );
    }
}
