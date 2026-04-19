<?php
/**
 * Profile Manager for the Bulk Plugin Installer.
 *
 * Manages plugin installation profiles stored in the `bpi_profiles` option.
 *
 * @package BulkPluginInstaller
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class BPIProfileManager
 *
 * Handles CRUD operations for plugin installation profiles,
 * JSON export/import, and AJAX handlers for profile management.
 *
 * @since 1.0.0
 */
class BPIProfileManager {

    /**
     * Option key for storing profiles.
     *
     * @var string
     */
    const OPTION_KEY = 'bpi_profiles';

    /**
     * Security verification failure message.
     */
    private const MSG_SECURITY_FAILED = 'Security verification failed.';

    /**
     * Permission denied message.
     */
    private const MSG_PERMISSION_DENIED = 'You do not have permission to manage profiles.';

    /**
     * Save a profile with the given name and plugin list.
     *
     * @since 1.0.0
     *
     * @param string $name    Profile name.
     * @param array  $plugins Array of plugin data (slug, name, version).
     * @return int The ID of the saved profile.
     */
    public function saveProfile( string $name, array $plugins ): int {
        $profiles = $this->getAllProfiles();

        // Auto-increment ID: find max existing ID and add 1.
        $max_id = 0;
        foreach ( $profiles as $profile ) {
            if ( isset( $profile['id'] ) && $profile['id'] > $max_id ) {
                $max_id = $profile['id'];
            }
        }
        $new_id = $max_id + 1;

        $profiles[] = array(
            'id'         => $new_id,
            'name'       => $name,
            'created_at' => gmdate( 'Y-m-d\TH:i:s\Z' ),
            'plugins'    => $plugins,
        );

        update_option( self::OPTION_KEY, $profiles );

        return $new_id;
    }

    /**
     * Get a profile by its ID.
     *
     * @since 1.0.0
     *
     * @param int $id Profile ID.
     * @return array|null Profile data or null if not found.
     */
    public function getProfile( int $id ): array|null {
        $profiles = $this->getAllProfiles();

        foreach ( $profiles as $profile ) {
            if ( isset( $profile['id'] ) && $profile['id'] === $id ) {
                return $profile;
            }
        }

        return null;
    }

    /**
     * Get all saved profiles.
     *
     * @since 1.0.0
     *
     * @return array Array of profile data.
     */
    public function getAllProfiles(): array {
        $profiles = get_option( self::OPTION_KEY, array() );

        if ( ! is_array( $profiles ) ) {
            return array();
        }

        return $profiles;
    }

    /**
     * Delete a profile by its ID.
     *
     * @since 1.0.0
     *
     * @param int $id Profile ID.
     * @return bool True if the profile was deleted, false if not found.
     */
    public function deleteProfile( int $id ): bool {
        $profiles = $this->getAllProfiles();
        $found    = false;

        $profiles = array_filter( $profiles, function ( $profile ) use ( $id, &$found ) {
            if ( isset( $profile['id'] ) && $profile['id'] === $id ) {
                $found = true;
                return false;
            }
            return true;
        } );

        if ( ! $found ) {
            return false;
        }

        // Re-index the array.
        $profiles = array_values( $profiles );
        update_option( self::OPTION_KEY, $profiles );

        return true;
    }

    /**
     * Export a profile as a JSON string.
     *
     * @since 1.0.0
     *
     * @param int $id Profile ID.
     * @return string JSON string of the profile, or empty string if not found.
     */
    public function exportProfile( int $id ): string {
        $profile = $this->getProfile( $id );

        if ( null === $profile ) {
            return '';
        }

        return wp_json_encode( $profile, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
    }

    /**
     * Sanitize a plugins array, ensuring each entry has expected keys.
     *
     * @param array $raw_plugins Raw plugins data.
     * @return array Sanitized plugins array.
     */
    private function sanitizePlugins( array $raw_plugins ): array {
        return array_map( function ( $p ) {
            return array(
                'slug'    => sanitize_text_field( $p['slug'] ?? '' ),
                'name'    => sanitize_text_field( $p['name'] ?? '' ),
                'version' => sanitize_text_field( $p['version'] ?? '' ),
            );
        }, $raw_plugins );
    }

    /**
     * Import a profile from a JSON string.
     *
     * Validates the JSON structure and creates a new profile with a new ID.
     *
     * @since 1.0.0
     *
     * @param string $json JSON string representing a profile.
     * @return int|\WP_Error The new profile ID on success, or WP_Error on failure.
     */
    public function importProfile( string $json ): int|\WP_Error {
        $data = json_decode( $json, true );

        if ( null === $data || ! is_array( $data ) ) {
            return new \WP_Error(
                'invalid_json',
                __( 'The provided string is not valid JSON.', 'bulk-plugin-installer' )
            );
        }

        if ( empty( $data['name'] ) || ! is_string( $data['name'] )
            || ! isset( $data['plugins'] ) || ! is_array( $data['plugins'] ) ) {
            return new \WP_Error(
                'invalid_profile',
                __( 'The profile JSON must contain a "name" string and a "plugins" array.', 'bulk-plugin-installer' )
            );
        }

        $plugins = $this->sanitizePlugins( $data['plugins'] );

        return $this->saveProfile(
            sanitize_text_field( $data['name'] ),
            $plugins
        );
    }

    /**
     * Register AJAX handlers for profile management.
     *
     * @since 1.0.0
     */
    public function registerAjaxHandlers(): void {
        add_action( 'wp_ajax_bpi_save_profile', array( $this, 'handleAjaxSaveProfile' ) );
        add_action( 'wp_ajax_bpi_import_profile', array( $this, 'handleAjaxImportProfile' ) );
        add_action( 'wp_ajax_bpi_export_profile', array( $this, 'handleAjaxExportProfile' ) );
        add_action( 'wp_ajax_bpi_delete_profile', array( $this, 'handleAjaxDeleteProfile' ) );
    }

    /**
     * Verify nonce and capability for an AJAX request.
     *
     * Sends a JSON error response and returns false if verification fails.
     *
     * @param string $nonce_action Nonce action name.
     * @param string $method      HTTP method to check ('POST' or 'REQUEST').
     * @return bool True if verified, false if error response was sent.
     */
    private function verifyAjaxRequest( string $nonce_action, string $method = 'POST' ): bool {
        $input = 'REQUEST' === $method ? $_REQUEST : $_POST;

        if ( ! isset( $input['_wpnonce'] ) || ! wp_verify_nonce( wp_unslash( $input['_wpnonce'] ), $nonce_action ) ) {
            wp_send_json_error(
                array( 'message' => __( self::MSG_SECURITY_FAILED, 'bulk-plugin-installer' ) ),
                403
            );
            return false;
        }

        if ( ! current_user_can( 'install_plugins' ) ) {
            wp_send_json_error(
                array( 'message' => __( self::MSG_PERMISSION_DENIED, 'bulk-plugin-installer' ) ),
                403
            );
            return false;
        }

        return true;
    }

    /**
     * AJAX handler: Save a profile.
     *
     * @since 1.0.0
     */
    public function handleAjaxSaveProfile(): void {
        if ( ! $this->verifyAjaxRequest( 'bpi_save_profile' ) ) {
            return;
        }

        $name        = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
        $raw_plugins = isset( $_POST['plugins'] ) ? wp_unslash( $_POST['plugins'] ) : array();

        if ( empty( $name ) ) {
            wp_send_json_error(
                array( 'message' => __( 'Profile name is required.', 'bulk-plugin-installer' ) )
            );
            return;
        }

        if ( ! is_array( $raw_plugins ) ) {
            wp_send_json_error(
                array( 'message' => __( 'Plugins must be an array.', 'bulk-plugin-installer' ) )
            );
            return;
        }

        $plugins = $this->sanitizePlugins( $raw_plugins );

        $id = $this->saveProfile( $name, $plugins );

        wp_send_json_success( array(
            'message' => __( 'Profile saved successfully.', 'bulk-plugin-installer' ),
            'id'      => $id,
            'profile' => $this->getProfile( $id ),
        ) );
    }

    /**
     * AJAX handler: Import a profile from JSON.
     *
     * @since 1.0.0
     */
    public function handleAjaxImportProfile(): void {
        if ( ! $this->verifyAjaxRequest( 'bpi_import_profile' ) ) {
            return;
        }

        $json = isset( $_POST['profile_json'] ) ? wp_unslash( $_POST['profile_json'] ) : '';

        if ( empty( $json ) ) {
            wp_send_json_error(
                array( 'message' => __( 'No profile JSON provided.', 'bulk-plugin-installer' ) )
            );
            return;
        }

        $result = $this->importProfile( $json );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error(
                array( 'message' => $result->get_error_message() )
            );
            return;
        }

        wp_send_json_success( array(
            'message' => __( 'Profile imported successfully.', 'bulk-plugin-installer' ),
            'id'      => $result,
            'profile' => $this->getProfile( $result ),
        ) );
    }

    /**
     * AJAX handler: Export a profile as JSON.
     *
     * @since 1.0.0
     */
    public function handleAjaxExportProfile(): void {
        if ( ! $this->verifyAjaxRequest( 'bpi_export_profile', 'REQUEST' ) ) {
            return;
        }

        $id = isset( $_REQUEST['profile_id'] ) ? absint( $_REQUEST['profile_id'] ) : 0;

        if ( 0 === $id ) {
            wp_send_json_error(
                array( 'message' => __( 'Profile ID is required.', 'bulk-plugin-installer' ) )
            );
            return;
        }

        $json = $this->exportProfile( $id );

        if ( '' === $json ) {
            wp_send_json_error(
                array( 'message' => __( 'Profile not found.', 'bulk-plugin-installer' ) )
            );
            return;
        }

        wp_send_json_success( array(
            'json' => $json,
        ) );
    }

    /**
     * AJAX handler: Delete a profile.
     *
     * @since 1.0.0
     */
    public function handleAjaxDeleteProfile(): void {
        if ( ! $this->verifyAjaxRequest( 'bpi_delete_profile' ) ) {
            return;
        }

        $id = isset( $_POST['profile_id'] ) ? absint( $_POST['profile_id'] ) : 0;

        if ( 0 === $id ) {
            wp_send_json_error(
                array( 'message' => __( 'Profile ID is required.', 'bulk-plugin-installer' ) )
            );
            return;
        }

        $deleted = $this->deleteProfile( $id );

        if ( ! $deleted ) {
            wp_send_json_error(
                array( 'message' => __( 'Profile not found.', 'bulk-plugin-installer' ) )
            );
            return;
        }

        wp_send_json_success( array(
            'message' => __( 'Profile deleted successfully.', 'bulk-plugin-installer' ),
        ) );
    }

    /**
     * Render the profiles list for the Settings_Page.
     *
     * Displays profile name, creation date, plugin count, and a delete button.
     *
     * @since 1.0.0
     */
    public function renderProfilesList(): void {
        $profiles = $this->getAllProfiles();
        ?>
        <div class="bpi-profiles-list">
            <h3><?php esc_html_e( 'Plugin Profiles', 'bulk-plugin-installer' ); ?></h3>
            <?php if ( empty( $profiles ) ) : ?>
                <p><?php esc_html_e( 'No profiles saved yet.', 'bulk-plugin-installer' ); ?></p>
            <?php else : ?>
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Name', 'bulk-plugin-installer' ); ?></th>
                            <th><?php esc_html_e( 'Created', 'bulk-plugin-installer' ); ?></th>
                            <th><?php esc_html_e( 'Plugins', 'bulk-plugin-installer' ); ?></th>
                            <th><?php esc_html_e( 'Actions', 'bulk-plugin-installer' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $profiles as $profile ) : ?>
                            <tr>
                                <td><?php echo esc_html( $profile['name'] ); ?></td>
                                <td><?php echo esc_html( $profile['created_at'] ?? '' ); ?></td>
                                <td><?php echo esc_html( count( $profile['plugins'] ?? array() ) ); ?></td>
                                <td>
                                    <button type="button"
                                        class="button bpi-delete-profile"
                                        data-profile-id="<?php echo esc_attr( (string) $profile['id'] ); ?>"
                                        aria-label="<?php echo esc_attr( sprintf(
                                            /* translators: %s: profile name */
                                            __( 'Delete profile: %s', 'bulk-plugin-installer' ),
                                            $profile['name']
                                        ) ); ?>">
                                        <?php esc_html_e( 'Delete', 'bulk-plugin-installer' ); ?>
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }
}
