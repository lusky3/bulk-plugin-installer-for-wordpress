<?php
/**
 * Bulk Uploader for Bulk Plugin Installer.
 *
 * Handles file reception, ZIP validation, plugin header extraction,
 * path traversal detection, and security checks for uploaded plugin archives.
 *
 * @package BulkPluginInstaller
 */

// Abort if this file is called directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Handles bulk plugin ZIP file uploads with validation and security.
 *
 * Provides AJAX upload handling, ZIP structure validation, WordPress plugin
 * header extraction, path traversal detection, and slug determination.
 * All uploads are verified with nonce and capability checks.
 */
class BPIBulkUploader {

    /**
     * Standard WordPress plugin headers to extract.
     *
     * @var array<string, string>
     */
    private const PLUGIN_HEADERS = array(
        'Plugin Name'    => 'plugin_name',
        'Version'        => 'version',
        'Author'         => 'author',
        'Description'    => 'description',
        'Requires PHP'   => 'requires_php',
        'Requires at least' => 'requires_wp',
    );

    /**
     * Verify nonce and capability for an AJAX upload request.
     *
     * @return bool True if verified, false if error response was sent.
     */
    private function verifyAjaxRequest(): bool {
        if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( wp_unslash( $_POST['_wpnonce'] ), 'bpi_upload' ) ) {
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
     * AJAX handler for file upload.
     *
     * Verifies nonce and capability, validates the uploaded file,
     * and returns plugin data on success or error on failure.
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     */
    public function handleUpload(): void {
        if ( ! $this->verifyAjaxRequest() ) {
            return;
        }

        $upload_error = $this->validateUploadedFile();
        if ( null !== $upload_error ) {
            wp_send_json_error( array( 'message' => $upload_error ), 400 );
            return;
        }

        $file      = $_FILES['plugin_zip'];
        $file_path = $file['tmp_name'];
        $file_name = sanitize_text_field( $file['name'] ?? '' );
        $file_size = (int) ( $file['size'] ?? 0 );

        // Validate ZIP.
        $validation = $this->validateZip( $file_path );

        if ( is_wp_error( $validation ) ) {
            wp_send_json_error(
                array(
                    'message' => sprintf(
                        /* translators: 1: file name, 2: error message */
                        __( "File '%1\$s': %2\$s", 'bulk-plugin-installer' ),
                        $file_name,
                        $validation->get_error_message()
                    ),
                ),
                400
            );
            return;
        }

        // Extract plugin headers.
        $headers = $this->extractPluginHeaders( $file_path );
        $slug    = $this->getPluginSlug( $file_path );

        // Move uploaded file to a persistent temp location so it survives the request.
        $upload_dir  = wp_upload_dir();
        $bpi_tmp_dir = trailingslashit( $upload_dir['basedir'] ) . 'bpi-tmp/';
        wp_mkdir_p( $bpi_tmp_dir );

        $dest_path = $bpi_tmp_dir . sanitize_file_name( $slug . '-' . time() . '.zip' );
        if ( ! move_uploaded_file( $file_path, $dest_path ) && ! copy( $file_path, $dest_path ) ) {
            wp_send_json_error(
                array( 'message' => __( 'Failed to save uploaded file.', 'bulk-plugin-installer' ) ),
                500
            );
            return;
        }

        // Determine install vs update.
        if ( ! function_exists( 'get_plugins' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php'; // phpcs:ignore PHPMD -- WordPress core file, no namespace available.
        }
        $installed_plugins = get_plugins();
        $action            = 'install';
        $installed_version = null;
        foreach ( $installed_plugins as $pfile => $pinfo ) {
            if ( dirname( $pfile ) === $slug ) {
                $action            = 'update';
                $installed_version = $pinfo['Version'] ?? '';
                break;
            }
        }

        // Add to the queue.
        $queue_manager = new BPIQueueManager();
        $was_duplicate = $queue_manager->hasDuplicate( $slug );
        $queue_manager->add( $dest_path, array(
            'slug'              => $slug,
            'file_name'         => $file_name,
            'file_size'         => $file_size,
            'plugin_name'       => $headers['plugin_name'] ?? '',
            'plugin_version'    => $headers['version'] ?? '',
            'plugin_author'     => $headers['author'] ?? '',
            'plugin_description' => $headers['description'] ?? '',
            'requires_php'      => $headers['requires_php'] ?? '',
            'requires_wp'       => $headers['requires_wp'] ?? '',
            'action'            => $action,
            'installed_version' => $installed_version,
        ) );

        wp_send_json_success(
            array(
                'slug'          => $slug,
                'file_name'     => $file_name,
                'file_size'     => $file_size,
                'headers'       => $headers,
                'action'        => $action,
                'was_duplicate' => $was_duplicate,
                'queue_count'   => $queue_manager->getCount(),
                'queue_size'    => $queue_manager->getTotalSize(),
            )
        );
    }

    /**
     * Validate that a file was uploaded and meets size constraints.
     *
     * @return string|null Error message if invalid, null if OK.
     */
    private function validateUploadedFile(): ?string {
        if ( empty( $_FILES['plugin_zip'] ) || empty( $_FILES['plugin_zip']['tmp_name'] ) ) {
            return __( 'No file was uploaded.', 'bulk-plugin-installer' );
        }

        $file_name = sanitize_text_field( $_FILES['plugin_zip']['name'] ?? '' );
        $file_size = (int) ( $_FILES['plugin_zip']['size'] ?? 0 );

        return $this->checkFileSizeLimit( $file_name, $file_size );
    }

    /**
     * Check if a file exceeds the configured size limit.
     *
     * @param string $file_name File name for error messages.
     * @param int    $file_size File size in bytes.
     * @return string|null Error message if limit exceeded, null if OK.
     */
    private function checkFileSizeLimit( string $file_name, int $file_size ): ?string {
        $max_size_mb = (int) get_option( 'bpi_max_file_size', 0 );
        if ( $max_size_mb <= 0 ) {
            return null;
        }

        $max_size_bytes = $max_size_mb * 1024 * 1024;
        if ( $file_size > $max_size_bytes ) {
            return sprintf(
                /* translators: 1: file name, 2: max size in MB */
                __( "File '%1\$s' exceeds the maximum allowed size of %2\$dMB.", 'bulk-plugin-installer' ),
                $file_name,
                $max_size_mb
            );
        }

        return null;
    }

    /**
     * Validate ZIP structure and contents.
     *
     * Verifies the file is a valid ZIP archive, checks for path traversal,
     * and ensures it contains at least one PHP file with a valid WordPress
     * plugin header.
     *
     * @param string $file_path Path to the ZIP file.
     * @return \WP_Error|array WP_Error on failure, array of validation data on success.
     */
    public function validateZip( string $file_path ): \WP_Error|array {
        $error = $this->checkZipStructure( $file_path );
        if ( null !== $error ) {
            return $error;
        }

        return array( 'valid' => true );
    }

    /**
     * Check ZIP file structure for validity, path traversal, and plugin headers.
     *
     * @param string $file_path Path to the ZIP file.
     * @return \WP_Error|null WP_Error on failure, null if valid.
     */
    private function checkZipStructure( string $file_path ): ?\WP_Error {
        if ( ! file_exists( $file_path ) ) {
            return new \WP_Error(
                'file_not_found',
                __( 'The uploaded file could not be found.', 'bulk-plugin-installer' )
            );
        }

        $zip = new \ZipArchive();
        if ( true !== $zip->open( $file_path ) ) {
            return new \WP_Error(
                'invalid_zip',
                __( 'The file is not a valid ZIP archive.', 'bulk-plugin-installer' )
            );
        }

        $content_error = $this->validateZipContents( $zip, $file_path );
        $zip->close();

        return $content_error;
    }

    /**
     * Validate ZIP contents for path traversal and plugin headers.
     *
     * @param \ZipArchive $zip       Open ZIP archive.
     * @param string      $file_path Path to the ZIP file (for path traversal check).
     * @return \WP_Error|null WP_Error on failure, null if valid.
     */
    private function validateZipContents( \ZipArchive $zip, string $file_path ): ?\WP_Error {
        if ( $this->checkPathTraversal( $file_path ) ) {
            return new \WP_Error(
                'path_traversal',
                __( 'The archive contains unsafe file paths and was rejected for security.', 'bulk-plugin-installer' )
            );
        }

        if ( ! $this->zipContainsPluginHeader( $zip ) ) {
            return new \WP_Error(
                'no_plugin_header',
                __( 'The archive does not contain a valid WordPress plugin.', 'bulk-plugin-installer' )
            );
        }

        return null;
    }

    /**
     * Verify a file exists and is a valid ZIP archive.
     *
     * @param string $file_path Path to the file.
     * @return \WP_Error|true WP_Error on failure, true if valid.
     */

    /**
     * Check if a ZIP archive contains at least one PHP file with a Plugin Name header.
     *
     * @param \ZipArchive $zip Open ZIP archive.
     * @return bool True if a plugin header was found.
     */
    private function zipContainsPluginHeader( \ZipArchive $zip ): bool {
        for ( $i = 0; $i < $zip->numFiles; $i++ ) {
            $entry_name = $zip->getNameIndex( $i );
            if ( $entry_name === false ) {
                continue;
            }

            if ( '.php' !== strtolower( substr( $entry_name, -4 ) ) ) {
                continue;
            }

            $content = $zip->getFromIndex( $i );
            if ( false === $content ) {
                continue;
            }

            if ( preg_match( '/^\s*\*?\s*Plugin Name\s*:/mi', $content ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Extract plugin headers from a ZIP archive.
     *
     * Reads standard WordPress plugin headers (Plugin Name, Version, Author,
     * Description, Requires PHP, Requires at least) from PHP files in the archive.
     *
     * @param string $file_path Path to the ZIP file.
     * @return array Associative array of extracted headers.
     */
    public function extractPluginHeaders( string $file_path ): array {
        $headers = array(
            'plugin_name'  => '',
            'version'      => '',
            'author'       => '',
            'description'  => '',
            'requires_php' => '',
            'requires_wp'  => '',
        );

        $zip = new \ZipArchive();
        if ( true !== $zip->open( $file_path ) ) {
            return $headers;
        }

        for ( $i = 0; $i < $zip->numFiles; $i++ ) {
            $entry_name = $zip->getNameIndex( $i );
            if ( $entry_name === false ) {
                continue;
            }

            // Only check PHP files.
            if ( '.php' !== strtolower( substr( $entry_name, -4 ) ) ) {
                continue;
            }

            // Read first 8KB for header parsing (WordPress convention).
            $content = $zip->getFromIndex( $i );
            if ( false === $content ) {
                continue;
            }
            $content = substr( $content, 0, 8192 );

            // Check if this file has a Plugin Name header.
            if ( ! preg_match( '/^\s*\*?\s*Plugin Name\s*:/mi', $content ) ) {
                continue;
            }

            // Extract each header.
            foreach ( self::PLUGIN_HEADERS as $header_name => $key ) {
                $pattern = '/^\s*\*?\s*' . preg_quote( $header_name, '/' ) . '\s*:\s*(.+)$/mi';
                if ( preg_match( $pattern, $content, $matches ) ) {
                    $headers[ $key ] = trim( $matches[1] );
                }
            }

            // Found the main plugin file, stop searching.
            break;
        }

        $zip->close();

        return $headers;
    }

    /**
     * Detect path traversal sequences in ZIP entries.
     *
     * Checks all file entries in the ZIP archive for directory traversal
     * patterns (../) and absolute paths that could write outside the
     * intended directory.
     *
     * @param string $file_path Path to the ZIP file.
     * @return bool True if path traversal is detected, false if clean.
     */
    public function checkPathTraversal( string $file_path ): bool {
        $zip = new \ZipArchive();
        if ( true !== $zip->open( $file_path ) ) {
            return false;
        }

        $found = false;
        for ( $i = 0; $i < $zip->numFiles; $i++ ) {
            $entry_name = $zip->getNameIndex( $i );
            if ( $entry_name === false ) {
                continue;
            }

            // Check for ../ or ..\ sequences, backslash traversal, or absolute paths.
            if ( str_contains( $entry_name, '../' ) || str_contains( $entry_name, '..' . DIRECTORY_SEPARATOR )
                || str_contains( $entry_name, '..\\' )
                || preg_match( '/^(\/|[A-Za-z]:[\\\\\/])/', $entry_name ) ) {
                $found = true;
                break;
            }
        }

        $zip->close();

        return $found;
    }

    /**
     * Determine plugin slug from ZIP directory structure.
     *
     * The slug is derived from the top-level directory in the ZIP archive.
     * If no directory structure exists, falls back to the first PHP file name
     * without extension.
     *
     * @param string $file_path Path to the ZIP file.
     * @return string Plugin slug.
     */
    public function getPluginSlug( string $file_path ): string {
        $zip = new \ZipArchive();
        if ( true !== $zip->open( $file_path ) ) {
            return '';
        }

        $first_php_file = '';

        for ( $i = 0; $i < $zip->numFiles; $i++ ) {
            $entry_name = $zip->getNameIndex( $i );
            if ( $entry_name === false ) {
                continue;
            }

            // Look for a top-level directory.
            $parts = explode( '/', $entry_name );
            if ( count( $parts ) > 1 && '' !== $parts[0] ) {
                $zip->close();
                return $parts[0];
            }

            // Track first PHP file as fallback.
            if ( '' === $first_php_file && '.php' === strtolower( substr( $entry_name, -4 ) ) ) {
                $first_php_file = pathinfo( $entry_name, PATHINFO_FILENAME );
            }
        }

        $zip->close();

        return $first_php_file;
    }
}
