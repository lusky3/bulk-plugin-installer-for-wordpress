<?php
/**
 * WordPress function stubs for unit testing outside of WordPress.
 *
 * These stubs provide minimal implementations of WordPress functions
 * used by the plugin so that classes can be instantiated and tested
 * without a full WordPress installation.
 *
 * @package BulkPluginInstaller
 */

// Track registered hooks for test assertions.
global $bpi_test_hooks, $bpi_test_options, $wpdb;
$bpi_test_hooks   = array();
$bpi_test_options = array();

// Minimal $wpdb stub for deactivate() transient cleanup and log table operations.
$wpdb = new class {
    public string $options = 'wp_options';
    public string $prefix  = 'wp_';
    public string $charset_collate = 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'; // NOSONAR

    /**
     * In-memory storage for log entries (simulates the bpi_log table).
     *
     * @var array
     */
    public array $bpi_log_rows = array(); // NOSONAR

    /**
     * Auto-increment counter for log entry IDs.
     *
     * @var int
     */
    public int $bpi_log_next_id = 1; // NOSONAR

    /**
     * Last insert ID.
     *
     * @var int
     */
    public int $insert_id = 0; // NOSONAR

    /**
     * Whether the log table has been "created".
     *
     * @var bool
     */
    public bool $bpi_log_table_exists = false; // NOSONAR

    /**
     * @param string $query SQL query.
     * @return int|bool Number of rows affected or false on error.
     */
    public function query( string $query ) {
        // Handle DROP TABLE.
        if ( stripos( $query, 'DROP TABLE' ) !== false ) {
            $this->bpi_log_rows        = array();
            $this->bpi_log_next_id     = 1;
            $this->bpi_log_table_exists = false;
            return 0;
        }

        // Handle TRUNCATE TABLE.
        if ( stripos( $query, 'TRUNCATE TABLE' ) !== false ) {
            $this->bpi_log_rows    = array();
            $this->bpi_log_next_id = 1;
            return 0;
        }

        return 0;
    }

    /**
     * Insert a row into a table.
     *
     * @param string $table  Table name.
     * @param array  $data   Data to insert (column => value).
     * @param array  $format Data format.
     * @return int|false Number of rows inserted or false on error.
     */
    public function insert( string $table, array $data, $format = null ) { // NOSONAR
        if ( str_contains( $table, 'bpi_log' ) ) {
            $data['id']        = $this->bpi_log_next_id++;
            $this->insert_id   = $data['id'];
            $this->bpi_log_rows[] = $data;
            return 1;
        }
        return false;
    }

    /**
     * Retrieve results from the database.
     *
     * @param string $query  SQL query.
     * @param string $output Output type.
     * @return array|null Results.
     */
    public function get_results( string $query, $output = 'OBJECT' ) { // NOSONAR
        if ( str_contains( $query, 'bpi_log' ) ) {
            $rows = $this->bpi_log_rows;

            // Sort by timestamp DESC (most recent first).
            usort( $rows, function ( $a, $b ) {
                return strcmp( $b['timestamp'] ?? '', $a['timestamp'] ?? '' );
            });

            // Parse LIMIT and OFFSET from query.
            if ( preg_match( '/LIMIT\s+(\d+)/i', $query, $m ) ) {
                $limit = (int) $m[1];
                $offset = 0;
                if ( preg_match( '/OFFSET\s+(\d+)/i', $query, $m2 ) ) {
                    $offset = (int) $m2[1];
                }
                $rows = array_slice( $rows, $offset, $limit );
            }

            if ( $output === 'ARRAY_A' ) {
                return $rows;
            }

            // Convert to objects.
            return array_map( function ( $row ) {
                return (object) $row;
            }, $rows );
        }
        return array();
    }

    /**
     * Prepare a SQL query for safe execution.
     *
     * @param string $query  Query with placeholders.
     * @param mixed  ...$args Values to substitute.
     * @return string Prepared query.
     */
    public function prepare( string $query, ...$args ) {
        // Flatten if first arg is an array (WordPress-style call).
        if ( count( $args ) === 1 && is_array( $args[0] ) ) {
            $args = $args[0];
        }

        $i = 0;
        return preg_replace_callback( '/%[sd]/', function ( $match ) use ( $args, &$i ) {
            $val = $args[ $i++ ] ?? '';
            if ( $match[0] === '%d' ) {
                return (int) $val;
            }
            return "'" . addslashes( (string) $val ) . "'";
        }, $query );
    }

    public function esc_like( string $text ): string {
        return addcslashes( $text, '_%\\' );
    }

    /**
     * Get the charset collate string for table creation.
     *
     * @return string
     */
    public function get_charset_collate(): string { // NOSONAR
        return $this->charset_collate;
    }

    /**
     * Reset the log table state (useful in tests).
     */
    public function reset_bpi_log(): void { // NOSONAR
        $this->bpi_log_rows        = array();
        $this->bpi_log_next_id     = 1;
        $this->insert_id           = 0;
        $this->bpi_log_table_exists = false;
    }
};

if ( ! function_exists( 'plugin_dir_path' ) ) {
    function plugin_dir_path( string $file ): string { // NOSONAR
        return trailingslashit( dirname( $file ) );
    }
}

if ( ! function_exists( 'plugin_dir_url' ) ) {
    function plugin_dir_url( string $file ): string { // NOSONAR
        return 'https://example.com/wp-content/plugins/' . basename( dirname( $file ) ) . '/';
    }
}

if ( ! function_exists( 'plugin_basename' ) ) {
    function plugin_basename( string $file ): string { // NOSONAR
        return basename( dirname( $file ) ) . '/' . basename( $file );
    }
}

if ( ! function_exists( 'trailingslashit' ) ) {
    function trailingslashit( string $value ): string {
        return rtrim( $value, '/\\' ) . '/';
    }
}

if ( ! function_exists( 'add_action' ) ) {
    function add_action( string $hook, $callback, int $priority = 10, int $accepted_args = 1 ): void { // NOSONAR
        global $bpi_test_hooks;
        $bpi_test_hooks[] = array(
            'type'     => 'action',
            'hook'     => $hook,
            'callback' => $callback,
            'priority' => $priority,
        );
    }
}

if ( ! function_exists( 'do_action' ) ) {
    function do_action( string $hook_name, ...$args ): void {}
}

if ( ! function_exists( 'apply_filters' ) ) {
    function apply_filters( string $hook_name, $value, ...$args ) {
        return $value;
    }
}

if ( ! function_exists( 'wp_next_scheduled' ) ) {
    function wp_next_scheduled( string $hook, array $args = array() ) {
        return false;
    }
}

if ( ! function_exists( 'wp_schedule_event' ) ) {
    function wp_schedule_event( int $timestamp, string $recurrence, string $hook, array $args = array() ): bool {
        return true;
    }
}

if ( ! function_exists( 'wp_clear_scheduled_hook' ) ) {
    function wp_clear_scheduled_hook( string $hook, array $args = array() ): int {
        return 0;
    }
}

if ( ! function_exists( 'register_activation_hook' ) ) {
    function register_activation_hook( string $file, $callback ): void { // NOSONAR
        global $bpi_test_hooks;
        $bpi_test_hooks[] = array(
            'type'     => 'activation',
            'file'     => $file,
            'callback' => $callback,
        );
    }
}

if ( ! function_exists( 'register_deactivation_hook' ) ) {
    function register_deactivation_hook( string $file, $callback ): void { // NOSONAR
        global $bpi_test_hooks;
        $bpi_test_hooks[] = array(
            'type'     => 'deactivation',
            'file'     => $file,
            'callback' => $callback,
        );
    }
}

if ( ! function_exists( 'load_plugin_textdomain' ) ) {
    function load_plugin_textdomain( string $domain, $_deprecated = false, string $plugin_rel_path = '' ): bool { // NOSONAR
        global $bpi_test_hooks;
        $bpi_test_hooks[] = array(
            'type'   => 'textdomain',
            'domain' => $domain,
            'path'   => $plugin_rel_path,
        );
        return true;
    }
}

if ( ! function_exists( 'get_option' ) ) {
    function get_option( string $option, $default = false ) { // NOSONAR
        global $bpi_test_options;
        return array_key_exists( $option, $bpi_test_options ) ? $bpi_test_options[ $option ] : $default;
    }
}

if ( ! function_exists( 'add_option' ) ) {
    function add_option( string $option, $value = '' ): bool { // NOSONAR
        global $bpi_test_options;
        if ( ! array_key_exists( $option, $bpi_test_options ) ) {
            $bpi_test_options[ $option ] = $value;
            return true;
        }
        return false;
    }
}

if ( ! function_exists( 'update_option' ) ) {
    function update_option( string $option, $value ): bool { // NOSONAR
        global $bpi_test_options;
        $bpi_test_options[ $option ] = $value;
        return true;
    }
}

if ( ! function_exists( 'delete_option' ) ) {
    function delete_option( string $option ): bool { // NOSONAR
        global $bpi_test_options;
        if ( array_key_exists( $option, $bpi_test_options ) ) {
            unset( $bpi_test_options[ $option ] );
            return true;
        }
        return false;
    }
}

if ( ! function_exists( '__' ) ) {
    function __( string $text, string $domain = 'default' ): string { // NOSONAR
        return $text;
    }
}

if ( ! function_exists( '_e' ) ) {
    function _e( string $text, string $domain = 'default' ): void { // NOSONAR
        echo $text;
    }
}

if ( ! function_exists( 'esc_html__' ) ) {
    function esc_html__( string $text, string $domain = 'default' ): string { // NOSONAR
        return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
    }
}

if ( ! function_exists( 'esc_html_e' ) ) {
    function esc_html_e( string $text, string $domain = 'default' ): void { // NOSONAR
        echo esc_html__( $text, $domain );
    }
}

if ( ! function_exists( 'get_current_user_id' ) ) {
    /**
     * Stub for get_current_user_id().
     *
     * @return int Current user ID (defaults to 1 for tests).
     */
    function get_current_user_id(): int { // NOSONAR
        global $bpi_test_current_user_id;
        return $bpi_test_current_user_id ?? 1;
    }
}

if ( ! function_exists( 'current_time' ) ) {
    /**
     * Stub for current_time().
     *
     * @param string $type Type of time to return.
     * @return string Current time in MySQL format.
     */
    function current_time( string $type = 'mysql' ): string { // NOSONAR
        return gmdate( 'Y-m-d H:i:s' );
    }
}

if ( ! function_exists( 'dbDelta' ) ) {
    /**
     * Stub for dbDelta() — marks the log table as created.
     *
     * @param string|array $queries SQL queries.
     * @param bool         $execute Whether to execute.
     * @return array Results.
     */
    function dbDelta( $queries = '', bool $_execute = true ): array { // NOSONAR
        global $wpdb;
        $wpdb->bpi_log_table_exists = true;
        return array();
    }
}

// ---- Settings API stubs ----

// Track registered settings, sections, fields, and pages for test assertions.
global $bpi_test_registered_settings, $bpi_test_settings_sections, $bpi_test_settings_fields, $bpi_test_options_pages, $bpi_test_settings_errors;
$bpi_test_registered_settings = array();
$bpi_test_settings_sections   = array();
$bpi_test_settings_fields     = array();
$bpi_test_options_pages       = array();
$bpi_test_settings_errors     = array();

if ( ! function_exists( 'register_setting' ) ) {
    function register_setting( string $option_group, string $option_name, $args = array() ): void { // NOSONAR
        global $bpi_test_registered_settings;
        $bpi_test_registered_settings[ $option_name ] = array(
            'group' => $option_group,
            'name'  => $option_name,
            'args'  => $args,
        );
    }
}

if ( ! function_exists( 'add_settings_section' ) ) {
    function add_settings_section( string $id, string $title, $callback, string $page, $args = array() ): void { // NOSONAR
        global $bpi_test_settings_sections;
        $bpi_test_settings_sections[ $id ] = array(
            'id'       => $id,
            'title'    => $title,
            'callback' => $callback,
            'page'     => $page,
            'args'     => $args,
        );
    }
}

if ( ! function_exists( 'add_settings_field' ) ) {
    function add_settings_field( string $id, string $title, $callback, string $page, string $section = 'default', $args = array() ): void { // NOSONAR
        global $bpi_test_settings_fields;
        $bpi_test_settings_fields[ $id ] = array(
            'id'       => $id,
            'title'    => $title,
            'callback' => $callback,
            'page'     => $page,
            'section'  => $section,
            'args'     => $args,
        );
    }
}

if ( ! function_exists( 'add_options_page' ) ) {
    function add_options_page( string $page_title, string $menu_title, string $capability, string $menu_slug, $callback = '', int $position = null ): string { // NOSONAR
        global $bpi_test_options_pages;
        $bpi_test_options_pages[ $menu_slug ] = array(
            'page_title' => $page_title,
            'menu_title' => $menu_title,
            'capability' => $capability,
            'menu_slug'  => $menu_slug,
            'callback'   => $callback,
            'position'   => $position,
        );
        return 'settings_page_' . $menu_slug;
    }
}

if ( ! function_exists( 'settings_fields' ) ) {
    function settings_fields( string $option_group ): void { // NOSONAR
        echo '<input type="hidden" name="option_page" value="' . htmlspecialchars( $option_group ) . '" />';
    }
}

if ( ! function_exists( 'do_settings_sections' ) ) {
    function do_settings_sections( string $page ): void { // NOSONAR
        echo '<!-- settings sections for ' . htmlspecialchars( $page ) . ' -->';
    }
}

if ( ! function_exists( 'submit_button' ) ) {
    function submit_button( string $text = 'Save Changes', string $type = 'primary', string $name = 'submit', bool $wrap = true, $other_attributes = null ): void { // NOSONAR
        echo '<input type="submit" name="' . htmlspecialchars( $name ) . '" class="button button-' . htmlspecialchars( $type ) . '" value="' . htmlspecialchars( $text ) . '" />';
    }
}

if ( ! function_exists( 'add_settings_error' ) ) {
    function add_settings_error( string $setting, string $code, string $message, string $type = 'error' ): void { // NOSONAR
        global $bpi_test_settings_errors;
        $bpi_test_settings_errors[] = array(
            'setting' => $setting,
            'code'    => $code,
            'message' => $message,
            'type'    => $type,
        );
    }
}

if ( ! function_exists( 'esc_attr' ) ) {
    function esc_attr( string $text ): string { // NOSONAR
        return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
    }
}

if ( ! function_exists( 'esc_html' ) ) {
    function esc_html( string $text ): string { // NOSONAR
        return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
    }
}

if ( ! function_exists( 'esc_js' ) ) {
    function esc_js( string $text ): string {
        return addslashes( $text );
    }
}

if ( ! function_exists( 'esc_url' ) ) {
    function esc_url( string $url ): string { // NOSONAR
        return filter_var( $url, FILTER_SANITIZE_URL ) ?: '';
    }
}

if ( ! function_exists( 'wp_nonce_field' ) ) {
    function wp_nonce_field( $action = -1, string $name = '_wpnonce', bool $referer = true, bool $display = true ): string { // NOSONAR
        $field = '<input type="hidden" name="' . htmlspecialchars( $name ) . '" value="nonce_' . htmlspecialchars( (string) $action ) . '" />';
        if ( $display ) {
            echo $field;
        }
        return $field;
    }
}

if ( ! function_exists( 'wp_create_nonce' ) ) {
    function wp_create_nonce( $action = -1 ): string { // NOSONAR
        return 'nonce_' . (string) $action;
    }
}

if ( ! function_exists( 'admin_url' ) ) {
    function admin_url( string $path = '' ): string { // NOSONAR
        return 'https://example.com/wp-admin/' . ltrim( $path, '/' );
    }
}

if ( ! function_exists( 'checked' ) ) {
    function checked( $checked, $current = true, bool $display = true ): string {
        $result = ( (string) $checked === (string) $current ) ? " checked='checked'" : '';
        if ( $display ) {
            echo $result;
        }
        return $result;
    }
}

if ( ! function_exists( 'selected' ) ) {
    function selected( $selected, $current = true, bool $display = true ): string {
        $result = ( (string) $selected === (string) $current ) ? " selected='selected'" : '';
        if ( $display ) {
            echo $result;
        }
        return $result;
    }
}

if ( ! function_exists( 'sanitize_email' ) ) {
    function sanitize_email( string $email ): string { // NOSONAR
        return filter_var( trim( $email ), FILTER_VALIDATE_EMAIL ) ? trim( $email ) : '';
    }
}

if ( ! function_exists( 'is_email' ) ) {
    function is_email( string $email ): bool { // NOSONAR
        return (bool) filter_var( trim( $email ), FILTER_VALIDATE_EMAIL );
    }
}

if ( ! function_exists( 'wp_kses_post' ) ) {
    function wp_kses_post( string $data ): string { // NOSONAR
        return strip_tags( $data, '<a><strong><em><br><p><span>' );
    }
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
    /**
     * Stub for sanitize_text_field().
     *
     * @param string $str String to sanitize.
     * @return string Sanitized string.
     */
    function sanitize_text_field( string $str ): string { // NOSONAR
        return trim( strip_tags( $str ) );
    }
}

if ( ! function_exists( 'sanitize_textarea_field' ) ) {
    function sanitize_textarea_field( string $str ): string {
        return strip_tags( $str );
    }
}

if ( ! function_exists( 'wp_json_encode' ) ) {
    function wp_json_encode( $data, int $options = 0, int $depth = 512 ) {
        return json_encode( $data, $options, $depth );
    }
}

if ( ! function_exists( 'absint' ) ) {
    /**
     * Stub for absint().
     *
     * @param mixed $maybeint Data to convert to a non-negative integer.
     * @return int Non-negative integer.
     */
    function absint( $maybeint ): int {
        return abs( (int) $maybeint );
    }
}

// ---- WP_Error stub ----

if ( ! class_exists( 'WP_Error' ) ) {
    /**
     * Minimal WP_Error stub for unit testing.
     */
    class WP_Error { // NOSONAR
        /**
         * Error codes and messages.
         *
         * @var array
         */
        private array $errors = array();

        /**
         * Error data.
         *
         * @var array
         */
        private array $error_data = array(); // NOSONAR

        /**
         * Constructor.
         *
         * @param string $code    Error code.
         * @param string $message Error message.
         * @param mixed  $data    Optional error data.
         */
        public function __construct( string $code = '', string $message = '', $data = '' ) {
            if ( '' !== $code ) {
                $this->errors[ $code ][] = $message;
                if ( '' !== $data ) {
                    $this->error_data[ $code ] = $data;
                }
            }
        }

        /**
         * Get the first error code.
         *
         * @return string|int Error code or empty string.
         */
        public function get_error_code() { // NOSONAR
            $codes = array_keys( $this->errors );
            return ! empty( $codes ) ? $codes[0] : '';
        }

        /**
         * Get the first error message.
         *
         * @param string $code Optional error code.
         * @return string Error message.
         */
        public function get_error_message( string $code = '' ): string { // NOSONAR
            if ( '' === $code ) {
                $code = $this->get_error_code();
            }
            return $this->errors[ $code ][0] ?? '';
        }

        /**
         * Get all error messages.
         *
         * @param string $code Optional error code.
         * @return array Error messages.
         */
        public function get_error_messages( string $code = '' ): array { // NOSONAR
            if ( '' === $code ) {
                $all = array();
                foreach ( $this->errors as $messages ) {
                    $all = array_merge( $all, $messages );
                }
                return $all;
            }
            return $this->errors[ $code ] ?? array();
        }

        /**
         * Get error data.
         *
         * @param string $code Optional error code.
         * @return mixed Error data.
         */
        public function get_error_data( string $code = '' ) { // NOSONAR
            if ( '' === $code ) {
                $code = $this->get_error_code();
            }
            return $this->error_data[ $code ] ?? null;
        }

        /**
         * Add an error.
         *
         * @param string $code    Error code.
         * @param string $message Error message.
         * @param mixed  $data    Optional error data.
         */
        public function add( string $code, string $message, $data = '' ): void {
            $this->errors[ $code ][] = $message;
            if ( '' !== $data ) {
                $this->error_data[ $code ] = $data;
            }
        }

        /**
         * Check if there are errors.
         *
         * @return bool True if errors exist.
         */
        public function has_errors(): bool { // NOSONAR
            return ! empty( $this->errors );
        }
    }
}

if ( ! function_exists( 'is_wp_error' ) ) {
    /**
     * Check if a value is a WP_Error.
     *
     * @param mixed $thing Value to check.
     * @return bool True if WP_Error.
     */
    function is_wp_error( $thing ): bool { // NOSONAR
        return $thing instanceof WP_Error;
    }
}

// ---- AJAX / Security stubs ----

// Global to control wp_verify_nonce behavior in tests.
global $bpi_test_nonce_valid, $bpi_test_user_can, $bpi_test_json_responses;
$bpi_test_nonce_valid    = true;
$bpi_test_user_can       = true;
$bpi_test_json_responses = array();

if ( ! function_exists( 'wp_verify_nonce' ) ) {
    /**
     * Stub for wp_verify_nonce().
     *
     * @param string $nonce  Nonce to verify.
     * @param string $action Action name.
     * @return false|int False if invalid, 1 or 2 if valid.
     */
    function wp_verify_nonce( $nonce, $action = -1 ) { // NOSONAR
        global $bpi_test_nonce_valid;
        return $bpi_test_nonce_valid ? 1 : false;
    }
}

if ( ! function_exists( 'current_user_can' ) ) {
    /**
     * Stub for current_user_can().
     *
     * @param string $capability Capability to check.
     * @return bool Whether the user has the capability.
     */
    function current_user_can( string $capability ): bool { // NOSONAR
        global $bpi_test_user_can;
        if ( is_array( $bpi_test_user_can ) ) {
            return $bpi_test_user_can[ $capability ] ?? false;
        }
        return (bool) $bpi_test_user_can;
    }
}

if ( ! function_exists( 'wp_send_json_error' ) ) {
    /**
     * Stub for wp_send_json_error().
     *
     * @param mixed $data   Response data.
     * @param int   $status HTTP status code.
     */
    function wp_send_json_error( $data = null, int $status = 200 ): void { // NOSONAR
        global $bpi_test_json_responses;
        $bpi_test_json_responses[] = array(
            'success' => false,
            'data'    => $data,
            'status'  => $status,
        );
    }
}

if ( ! function_exists( 'wp_send_json_success' ) ) {
    /**
     * Stub for wp_send_json_success().
     *
     * @param mixed $data   Response data.
     * @param int   $status HTTP status code.
     */
    function wp_send_json_success( $data = null, int $status = 200 ): void { // NOSONAR
        global $bpi_test_json_responses;
        $bpi_test_json_responses[] = array(
            'success' => true,
            'data'    => $data,
            'status'  => $status,
        );
    }
}

if ( ! function_exists( 'wp_unslash' ) ) {
    /**
     * Stub for wp_unslash().
     *
     * @param string|array $value String or array to unslash.
     * @return string|array Unslashed value.
     */
    function wp_unslash( $value ) { // NOSONAR
        if ( is_array( $value ) ) {
            return array_map( 'wp_unslash', $value );
        }
        return stripslashes( $value );
    }
}

// ---- Transient stubs ----

// In-memory transient storage for testing.
global $bpi_test_transients;
$bpi_test_transients = array();

if ( ! function_exists( 'set_transient' ) ) {
    /**
     * Stub for set_transient().
     *
     * @param string $transient  Transient name.
     * @param mixed  $value      Transient value.
     * @param int    $expiration Time until expiration in seconds (0 = no expiration).
     * @return bool True if the value was set.
     */
    function set_transient( string $transient, $value, int $expiration = 0 ): bool { // NOSONAR
        global $bpi_test_transients;
        $bpi_test_transients[ $transient ] = array(
            'value'      => $value,
            'expiration' => $expiration,
        );
        return true;
    }
}

if ( ! function_exists( 'get_transient' ) ) {
    /**
     * Stub for get_transient().
     *
     * @param string $transient Transient name.
     * @return mixed Transient value or false if not set.
     */
    function get_transient( string $transient ) { // NOSONAR
        global $bpi_test_transients;
        if ( array_key_exists( $transient, $bpi_test_transients ) ) {
            return $bpi_test_transients[ $transient ]['value'];
        }
        return false;
    }
}

if ( ! function_exists( 'delete_transient' ) ) {
    /**
     * Stub for delete_transient().
     *
     * @param string $transient Transient name.
     * @return bool True if the transient was deleted.
     */
    function delete_transient( string $transient ): bool { // NOSONAR
        global $bpi_test_transients;
        if ( array_key_exists( $transient, $bpi_test_transients ) ) {
            unset( $bpi_test_transients[ $transient ] );
            return true;
        }
        return false;
    }
}

// Global to control the WordPress version returned by get_bloginfo().
global $bpi_test_wp_version;
$bpi_test_wp_version = '6.7.0';

if ( ! function_exists( 'get_bloginfo' ) ) {
    /**
     * Stub for get_bloginfo().
     *
     * @param string $show   Site info to retrieve.
     * @param string $filter How to filter what is retrieved.
     * @return string Site info value.
     */
    function get_bloginfo( string $show = '', string $filter = 'raw' ): string { // NOSONAR
        global $bpi_test_wp_version;
        if ( 'version' === $show ) {
            return $bpi_test_wp_version ?? '6.7.0';
        }
        return '';
    }
}

if ( ! function_exists( 'size_format' ) ) {
    /**
     * Stub for size_format().
     *
     * @param int|float $bytes    Number of bytes.
     * @param int       $decimals Number of decimal places.
     * @return string Formatted size string.
     */
    function size_format( $bytes, int $decimals = 0 ): string { // NOSONAR
        $units = array( 'B', 'KB', 'MB', 'GB', 'TB' );
        $bytes = max( $bytes, 0 );
        $pow   = floor( ( $bytes ? log( $bytes ) : 0 ) / log( 1024 ) );
        $pow   = min( $pow, count( $units ) - 1 );
        $bytes /= pow( 1024, $pow );
        return round( $bytes, $decimals ) . ' ' . $units[ $pow ];
    }
}


if ( ! function_exists( 'wp_upload_dir' ) ) {
    /**
     * Stub for wp_upload_dir().
     *
     * @param string|null $time Optional time to base the upload directory on.
     * @return array Upload directory data.
     */
    function wp_upload_dir( $time = null ): array { // NOSONAR
        $base = sys_get_temp_dir() . '/bpi-wp-uploads';
        return array(
            'path'    => $base,
            'url'     => 'https://example.com/wp-content/uploads',
            'subdir'  => '',
            'basedir' => $base,
            'baseurl' => 'https://example.com/wp-content/uploads',
            'error'   => false,
        );
    }
}

if ( ! function_exists( 'sanitize_file_name' ) ) {
    /**
     * Stub for sanitize_file_name().
     *
     * @param string $filename Filename to sanitize.
     * @return string Sanitized filename.
     */
    function sanitize_file_name( string $filename ): string { // NOSONAR
        return preg_replace( '/[^a-zA-Z0-9._-]/', '', $filename );
    }
}

// ---- Filesystem stubs ----

if ( ! function_exists( 'wp_mkdir_p' ) ) {
    /**
     * Stub for wp_mkdir_p().
     *
     * @param string $target Directory path to create.
     * @return bool True on success.
     */
    function wp_mkdir_p( string $target ): bool { // NOSONAR
        if ( is_dir( $target ) ) {
            return true;
        }
        return mkdir( $target, 0755, true );
    }
}

if ( ! function_exists( 'wp_generate_password' ) ) {
    /**
     * Stub for wp_generate_password().
     *
     * @param int  $length              Password length.
     * @param bool $special_chars       Whether to include special characters.
     * @param bool $extra_special_chars Whether to include extra special characters.
     * @return string Generated password.
     */
    function wp_generate_password( int $length = 12, bool $special_chars = true, bool $extra_special_chars = false ): string { // NOSONAR
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $password = '';
        for ( $i = 0; $i < $length; $i++ ) {
            $password .= $chars[ random_int( 0, strlen( $chars ) - 1 ) ];
        }
        return $password;
    }

    function wp_generate_uuid4(): string {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            random_int( 0, 0xffff ), random_int( 0, 0xffff ),
            random_int( 0, 0xffff ),
            random_int( 0, 0x0fff ) | 0x4000,
            random_int( 0, 0x3fff ) | 0x8000,
            random_int( 0, 0xffff ), random_int( 0, 0xffff ), random_int( 0, 0xffff )
        );
    }
}


// ---- Plugin API stubs for Plugin_Processor ----

// Global to control activate_plugin behavior in tests.
global $bpi_test_active_plugins, $bpi_test_installed_plugins;
$bpi_test_active_plugins   = array();
$bpi_test_installed_plugins = array();

if ( ! function_exists( 'activate_plugin' ) ) {
    /**
     * Stub for WordPress activate_plugin().
     *
     * @param string $plugin       Plugin path relative to plugins directory.
     * @param string $redirect     URL to redirect to after activation.
     * @param bool   $network_wide Whether to activate network-wide.
     * @param bool   $silent       Whether to suppress activation hooks.
     * @return \WP_Error|null WP_Error on failure, null on success.
     */
    function activate_plugin( string $plugin, string $redirect = '', bool $network_wide = false, bool $silent = false ): \WP_Error|null { // NOSONAR
        global $bpi_test_active_plugins, $bpi_test_activate_plugin_result;
        if ( isset( $bpi_test_activate_plugin_result ) && is_wp_error( $bpi_test_activate_plugin_result ) ) {
            return $bpi_test_activate_plugin_result;
        }
        $bpi_test_active_plugins[ $plugin ] = true;
        return null;
    }
}

if ( ! function_exists( 'is_plugin_active' ) ) {
    /**
     * Stub for is_plugin_active().
     *
     * @param string $plugin Plugin path relative to plugins directory.
     * @return bool True if the plugin is active.
     */
    function is_plugin_active( string $plugin ): bool { // NOSONAR
        global $bpi_test_active_plugins;
        return ! empty( $bpi_test_active_plugins[ $plugin ] );
    }
}

if ( ! function_exists( 'get_plugins' ) ) {
    /**
     * Stub for get_plugins().
     *
     * @param string $plugin_folder Optional plugin folder to search.
     * @return array Array of plugin data.
     */
    function get_plugins( string $plugin_folder = '' ): array { // NOSONAR
        global $bpi_test_installed_plugins;
        return $bpi_test_installed_plugins ?? array();
    }
}

// Minimal Plugin_Upgrader stub.
if ( ! class_exists( 'Plugin_Upgrader' ) ) {
    /**
     * Minimal Plugin_Upgrader stub for unit testing.
     */
    class Plugin_Upgrader { // NOSONAR
        public $skin;
        public $result;

        public function __construct( $skin = null ) {
            $this->skin = $skin;
        }

        public function install( string $package, array $args = array() ) { // NOSONAR
            return $this->result ?? true;
        }

        public function upgrade( string $plugin, array $args = array() ) { // NOSONAR
            return $this->result ?? true;
        }
    }
}

// Minimal WP_Ajax_Upgrader_Skin stub.
if ( ! class_exists( 'WP_Ajax_Upgrader_Skin' ) ) {
    class WP_Ajax_Upgrader_Skin { // NOSONAR
        public function __construct( $args = array() ) { /* WP API stub */ } // NOSONAR
        public function get_errors(): \WP_Error { // NOSONAR
            return new \WP_Error();
        }
    }
}


// ---- wp_mail stub for Notification_Manager tests ----

// Track wp_mail calls for test assertions.
global $bpi_test_emails;
$bpi_test_emails = array();

if ( ! function_exists( 'wp_mail' ) ) {
    /**
     * Stub for wp_mail().
     *
     * Records each call in $bpi_test_emails for test assertions.
     *
     * @param string|array $to          Recipient(s).
     * @param string       $subject     Email subject.
     * @param string       $message     Email body.
     * @param string|array $headers     Optional headers.
     * @param string|array $attachments Optional attachments.
     * @return bool True (always succeeds in tests).
     */
    function wp_mail( $to, string $subject, string $message, $headers = '', $attachments = array() ): bool { // NOSONAR
        global $bpi_test_emails;
        $bpi_test_emails[] = array(
            'to'          => is_array( $to ) ? $to : array( $to ),
            'subject'     => $subject,
            'message'     => $message,
            'headers'     => $headers,
            'attachments' => $attachments,
        );
        return true;
    }
}

// ---- wp_get_current_user stub for Notification_Manager ----

if ( ! function_exists( 'wp_get_current_user' ) ) {
    /**
     * Stub for wp_get_current_user().
     *
     * @return object Minimal user object.
     */
    function wp_get_current_user(): object { // NOSONAR
        global $bpi_test_current_user_login, $bpi_test_current_user_email;
        return (object) array(
            'ID'           => get_current_user_id(),
            'user_login'   => $bpi_test_current_user_login ?? 'admin',
            'user_email'   => $bpi_test_current_user_email ?? 'admin@example.com',
            'display_name' => $bpi_test_current_user_login ?? 'admin',
        );
    }
}


// ---- Admin page stubs for BPIAdminPage tests ----

// Track registered submenu pages for test assertions.
global $bpi_test_submenu_pages;
$bpi_test_submenu_pages = array();

if ( ! function_exists( 'add_submenu_page' ) ) {
    /**
     * Stub for add_submenu_page().
     *
     * @param string   $parent_slug The slug name for the parent menu.
     * @param string   $page_title  The text to be displayed in the title tags.
     * @param string   $menu_title  The text to be used for the menu.
     * @param string   $capability  The capability required for this menu.
     * @param string   $menu_slug   The slug name to refer to this menu by.
     * @param callable $callback    The function to be called to output the content.
     * @param int|null $position    The position in the menu order.
     * @return string|false The resulting page's hook_suffix, or false if the user lacks capability.
     */
    function add_submenu_page( string $parent_slug, string $page_title, string $menu_title, string $capability, string $menu_slug, $callback = '', $position = null ) { // NOSONAR
        global $bpi_test_submenu_pages;
        $bpi_test_submenu_pages[ $menu_slug ] = array(
            'parent_slug' => $parent_slug,
            'page_title'  => $page_title,
            'menu_title'  => $menu_title,
            'capability'  => $capability,
            'menu_slug'   => $menu_slug,
            'callback'    => $callback,
            'position'    => $position,
        );
        // Return a hook suffix matching WordPress convention.
        return 'plugins_page_' . $menu_slug;
    }
}

if ( ! function_exists( 'add_filter' ) ) {
    /**
     * Stub for add_filter().
     *
     * @param string   $hook_name     The name of the filter to add the callback to.
     * @param callable $callback      The callback to be run when the filter is applied.
     * @param int      $priority      Priority of the filter.
     * @param int      $accepted_args Number of arguments the callback accepts.
     * @return true Always returns true.
     */
    function add_filter( string $hook_name, $callback, int $priority = 10, int $accepted_args = 1 ): bool { // NOSONAR
        global $bpi_test_hooks;
        $bpi_test_hooks[] = array(
            'type'          => 'filter',
            'hook'          => $hook_name,
            'callback'      => $callback,
            'priority'      => $priority,
            'accepted_args' => $accepted_args,
        );
        return true;
    }
}

// ---- Asset enqueue stubs ----

// Track enqueued scripts and styles for test assertions.
global $bpi_test_enqueued_styles, $bpi_test_enqueued_scripts, $bpi_test_localized_scripts;
$bpi_test_enqueued_styles     = array();
$bpi_test_enqueued_scripts    = array();
$bpi_test_localized_scripts   = array();

if ( ! function_exists( 'wp_enqueue_style' ) ) {
    /**
     * Stub for wp_enqueue_style().
     *
     * @param string           $handle Name of the stylesheet.
     * @param string           $src    Full URL of the stylesheet.
     * @param array            $deps   Array of registered stylesheet handles.
     * @param string|bool|null $ver    Stylesheet version number.
     * @param string           $media  Media for which this stylesheet has been defined.
     */
    function wp_enqueue_style( string $handle, string $src = '', array $deps = array(), $ver = false, string $media = 'all' ): void { // NOSONAR
        global $bpi_test_enqueued_styles;
        $bpi_test_enqueued_styles[ $handle ] = array(
            'handle' => $handle,
            'src'    => $src,
            'deps'   => $deps,
            'ver'    => $ver,
            'media'  => $media,
        );
    }
}

if ( ! function_exists( 'wp_enqueue_script' ) ) {
    /**
     * Stub for wp_enqueue_script().
     *
     * @param string           $handle    Name of the script.
     * @param string           $src       Full URL of the script.
     * @param array            $deps      Array of registered script handles.
     * @param string|bool|null $ver       Script version number.
     * @param bool|array       $in_footer Whether to enqueue in footer.
     */
    function wp_enqueue_script( string $handle, string $src = '', array $deps = array(), $ver = false, $in_footer = false ): void { // NOSONAR
        global $bpi_test_enqueued_scripts;
        $bpi_test_enqueued_scripts[ $handle ] = array(
            'handle'    => $handle,
            'src'       => $src,
            'deps'      => $deps,
            'ver'       => $ver,
            'in_footer' => $in_footer,
        );
    }
}

if ( ! function_exists( 'wp_localize_script' ) ) {
    /**
     * Stub for wp_localize_script().
     *
     * @param string $handle      Script handle the data will be attached to.
     * @param string $object_name Name for the JavaScript object.
     * @param array  $l10n        The data itself.
     * @return bool True on success, false on failure.
     */
    function wp_localize_script( string $handle, string $object_name, array $l10n ): bool { // NOSONAR
        global $bpi_test_localized_scripts;
        $bpi_test_localized_scripts[ $handle ] = array(
            'handle'      => $handle,
            'object_name' => $object_name,
            'data'        => $l10n,
        );
        return true;
    }
}


// ---- Multisite stubs for Network Admin support ----

// Globals to control multisite behavior in tests.
global $bpi_test_is_multisite, $bpi_test_is_network_admin;
$bpi_test_is_multisite     = false;
$bpi_test_is_network_admin = false;

if ( ! function_exists( 'is_multisite' ) ) {
    /**
     * Stub for is_multisite().
     *
     * @return bool Whether WordPress is running in multisite mode.
     */
    function is_multisite(): bool { // NOSONAR
        global $bpi_test_is_multisite;
        return (bool) $bpi_test_is_multisite;
    }
}

if ( ! function_exists( 'is_network_admin' ) ) {
    /**
     * Stub for is_network_admin().
     *
     * @return bool Whether the current screen is the Network Admin.
     */
    function is_network_admin(): bool { // NOSONAR
        global $bpi_test_is_network_admin;
        return (bool) $bpi_test_is_network_admin;
    }
}

if ( ! function_exists( 'network_admin_url' ) ) {
    /**
     * Stub for network_admin_url().
     *
     * @param string $path Optional path relative to the network admin URL.
     * @return string Network admin URL.
     */
    function network_admin_url( string $path = '' ): string { // NOSONAR
        return 'https://example.com/wp-admin/network/' . ltrim( $path, '/' );
    }
}


// ---- WP-CLI stubs for CLI_Interface tests ----

// Track WP_CLI calls for test assertions.
global $bpi_test_cli_commands, $bpi_test_cli_log, $bpi_test_cli_halt_code, $bpi_test_cli_format_items_calls;
$bpi_test_cli_commands          = array();
$bpi_test_cli_log               = array();
$bpi_test_cli_halt_code         = null;
$bpi_test_cli_format_items_calls = array();

if ( ! class_exists( 'WP_CLI' ) ) {
    /**
     * Minimal WP_CLI stub for unit testing.
     */
    class WP_CLI { // NOSONAR

        /**
         * Register a WP-CLI command.
         *
         * @param string          $name     Command name.
         * @param callable|string $callable Command handler.
         * @param array           $args     Command arguments.
         */
        public static function add_command( string $name, $callable, array $args = array() ): void { // NOSONAR
            global $bpi_test_cli_commands;
            $bpi_test_cli_commands[] = array(
                'name'     => $name,
                'callable' => $callable,
                'args'     => $args,
            );
        }

        /**
         * Log a message.
         *
         * @param string $message Message to log.
         */
        public static function log( string $message ): void {
            global $bpi_test_cli_log;
            $bpi_test_cli_log[] = array( 'type' => 'log', 'message' => $message );
        }

        /**
         * Log a success message.
         *
         * @param string $message Message to log.
         */
        public static function success( string $message ): void {
            global $bpi_test_cli_log;
            $bpi_test_cli_log[] = array( 'type' => 'success', 'message' => $message );
        }

        /**
         * Log a warning message.
         *
         * @param string $message Message to log.
         */
        public static function warning( string $message ): void {
            global $bpi_test_cli_log;
            $bpi_test_cli_log[] = array( 'type' => 'warning', 'message' => $message );
        }

        /**
         * Log an error message.
         *
         * @param string $message Message to log.
         * @param bool   $exit    Whether to exit (ignored in tests).
         */
        public static function error( string $message, bool $exit = true ): void { // NOSONAR
            global $bpi_test_cli_log;
            $bpi_test_cli_log[] = array( 'type' => 'error', 'message' => $message );
        }

        /**
         * Output a line.
         *
         * @param string $message Message to output.
         */
        public static function line( string $message = '' ): void {
            global $bpi_test_cli_log;
            $bpi_test_cli_log[] = array( 'type' => 'line', 'message' => $message );
        }

        /**
         * Halt execution with an exit code.
         *
         * @param int $code Exit code.
         */
        public static function halt( int $code ): void {
            global $bpi_test_cli_halt_code;
            $bpi_test_cli_halt_code = $code;
        }
    }
}

// Load WP_CLI\Utils namespace stubs from a separate file.
require_once __DIR__ . '/wp-cli-utils-stubs.php'; // NOSONAR
