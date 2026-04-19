<?php
/**
 * Queue Manager for Bulk Plugin Installer.
 *
 * Manages the upload queue stored in a WordPress transient, keyed per user.
 * Provides methods to add, remove, query, and clear queued plugin items.
 *
 * @package BulkPluginInstaller
 */

// Abort if this file is called directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Manages the plugin upload queue using WordPress transients.
 *
 * Each user gets their own queue stored as a transient keyed by
 * `bpi_queue_{user_id}`. The queue holds validated plugin data
 * ready for preview and processing.
 *
 * @since 1.0.0
 */
class BPIQueueManager {

    /**
     * Transient expiration in seconds (1 hour).
     *
     * @var int
     */
    private const TRANSIENT_EXPIRATION = 3600;

    /**
     * Get the transient key for the current user's queue.
     *
     * @return string Transient key.
     */
    private function getTransientKey(): string {
        return 'bpi_queue_' . get_current_user_id();
    }

    /**
     * Add a validated plugin file to the queue.
     *
     * If a plugin with the same slug already exists in the queue,
     * the existing entry is replaced with the new one (deduplication).
     *
     * @since 1.0.0
     *
     * @param string $file_path   Path to the uploaded ZIP file.
     * @param array  $plugin_data Plugin metadata extracted from the ZIP.
     * @return bool True on success, false on failure.
     */
    public function add( string $file_path, array $plugin_data ): bool {
        $lock_key = $this->getTransientKey() . '_lock';
        $max_wait = 5;
        $waited = 0;
        while ( get_transient( $lock_key ) && $waited < $max_wait ) {
            usleep( 200000 ); // 200ms
            $waited++;
        }
        set_transient( $lock_key, 1, 10 );

        $queue = $this->getAll();
        $slug  = $plugin_data['slug'] ?? '';

        if ( '' === $slug ) {
            delete_transient( $lock_key );
            return false;
        }

        $item = array(
            'slug'               => $slug,
            'file_path'          => $file_path,
            'file_name'          => $plugin_data['file_name'] ?? basename( $file_path ),
            'file_size'          => (int) ( $plugin_data['file_size'] ?? 0 ),
            'plugin_name'        => $plugin_data['plugin_name'] ?? '',
            'plugin_version'     => $plugin_data['plugin_version'] ?? '',
            'plugin_author'      => $plugin_data['plugin_author'] ?? '',
            'plugin_description' => $plugin_data['plugin_description'] ?? '',
            'requires_php'       => $plugin_data['requires_php'] ?? '',
            'requires_wp'        => $plugin_data['requires_wp'] ?? '',
            'action'             => $plugin_data['action'] ?? 'install',
            'installed_version'  => $plugin_data['installed_version'] ?? null,
            'compatibility_issues' => $plugin_data['compatibility_issues'] ?? array(),
            'changelog'          => $plugin_data['changelog'] ?? array(),
            'added_at'           => gmdate( 'c' ),
        );

        // Deduplicate: remove existing entry with the same slug.
        $queue = array_filter( $queue, function ( $existing ) use ( $slug ) {
            return $existing['slug'] !== $slug;
        } );

        // Re-index and append.
        $queue   = array_values( $queue );
        $queue[] = $item;

        $result = set_transient( $this->getTransientKey(), $queue, self::TRANSIENT_EXPIRATION );
        delete_transient( $lock_key );

        return $result;
    }

    /**
     * Remove a plugin from the queue by slug.
     *
     * @since 1.0.0
     *
     * @param string $slug Plugin slug to remove.
     * @return bool True if the item was found and removed, false otherwise.
     */
    public function remove( string $slug ): bool {
        $lock_key = $this->getTransientKey() . '_lock';
        $max_wait = 5;
        $waited = 0;
        while ( get_transient( $lock_key ) && $waited < $max_wait ) {
            usleep( 200000 ); // 200ms
            $waited++;
        }
        set_transient( $lock_key, 1, 10 );

        $queue    = $this->getAll();
        $original = count( $queue );

        $queue = array_filter( $queue, function ( $item ) use ( $slug ) {
            return $item['slug'] !== $slug;
        } );

        $queue = array_values( $queue );

        if ( count( $queue ) === $original ) {
            delete_transient( $lock_key );
            return false;
        }

        if ( empty( $queue ) ) {
            delete_transient( $this->getTransientKey() );
        } else {
            set_transient( $this->getTransientKey(), $queue, self::TRANSIENT_EXPIRATION );
        }

        delete_transient( $lock_key );
        return true;
    }

    /**
     * Get all queued items for the current user.
     *
     * @since 1.0.0
     *
     * @return array Array of queue items.
     */
    public function getAll(): array {
        $queue = get_transient( $this->getTransientKey() );
        return is_array( $queue ) ? $queue : array();
    }

    /**
     * Clear the entire queue for the current user.
     *
     * @since 1.0.0
     */
    public function clear(): void {
        delete_transient( $this->getTransientKey() );
    }

    /**
     * Get the number of items in the queue.
     *
     * @since 1.0.0
     *
     * @return int Number of queued items.
     */
    public function getCount(): int {
        return count( $this->getAll() );
    }

    /**
     * Get the combined file size of all queued items in bytes.
     *
     * @since 1.0.0
     *
     * @return int Total file size in bytes.
     */
    public function getTotalSize(): int {
        $total = 0;
        foreach ( $this->getAll() as $item ) {
            $total += (int) ( $item['file_size'] ?? 0 );
        }
        return $total;
    }

    /**
     * Check if a plugin slug already exists in the queue.
     *
     * @since 1.0.0
     *
     * @param string $slug Plugin slug to check.
     * @return bool True if the slug is already queued.
     */
    public function hasDuplicate( string $slug ): bool {
        foreach ( $this->getAll() as $item ) {
            if ( $item['slug'] === $slug ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Verify nonce and capability for an AJAX request.
     *
     * @param string $nonce_action Nonce action name.
     * @return bool True if verified, false if error response was sent.
     */
    private function verifyAjaxRequest( string $nonce_action ): bool {
        if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( wp_unslash( $_POST['_wpnonce'] ), $nonce_action ) ) {
            wp_send_json_error(
                array( 'message' => __( 'Security verification failed. Please refresh the page and try again.', 'bulk-plugin-installer' ) ),
                403
            );
            return false;
        }

        $required_cap = 'install_plugins';
        if ( function_exists( 'is_multisite' ) && is_multisite()
            && function_exists( 'is_network_admin' ) && is_network_admin() ) {
            $required_cap = 'manage_network_plugins';
        }

        if ( ! current_user_can( $required_cap ) ) {
            wp_send_json_error(
                array( 'message' => __( 'You do not have permission to install plugins.', 'bulk-plugin-installer' ) ),
                403
            );
            return false;
        }

        return true;
    }

    /**
     * AJAX handler for removing a plugin from the queue.
     *
     * Verifies nonce and capability before removing the specified slug.
     * Registered on `wp_ajax_bpi_queue_remove`.
     *
     * @since 1.0.0
     */
    public function handleQueueRemove(): void {
        if ( ! $this->verifyAjaxRequest( 'bpi_queue_remove' ) ) {
            return;
        }

        // Get slug to remove.
        $slug = isset( $_POST['slug'] ) ? sanitize_text_field( wp_unslash( $_POST['slug'] ) ) : '';

        if ( '' === $slug ) {
            wp_send_json_error(
                array( 'message' => __( 'No plugin slug specified.', 'bulk-plugin-installer' ) ),
                400
            );
            return;
        }

        $removed = $this->remove( $slug );

        if ( ! $removed ) {
            wp_send_json_error(
                array( 'message' => __( 'Plugin not found in queue.', 'bulk-plugin-installer' ) ),
                404
            );
            return;
        }

        wp_send_json_success(
            array(
                'message' => sprintf(
                    /* translators: %s: plugin slug */
                    __( "Plugin '%s' removed from queue.", 'bulk-plugin-installer' ),
                    $slug
                ),
                'queue'   => $this->getAll(),
                'count'   => $this->getCount(),
                'size'    => $this->getTotalSize(),
            )
        );
    }
}
