<?php
/**
 * Settings Manager for Bulk Plugin Installer.
 *
 * Registers and manages all plugin settings via the WordPress Settings API,
 * provides sanitization callbacks, and renders the settings page with log display.
 *
 * @package BulkPluginInstaller
 */

// Abort if this file is called directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Handles plugin settings registration, sanitization, and rendering.
 *
 * Uses the WordPress Settings API for all option management. Provides
 * a settings page under Settings > Bulk Plugin Installer with configuration
 * options and an activity log display.
 *
 * @since 1.0.0
 */
class BPISettingsManager {

    /**
     * Settings page slug.
     *
     * @var string
     */
    private const PAGE_SLUG = 'bpi-settings';

    /**
     * Option group name for Settings API.
     *
     * @var string
     */
    private const OPTION_GROUP = 'bpi_settings_group';

    /**
     * Settings section ID.
     *
     * @var string
     */
    private const SECTION_ID = 'bpi_general_section';

    /**
     * Default values for all settings.
     *
     * @var array<string, mixed>
     */
    private const DEFAULTS = array(
        'bpi_auto_activate'            => false,
        'bpi_max_plugins'              => 20,
        'bpi_auto_rollback'            => true,
        'bpi_max_file_size'            => 0,
        'bpi_rollback_retention'       => 24,
        'bpi_email_notifications'      => false,
        'bpi_email_recipients'         => '',
        'bpi_delete_data_on_uninstall' => false,
    );

    /**
     * Register all settings with the WordPress Settings API.
     *
     * Registers the option group, settings section, and individual fields
     * for all plugin configuration options.
     *
     * @since 1.0.0
     */
    public function registerSettings(): void {
        register_setting(
            self::OPTION_GROUP,
            'bpi_settings',
            array(
                'sanitize_callback' => array( $this, 'sanitizeSettings' ),
            )
        );

        add_settings_section(
            self::SECTION_ID,
            __( 'General Settings', 'bulk-plugin-installer' ),
            array( $this, 'renderSectionDescription' ),
            self::PAGE_SLUG
        );

        add_settings_field(
            'bpi_auto_activate',
            __( 'Auto-Activate Plugins', 'bulk-plugin-installer' ),
            array( $this, 'renderCheckboxField' ),
            self::PAGE_SLUG,
            self::SECTION_ID,
            array(
                'key'         => 'bpi_auto_activate',
                'description' => __( 'Automatically activate newly installed plugins after installation.', 'bulk-plugin-installer' ),
            )
        );

        add_settings_field(
            'bpi_max_plugins',
            __( 'Maximum Plugins Per Upload', 'bulk-plugin-installer' ),
            array( $this, 'renderNumberField' ),
            self::PAGE_SLUG,
            self::SECTION_ID,
            array(
                'key'         => 'bpi_max_plugins',
                'description' => __( 'Maximum number of plugins allowed per bulk upload (1-100).', 'bulk-plugin-installer' ),
                'min'         => 1,
                'max'         => 100,
            )
        );

        add_settings_field(
            'bpi_auto_rollback',
            __( 'Auto-Rollback on Failure', 'bulk-plugin-installer' ),
            array( $this, 'renderCheckboxField' ),
            self::PAGE_SLUG,
            self::SECTION_ID,
            array(
                'key'         => 'bpi_auto_rollback',
                'description' => __( 'Automatically rollback plugin updates when installation fails.', 'bulk-plugin-installer' ),
            )
        );

        add_settings_field(
            'bpi_max_file_size',
            __( 'Maximum File Size (MB)', 'bulk-plugin-installer' ),
            array( $this, 'renderNumberField' ),
            self::PAGE_SLUG,
            self::SECTION_ID,
            array(
                'key'         => 'bpi_max_file_size',
                'description' => __( 'Maximum ZIP file size in megabytes. Set to 0 to use server default.', 'bulk-plugin-installer' ),
                'min'         => 0,
                'max'         => 99999,
            )
        );

        add_settings_field(
            'bpi_rollback_retention',
            __( 'Rollback Retention (Hours)', 'bulk-plugin-installer' ),
            array( $this, 'renderNumberField' ),
            self::PAGE_SLUG,
            self::SECTION_ID,
            array(
                'key'         => 'bpi_rollback_retention',
                'description' => __( 'How long to retain batch rollback backups, in hours (1-720).', 'bulk-plugin-installer' ),
                'min'         => 1,
                'max'         => 720,
            )
        );

        add_settings_field(
            'bpi_email_notifications',
            __( 'Email Notifications', 'bulk-plugin-installer' ),
            array( $this, 'renderCheckboxField' ),
            self::PAGE_SLUG,
            self::SECTION_ID,
            array(
                'key'         => 'bpi_email_notifications',
                'description' => __( 'Send email notifications after bulk operations complete.', 'bulk-plugin-installer' ),
            )
        );

        add_settings_field(
            'bpi_email_recipients',
            __( 'Additional Email Recipients', 'bulk-plugin-installer' ),
            array( $this, 'renderTextField' ),
            self::PAGE_SLUG,
            self::SECTION_ID,
            array(
                'key'         => 'bpi_email_recipients',
                'description' => __( 'Comma-separated list of additional email addresses for notifications.', 'bulk-plugin-installer' ),
                'placeholder' => 'admin@example.com, dev@example.com',
            )
        );

        add_settings_field(
            'bpi_delete_data_on_uninstall',
            __( 'Delete Data on Uninstall', 'bulk-plugin-installer' ),
            array( $this, 'renderCheckboxField' ),
            self::PAGE_SLUG,
            self::SECTION_ID,
            array(
                'key'         => 'bpi_delete_data_on_uninstall',
                'description' => __( 'Remove all plugin data (settings, logs, profiles) when the plugin is deleted. If unchecked, data is preserved for future reinstallation.', 'bulk-plugin-installer' ),
            )
        );
    }

    /**
     * Get a setting value with default fallback.
     *
     * @param string $key The option key to retrieve.
     * @return mixed The option value or its default.
     *
     * @since 1.0.0
     */
    public function getOption( string $key ): mixed {
        $default = self::DEFAULTS[ $key ] ?? null;
        return get_option( $key, $default );
    }

    /**
     * Sanitize callback for settings.
     *
     * Validates each field and returns sanitized values. Invalid values
     * are rejected with descriptive error messages and previous valid
     * settings are preserved.
     *
     * @param array $input Raw input from the settings form.
     * @return array Sanitized settings values.
     *
     * @since 1.0.0
     */
    public function sanitizeSettings( array $input ): array {
        $sanitized = array();

        $sanitized['bpi_auto_activate']       = ! empty( $input['bpi_auto_activate'] );
        $sanitized['bpi_max_plugins']         = $this->sanitizeIntRange(
            $input, 'bpi_max_plugins', 1, 100, 'bpi_invalid_max_plugins',
            __( 'Maximum plugins must be a positive integer between 1 and 100.', 'bulk-plugin-installer' )
        );
        $sanitized['bpi_auto_rollback']       = ! empty( $input['bpi_auto_rollback'] );
        $sanitized['bpi_max_file_size']       = $this->sanitizeNonNegativeInt( $input, 'bpi_max_file_size' );
        $sanitized['bpi_rollback_retention']  = $this->sanitizeIntRange(
            $input, 'bpi_rollback_retention', 1, 720, 'bpi_invalid_rollback_retention',
            __( 'Rollback retention must be between 1 and 720 hours.', 'bulk-plugin-installer' )
        );
        $sanitized['bpi_email_notifications'] = ! empty( $input['bpi_email_notifications'] );
        $sanitized['bpi_email_recipients']    = $this->sanitizeEmailRecipients( $input );
        $sanitized['bpi_delete_data_on_uninstall'] = ! empty( $input['bpi_delete_data_on_uninstall'] );

        // Persist each setting as an individual option.
        foreach ( $sanitized as $key => $value ) {
            update_option( $key, $value );
        }

        return $sanitized;
    }

    /**
     * Sanitize an integer setting within a min/max range.
     *
     * @param array  $input      Raw input.
     * @param string $key        Setting key.
     * @param int    $min        Minimum allowed value.
     * @param int    $max        Maximum allowed value.
     * @param string $error_code Error code for add_settings_error.
     * @param string $error_msg  Error message for add_settings_error.
     * @return int Sanitized value.
     */
    private function sanitizeIntRange( array $input, string $key, int $min, int $max, string $error_code, string $error_msg ): int {
        if ( ! isset( $input[ $key ] ) ) {
            return (int) $this->getOption( $key );
        }

        $value = (int) $input[ $key ];
        if ( $value < $min || $value > $max ) {
            add_settings_error( 'bpi_settings', $error_code, $error_msg, 'error' );
            return (int) $this->getOption( $key );
        }

        return $value;
    }

    /**
     * Sanitize a non-negative integer setting.
     *
     * @param array  $input Raw input.
     * @param string $key   Setting key.
     * @return int Sanitized value.
     */
    private function sanitizeNonNegativeInt( array $input, string $key ): int {
        if ( ! isset( $input[ $key ] ) ) {
            return (int) $this->getOption( $key );
        }

        $value = $input[ $key ];
        if ( ! is_numeric( $value ) || (float) $value < 0 ) {
            // Strip the 'bpi_' prefix from the key to build the error code,
            // e.g. 'bpi_max_file_size' → 'bpi_invalid_max_file_size'.
            $short_key = preg_replace( '/^bpi_/', '', $key );
            add_settings_error(
                'bpi_settings',
                'bpi_invalid_' . $short_key,
                __( 'Maximum file size must be a positive number.', 'bulk-plugin-installer' ),
                'error'
            );
            return (int) $this->getOption( $key );
        }

        return (int) $value;
    }

    /**
     * Sanitize email recipients setting.
     *
     * @param array $input Raw input.
     * @return string Sanitized comma-separated email list.
     */
    private function sanitizeEmailRecipients( array $input ): string {
        if ( ! isset( $input['bpi_email_recipients'] ) || '' === trim( $input['bpi_email_recipients'] ) ) {
            return '';
        }

        $raw_emails   = $input['bpi_email_recipients'];
        $emails       = array_map( 'trim', explode( ',', $raw_emails ) );
        $valid_emails = array();
        $has_invalid  = false;

        foreach ( $emails as $email ) {
            if ( '' === $email ) {
                continue;
            }
            if ( is_email( $email ) ) {
                $valid_emails[] = sanitize_email( $email );
            } else {
                $has_invalid = true;
            }
        }

        if ( $has_invalid ) {
            add_settings_error(
                'bpi_settings',
                'bpi_invalid_email_recipients',
                __( 'One or more email addresses are invalid.', 'bulk-plugin-installer' ),
                'error'
            );
            return (string) $this->getOption( 'bpi_email_recipients' );
        }

        return implode( ', ', $valid_emails );
    }

    /**
     * Add the settings page under the WordPress Settings menu.
     *
     * @since 1.0.0
     */
    public function addMenuPage(): void {
        add_options_page(
            __( 'Bulk Plugin Installer', 'bulk-plugin-installer' ),
            __( 'Bulk Plugin Installer', 'bulk-plugin-installer' ),
            'install_plugins',
            self::PAGE_SLUG,
            array( $this, 'renderSettingsPage' )
        );
    }

    /**
     * Render the settings page HTML.
     *
     * Displays the settings form and the last 50 activity log entries
     * with a clear log button.
     *
     * @since 1.0.0
     */
    public function renderSettingsPage(): void {
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Bulk Plugin Installer Settings', 'bulk-plugin-installer' ); ?></h1>

            <form method="post" action="options.php">
                <?php
                settings_fields( self::OPTION_GROUP );
                do_settings_sections( self::PAGE_SLUG );
                submit_button( __( 'Save Settings', 'bulk-plugin-installer' ) );
                ?>
            </form>

            <hr />

            <h2><?php esc_html_e( 'Activity Log', 'bulk-plugin-installer' ); ?></h2>

            <?php $this->renderLogSection(); ?>
        </div>
        <?php
    }

    /**
     * Render the section description.
     *
     * @since 1.0.0
     */
    public function renderSectionDescription(): void {
        echo '<p>' . esc_html__( 'Configure the behavior of the Bulk Plugin Installer.', 'bulk-plugin-installer' ) . '</p>';
    }

    /**
     * Render a checkbox settings field.
     *
     * @param array $args Field arguments including 'key' and 'description'.
     *
     * @since 1.0.0
     */
    public function renderCheckboxField( array $args ): void {
        $key     = $args['key'];
        $value   = $this->getOption( $key );
        $checked = ! empty( $value );
        ?>
        <label>
            <input type="checkbox"
                id="<?php echo esc_attr( $key ); ?>"
                name="bpi_settings[<?php echo esc_attr( $key ); ?>]"
                value="1"
                aria-describedby="<?php echo esc_attr( $key ); ?>-description"
                <?php checked( $checked ); ?>
            />
            <span id="<?php echo esc_attr( $key ); ?>-description"><?php echo esc_html( $args['description'] ?? '' ); ?></span>
        </label>
        <?php
    }

    /**
     * Render a number input settings field.
     *
     * @param array $args Field arguments including 'key', 'description', 'min', 'max'.
     *
     * @since 1.0.0
     */
    public function renderNumberField( array $args ): void {
        $key   = $args['key'];
        $value = $this->getOption( $key );
        $min   = $args['min'] ?? 0;
        $max   = $args['max'] ?? 99999;
        ?>
        <input type="number"
            id="<?php echo esc_attr( $key ); ?>"
            name="bpi_settings[<?php echo esc_attr( $key ); ?>]"
            value="<?php echo esc_attr( (string) $value ); ?>"
            min="<?php echo esc_attr( (string) $min ); ?>"
            max="<?php echo esc_attr( (string) $max ); ?>"
            aria-describedby="<?php echo esc_attr( $key ); ?>-description"
            class="small-text"
        />
        <p id="<?php echo esc_attr( $key ); ?>-description" class="description"><?php echo esc_html( $args['description'] ?? '' ); ?></p>
        <?php
    }

    /**
     * Render a text input settings field.
     *
     * @param array $args Field arguments including 'key', 'description', 'placeholder'.
     *
     * @since 1.0.0
     */
    public function renderTextField( array $args ): void {
        $key         = $args['key'];
        $value       = $this->getOption( $key );
        $placeholder = $args['placeholder'] ?? '';
        ?>
        <input type="text"
            id="<?php echo esc_attr( $key ); ?>"
            name="bpi_settings[<?php echo esc_attr( $key ); ?>]"
            value="<?php echo esc_attr( (string) $value ); ?>"
            placeholder="<?php echo esc_attr( $placeholder ); ?>"
            aria-describedby="<?php echo esc_attr( $key ); ?>-description"
            class="regular-text"
        />
        <p id="<?php echo esc_attr( $key ); ?>-description" class="description"><?php echo esc_html( $args['description'] ?? '' ); ?></p>
        <?php
    }

    /**
     * Render the activity log section with the last 50 entries and a clear button.
     */
    private function renderLogSection(): void {
        $log_manager = new BPILogManager();
        $entries     = $log_manager->getEntries( 50 );

        if ( empty( $entries ) ) {
            echo '<p>' . esc_html__( 'No log entries found.', 'bulk-plugin-installer' ) . '</p>';
            return;
        }

        ?>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('<?php echo esc_js( __( 'Are you sure you want to clear the activity log? This cannot be undone.', 'bulk-plugin-installer' ) ); ?>');">
            <?php wp_nonce_field( 'bpi_clear_log', 'bpi_clear_log_nonce' ); ?>
            <input type="hidden" name="action" value="bpi_clear_log" />
            <p>
                <button type="submit" class="button button-secondary">
                    <?php esc_html_e( 'Clear Log', 'bulk-plugin-installer' ); ?>
                </button>
            </p>
        </form>

        <table class="widefat fixed striped" role="table">
            <thead>
                <tr>
                    <th scope="col"><?php esc_html_e( 'Timestamp', 'bulk-plugin-installer' ); ?></th>
                    <th scope="col"><?php esc_html_e( 'Action', 'bulk-plugin-installer' ); ?></th>
                    <th scope="col"><?php esc_html_e( 'Plugin', 'bulk-plugin-installer' ); ?></th>
                    <th scope="col"><?php esc_html_e( 'Status', 'bulk-plugin-installer' ); ?></th>
                    <th scope="col"><?php esc_html_e( 'Message', 'bulk-plugin-installer' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $entries as $entry ) : ?>
                    <tr>
                        <td><?php echo esc_html( $entry->timestamp ?? '' ); ?></td>
                        <td>
                            <?php
                            echo esc_html( $entry->action ?? '' );
                            if ( ! empty( $entry->is_dry_run ) ) {
                                echo ' <span class="bpi-dry-run-badge">(' . esc_html__( 'Dry Run', 'bulk-plugin-installer' ) . ')</span>';
                            }
                            ?>
                        </td>
                        <td><?php echo esc_html( $entry->plugin_name ?? $entry->plugin_slug ?? '' ); ?></td>
                        <td><?php echo esc_html( $entry->status ?? '' ); ?></td>
                        <td><?php echo esc_html( $entry->message ?? '' ); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    /**
     * Get the page slug constant for external use.
     *
     * @return string The settings page slug.
     *
     * @since 1.0.0
     */
    public function getPageSlug(): string {
        return self::PAGE_SLUG;
    }

    /**
     * Get the option group constant for external use.
     *
     * @return string The option group name.
     *
     * @since 1.0.0
     */
    public function getOptionGroup(): string {
        return self::OPTION_GROUP;
    }

    /**
     * Get the default values for all settings.
     *
     * @return array<string, mixed> Default settings.
     *
     * @since 1.0.0
     */
    public function getDefaults(): array {
        return self::DEFAULTS;
    }
}
