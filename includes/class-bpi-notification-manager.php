<?php
/**
 * Notification Manager for Bulk Plugin Installer.
 *
 * Sends email summaries and queues admin notices after bulk operations.
 *
 * @package BulkPluginInstaller
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class BPINotificationManager
 *
 * Handles email notifications and WordPress admin notices for batch
 * install/update and rollback operations.
 *
 * @since 1.0.0
 */
class BPINotificationManager {

    /**
     * Settings manager instance.
     *
     * @var BPISettingsManager
     */
    private BPISettingsManager $settings;

    /**
     * Constructor.
     *
     * @since 1.0.0
     *
     * @param BPISettingsManager $settings Settings manager dependency.
     */
    public function __construct( BPISettingsManager $settings ) {
        $this->settings = $settings;
    }

    /**
     * Register WordPress hooks for displaying admin notices.
     *
     * @since 1.0.0
     */
    public function registerHooks(): void {
        add_action( 'admin_notices', array( $this, 'displayAdminNotices' ) );
    }

    /**
     * Send an email summary after a batch operation completes.
     *
     * Only sends when the `bpi_email_notifications` setting is enabled.
     *
     * @param array $batch_summary {
     *     Batch summary data.
     *
     *     @type string $timestamp Batch completion timestamp.
     *     @type int    $user_id   WordPress user ID who ran the batch.
     *     @type array  $plugins   Array of plugin results, each with slug, name, action, status.
     *     @type array  $summary   Counts: total, installed, updated, failed.
     * }
     *
     * @since 1.0.0
     */
    public function sendBatchEmail( array $batch_summary ): void {
        if ( ! $this->settings->getOption( 'bpi_email_notifications' ) ) {
            return;
        }

        $recipients = $this->getEmailRecipients();
        if ( empty( $recipients ) ) {
            return;
        }

        $user      = wp_get_current_user();
        $user_name = $user->user_login ?? __( 'Unknown', 'bulk-plugin-installer' );
        $timestamp = $batch_summary['timestamp'] ?? current_time( 'mysql' );
        $plugins   = $batch_summary['plugins'] ?? array();
        $summary   = $batch_summary['summary'] ?? array();

        $subject = __( 'Bulk Plugin Installer: Batch Complete', 'bulk-plugin-installer' );

        $body  = sprintf(
            /* translators: %s: timestamp */
            __( 'Timestamp: %s', 'bulk-plugin-installer' ),
            $timestamp
        ) . "\n";
        $body .= sprintf(
            /* translators: %s: admin username */
            __( 'Admin User: %s', 'bulk-plugin-installer' ),
            $user_name
        ) . "\n\n";

        $body .= __( 'Plugins Processed:', 'bulk-plugin-installer' ) . "\n";
        $body .= str_repeat( '-', 40 ) . "\n";

        foreach ( $plugins as $plugin ) {
            $name   = $plugin['name'] ?? $plugin['slug'] ?? __( 'Unknown', 'bulk-plugin-installer' );
            $action = $plugin['action'] ?? '';
            $status = $plugin['status'] ?? '';
            $body  .= sprintf( '- %s (%s): %s', $name, $action, $status ) . "\n";
        }

        $body .= "\n" . __( 'Summary:', 'bulk-plugin-installer' ) . "\n";
        $body .= sprintf(
            /* translators: %d: total count */
            __( 'Total: %d', 'bulk-plugin-installer' ),
            $summary['total'] ?? 0
        ) . "\n";
        $body .= sprintf(
            /* translators: %d: installed count */
            __( 'Installed: %d', 'bulk-plugin-installer' ),
            $summary['installed'] ?? 0
        ) . "\n";
        $body .= sprintf(
            /* translators: %d: updated count */
            __( 'Updated: %d', 'bulk-plugin-installer' ),
            $summary['updated'] ?? 0
        ) . "\n";
        $body .= sprintf(
            /* translators: %d: failed count */
            __( 'Failed: %d', 'bulk-plugin-installer' ),
            $summary['failed'] ?? 0
        ) . "\n";

        $subject = apply_filters( 'bpi_batch_email_subject', $subject, $batch_summary );
        $body    = apply_filters( 'bpi_batch_email_body', $body, $batch_summary );

        wp_mail( $recipients, $subject, $body );
    }

    /**
     * Send an email notification after a batch rollback.
     *
     * Only sends when the `bpi_email_notifications` setting is enabled.
     *
     * @param array $rollback_summary {
     *     Rollback summary data.
     *
     *     @type string $batch_id  The batch identifier.
     *     @type string $timestamp Rollback timestamp.
     *     @type int    $user_id   WordPress user ID who triggered rollback.
     *     @type array  $plugins   Array of plugin rollback results with slug, name, status.
     *     @type string $reason    Reason for rollback.
     * }
     *
     * @since 1.0.0
     */
    public function sendRollbackEmail( array $rollback_summary ): void {
        if ( ! $this->settings->getOption( 'bpi_email_notifications' ) ) {
            return;
        }

        $recipients = $this->getEmailRecipients();
        if ( empty( $recipients ) ) {
            return;
        }

        $user      = wp_get_current_user();
        $user_name = $user->user_login ?? __( 'Unknown', 'bulk-plugin-installer' );
        $timestamp = $rollback_summary['timestamp'] ?? current_time( 'mysql' );
        $batch_id  = $rollback_summary['batch_id'] ?? '';
        $plugins   = $rollback_summary['plugins'] ?? array();
        $reason    = $rollback_summary['reason'] ?? '';

        $subject = __( 'Bulk Plugin Installer: Batch Rollback', 'bulk-plugin-installer' );

        $body  = sprintf(
            /* translators: %s: timestamp */
            __( 'Timestamp: %s', 'bulk-plugin-installer' ),
            $timestamp
        ) . "\n";
        $body .= sprintf(
            /* translators: %s: admin username */
            __( 'Admin User: %s', 'bulk-plugin-installer' ),
            $user_name
        ) . "\n";

        if ( $batch_id ) {
            $body .= sprintf(
                /* translators: %s: batch ID */
                __( 'Batch ID: %s', 'bulk-plugin-installer' ),
                $batch_id
            ) . "\n";
        }

        if ( $reason ) {
            $body .= sprintf(
                /* translators: %s: rollback reason */
                __( 'Reason: %s', 'bulk-plugin-installer' ),
                $reason
            ) . "\n";
        }

        $body .= "\n" . __( 'Plugins Rolled Back:', 'bulk-plugin-installer' ) . "\n";
        $body .= str_repeat( '-', 40 ) . "\n";

        foreach ( $plugins as $plugin ) {
            $name   = $plugin['name'] ?? $plugin['slug'] ?? __( 'Unknown', 'bulk-plugin-installer' );
            $status = $plugin['status'] ?? '';
            $body  .= sprintf( '- %s: %s', $name, $status ) . "\n";
        }

        $subject = apply_filters( 'bpi_rollback_email_subject', $subject, $rollback_summary );
        $body    = apply_filters( 'bpi_rollback_email_body', $body, $rollback_summary );

        wp_mail( $recipients, $subject, $body );
    }

    /**
     * Queue an admin notice for display on the next page load.
     *
     * Stores the notice in a transient keyed by user ID so it persists
     * across the redirect after a bulk operation.
     *
     * @param string $message Notice message text.
     * @param string $type    Notice type: 'success', 'error', 'warning', 'info'.
     *
     * @since 1.0.0
     */
    public function queueAdminNotice( string $message, string $type = 'success' ): void {
        $user_id       = get_current_user_id();
        $transient_key = 'bpi_admin_notices_' . $user_id;
        $notices       = get_transient( $transient_key );

        if ( ! is_array( $notices ) ) {
            $notices = array();
        }

        $notices[] = array(
            'message' => $message,
            'type'    => $type,
        );

        set_transient( $transient_key, $notices, 300 );
    }

    /**
     * Display queued admin notices and clear the transient.
     *
     * Hooked to `admin_notices` via registerHooks().
     *
     * @since 1.0.0
     */
    public function displayAdminNotices(): void {
        $user_id       = get_current_user_id();
        $transient_key = 'bpi_admin_notices_' . $user_id;
        $notices       = get_transient( $transient_key );

        if ( ! is_array( $notices ) || empty( $notices ) ) {
            return;
        }

        foreach ( $notices as $notice ) {
            $allowed_types = array( 'success', 'error', 'warning', 'info' );
            $type          = in_array( $notice['type'] ?? '', $allowed_types, true ) ? $notice['type'] : 'info';
            $type          = esc_attr( $type );
            $message       = wp_kses_post( $notice['message'] ?? '' );
            printf(
                '<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
                $type,
                $message
            );
        }

        delete_transient( $transient_key );
    }

    /**
     * Get the list of email recipients for notifications.
     *
     * Returns the site admin email plus any additional recipients
     * configured in the `bpi_email_recipients` setting.
     *
     * @return array List of email addresses.
     *
     * @since 1.0.0
     */
    public function getEmailRecipients(): array {
        $recipients = array();

        $admin_email = get_option( 'admin_email', '' );
        if ( $admin_email && is_email( $admin_email ) ) {
            $recipients[] = $admin_email;
        }

        $additional = $this->settings->getOption( 'bpi_email_recipients' );
        if ( ! empty( $additional ) ) {
            $extras = array_map( 'trim', explode( ',', $additional ) );
            foreach ( $extras as $email ) {
                if ( $email && is_email( $email ) && ! in_array( $email, $recipients, true ) ) {
                    $recipients[] = $email;
                }
            }
        }

        return $recipients;
    }
}
