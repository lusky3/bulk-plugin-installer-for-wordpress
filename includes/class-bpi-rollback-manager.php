<?php
/**
 * Rollback Manager for Bulk Plugin Installer.
 *
 * Handles per-plugin backup and restore for updates, and cleanup
 * of partially installed files for failed new installs. All file
 * operations go through protected methods that use WP_Filesystem
 * in production and can be overridden for testing.
 *
 * @package BulkPluginInstaller
 */

// Abort if this file is called directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Manages plugin directory backup, restore, and cleanup operations.
 *
 * Before an update, the existing plugin directory is copied to a
 * backup location under `wp-content/bpi-backups/`. On failure the
 * backup is restored; on success it is cleaned up.
 *
 * @since 1.0.0
 */
class BPIRollbackManager {

    /**
     * Create a backup of a plugin directory.
     *
     * Copies the entire plugin directory to `wp-content/bpi-backups/`
     * with a unique timestamped name.
     *
     * @param string $plugin_dir Absolute path to the plugin directory.
     * @return string|\WP_Error Backup directory path on success, WP_Error on failure.
     *
     * @since 1.0.0
     */
    public function createBackup( string $plugin_dir ): string|\WP_Error {
        $plugin_dir = rtrim( $plugin_dir, '/\\' );

        if ( ! $this->dirExists( $plugin_dir ) ) {
            return new \WP_Error(
                'backup_source_missing',
                sprintf(
                    /* translators: %s: plugin directory path */
                    __( 'Cannot create backup: source directory "%s" does not exist.', 'bulk-plugin-installer' ),
                    $plugin_dir
                )
            );
        }

        $backup_base = $this->getBackupBaseDir();

        if ( ! $this->dirExists( $backup_base ) && ! $this->mkdir( $backup_base ) ) {
            return new \WP_Error(
                'backup_dir_failed',
                __( 'Cannot create backup directory.', 'bulk-plugin-installer' )
            );
        }

        return $this->performBackupCopy( $plugin_dir, $backup_base );
    }

    /**
     * Perform the actual backup copy operation.
     *
     * @param string $plugin_dir  Source plugin directory.
     * @param string $backup_base Base backup directory.
     * @return string|\WP_Error Backup path on success, WP_Error on failure.
     */
    private function performBackupCopy( string $plugin_dir, string $backup_base ): string|\WP_Error {
        $dir_name    = basename( $plugin_dir );
        $backup_path = trailingslashit( $backup_base ) . $dir_name . '_' . time() . '_' . wp_generate_password( 6, false );

        $copied = $this->copyDir( $plugin_dir, $backup_path );

        if ( ! $copied ) {
            return new \WP_Error(
                'backup_copy_failed',
                sprintf(
                    /* translators: %s: plugin directory name */
                    __( 'Failed to create backup of "%s".', 'bulk-plugin-installer' ),
                    $dir_name
                )
            );
        }

        return $backup_path;
    }

    /**
     * Restore a plugin directory from a backup.
     *
     * Deletes the current plugin directory and copies the backup
     * into its place.
     *
     * @param string $backup_path Absolute path to the backup directory.
     * @param string $plugin_dir  Absolute path to the plugin directory.
     * @return bool|\WP_Error True on success, WP_Error on failure.
     *
     * @since 1.0.0
     */
    public function restoreBackup( string $backup_path, string $plugin_dir ): bool|\WP_Error {
        $backup_path = rtrim( $backup_path, '/\\' );
        $plugin_dir  = rtrim( $plugin_dir, '/\\' );

        if ( ! $this->dirExists( $backup_path ) ) {
            return new \WP_Error(
                'restore_backup_missing',
                sprintf(
                    /* translators: %s: backup path */
                    __( 'Cannot restore: backup directory "%s" does not exist.', 'bulk-plugin-installer' ),
                    $backup_path
                )
            );
        }

        return $this->performRestore( $backup_path, $plugin_dir );
    }

    /**
     * Perform the actual restore operation.
     *
     * @param string $backup_path Backup directory path.
     * @param string $plugin_dir  Target plugin directory path.
     * @return bool|\WP_Error True on success, WP_Error on failure.
     */
    private function performRestore( string $backup_path, string $plugin_dir ): bool|\WP_Error {
        // Remove the current plugin directory if it exists.
        if ( $this->dirExists( $plugin_dir ) && ! $this->deleteDir( $plugin_dir ) ) {
            return new \WP_Error(
                'restore_delete_failed',
                sprintf(
                    /* translators: %s: plugin directory path */
                    __( 'Failed to remove current plugin directory "%s" during restore.', 'bulk-plugin-installer' ),
                    $plugin_dir
                )
            );
        }

        $copied = $this->copyDir( $backup_path, $plugin_dir );

        if ( ! $copied ) {
            return new \WP_Error(
                'restore_copy_failed',
                __( 'Failed to restore plugin from backup.', 'bulk-plugin-installer' )
            );
        }

        return true;
    }

    /**
     * Remove a backup directory after a successful operation.
     *
     * @param string $backup_path Absolute path to the backup directory.
     *
     * @since 1.0.0
     */
    public function cleanupBackup( string $backup_path ): void {
        $backup_path = rtrim( $backup_path, '/\\' );

        if ( $this->dirExists( $backup_path ) ) {
            $this->deleteDir( $backup_path );
        }
    }

    /**
     * Remove a partially installed plugin directory.
     *
     * Used to clean up after a failed new install.
     *
     * @param string $plugin_dir Absolute path to the plugin directory.
     *
     * @since 1.0.0
     */
    public function removePartialInstall( string $plugin_dir ): void {
        $plugin_dir = rtrim( $plugin_dir, '/\\' );

        if ( $this->dirExists( $plugin_dir ) ) {
            $this->deleteDir( $plugin_dir );
        }
    }

    // ------------------------------------------------------------------
    // Protected filesystem methods (overridable for testing)
    // ------------------------------------------------------------------

    /**
     * Get the base directory for backups.
     *
     * @return string Absolute path to the backups directory.
     */
    protected function getBackupBaseDir(): string {
        return WP_CONTENT_DIR . '/bpi-backups';
    }

    /**
     * Check if a directory exists.
     *
     * @param string $path Directory path.
     * @return bool True if the directory exists.
     */
    protected function dirExists( string $path ): bool {
        return is_dir( $path );
    }

    /**
     * Create a directory.
     *
     * @param string $path Directory path.
     * @return bool True on success.
     */
    protected function mkdir( string $path ): bool {
        return wp_mkdir_p( $path );
    }

    /**
     * Recursively copy a directory.
     *
     * @param string $source      Source directory path.
     * @param string $destination Destination directory path.
     * @return bool True on success.
     */
    protected function copyDir( string $source, string $destination ): bool {
        return $this->recursiveCopy( $source, $destination );
    }

    /**
     * Recursively delete a directory.
     *
     * @param string $path Directory path.
     * @return bool True on success.
     */
    protected function deleteDir( string $path ): bool {
        return $this->recursiveDelete( $path );
    }

    // ------------------------------------------------------------------
    // Private helpers
    // ------------------------------------------------------------------

    /**
     * Recursively copy a directory tree.
     *
     * @param string $source      Source path.
     * @param string $destination Destination path.
     * @return bool True on success.
     */
    private function recursiveCopy( string $source, string $destination ): bool {
        if ( ! is_dir( $source ) ) {
            return false;
        }

        if ( ! is_dir( $destination ) && ! wp_mkdir_p( $destination ) ) {
            return false;
        }

        return $this->copyDirectoryContents( $source, $destination );
    }

    /**
     * Copy all contents from source to destination directory.
     *
     * @param string $source      Source directory path.
     * @param string $destination Destination directory path.
     * @return bool True on success.
     */
    private function copyDirectoryContents( string $source, string $destination ): bool {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator( $source, \RecursiveDirectoryIterator::SKIP_DOTS ),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        $iterator->setMaxDepth( 50 );

        foreach ( $iterator as $item ) {
            $target = $destination . DIRECTORY_SEPARATOR . $iterator->getSubPathname();

            if ( $item->isDir() ) {
                if ( ! is_dir( $target ) && ! wp_mkdir_p( $target ) ) {
                    return false;
                }
            } elseif ( ! copy( $item->getPathname(), $target ) ) {
                return false;
            }
        }

        return true;
    }

    /**
     * Recursively delete a directory tree.
     *
     * @param string $path Directory path.
     * @return bool True on success.
     */
    private function recursiveDelete( string $path ): bool {
        if ( ! is_dir( $path ) ) {
            return false;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator( $path, \RecursiveDirectoryIterator::SKIP_DOTS ),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        $iterator->setMaxDepth( 50 );

        foreach ( $iterator as $item ) {
            $success = $item->isDir()
                ? rmdir( $item->getPathname() )
                : unlink( $item->getPathname() );

            if ( ! $success ) {
                return false;
            }
        }

        return rmdir( $path );
    }
}
