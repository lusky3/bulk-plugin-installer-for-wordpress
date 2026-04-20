# Bulk Plugin Installer

[![Lint](../../actions/workflows/lint.yml/badge.svg)](../../actions/workflows/lint.yml)
[![Tests](../../actions/workflows/test.yml/badge.svg)](../../actions/workflows/test.yml)
[![Security](../../actions/workflows/security.yml/badge.svg)](../../actions/workflows/security.yml)
[![Integration](../../actions/workflows/integration.yml/badge.svg)](../../actions/workflows/integration.yml)
[![PHP Version](https://img.shields.io/badge/php-%3E%3D8.3-8892BF.svg)](https://php.net)
[![WordPress](https://img.shields.io/badge/wordpress-%3E%3D5.8-21759B.svg)](https://wordpress.org)
[![License](https://img.shields.io/badge/license-GPL--2.0--or--later-blue.svg)](LICENSE)
[![PRs Welcome](https://img.shields.io/badge/PRs-welcome-brightgreen.svg)](../../pulls)

Upload and install multiple WordPress plugin ZIP files in a single operation with preview, rollback, and profile support.

## Features

- Drag-and-drop bulk upload of multiple plugin ZIP files
- Preview screen with compatibility checks before installation
- Sequential processing with per-plugin status tracking
- Automatic rollback on failed updates with batch rollback support
- Installation profiles for repeatable plugin sets
- Dry run mode to simulate installations without changes
- Changelog extraction with semantic version classification
- Email notifications for batch operations
- WP-CLI integration (`wp bulk-plugin install`)
- WordPress Multisite / Network Admin support
- Activity logging with filterable log viewer
- Configurable settings: auto-activate, max file size, rollback retention
- Self-updating via GitHub Releases

## Requirements

- WordPress 5.8 or later
- PHP 8.3 or later

## Installation

1. Download the latest release ZIP from [Releases](../../releases)
2. In WordPress admin, go to Plugins > Add New > Upload Plugin
3. Upload the ZIP file and activate

Or install via WP-CLI:

```bash
wp plugin install bulk-plugin-installer.zip --activate
```

The plugin checks GitHub for updates automatically and will notify you when a new version is available.

## Usage

1. Navigate to Plugins > Add New > Bulk Upload
2. Drag and drop your plugin ZIP files (or click to browse)
3. Review the preview screen — check compatibility warnings and changelogs
4. Select which plugins to install and click "Install Selected"
5. Monitor per-plugin progress and review the summary

### WP-CLI

```bash
# Install from ZIP files
wp bulk-plugin install plugin-a.zip plugin-b.zip

# Install from a saved profile
wp bulk-plugin install --profile=my-stack

# Dry run
wp bulk-plugin install plugin-a.zip --dry-run

# Skip confirmation
wp bulk-plugin install plugin-a.zip --yes
```

## Development

```bash
# Install dependencies
composer install

# Run all tests
php -d memory_limit=256M vendor/bin/phpunit --no-coverage

# Run unit tests only
vendor/bin/phpunit --testsuite unit

# Run property-based tests only
php -d memory_limit=256M vendor/bin/phpunit --testsuite property
```

## Testing

The test suite includes 480+ tests with 60,000+ assertions:

- Unit tests covering all components
- Property-based tests (using Eris) validating correctness invariants

## Permissions

All plugin functionality requires the `install_plugins` capability (Administrators). No public-facing endpoints exist — unauthenticated users cannot interact with the plugin.

| Context | Required Capability | Role |
|---------|-------------------|------|
| Single site | `install_plugins` | Administrator |
| Multisite (Network Admin) | `manage_network_plugins` | Super Admin |

On individual subsites within a multisite network, the standard `install_plugins` capability applies.

## Hooks & Extension Points

The plugin provides actions and filters for third-party developers:

- `bpi_before_process_batch` / `bpi_after_process_batch` — batch lifecycle
- `bpi_process_plugin_result` — filter individual plugin results
- `bpi_before_batch_rollback` / `bpi_after_batch_rollback` — rollback lifecycle
- `bpi_validate_zip` — add custom ZIP validation rules
- `bpi_preview_items` — filter preview data before display
- `bpi_batch_email_subject` / `bpi_batch_email_body` — customize notification emails
- `bpi_rollback_email_subject` / `bpi_rollback_email_body` — customize rollback emails

## Contributing

See [CONTRIBUTING.md](.github/CONTRIBUTING.md) for guidelines.

## Security

See [SECURITY.md](.github/SECURITY.md) for reporting vulnerabilities.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE) for the full text.

## AI Usage Disclaimer

Portions of this codebase were generated with the assistance of Large Language Models (LLMs). All AI-generated code has been reviewed and tested to ensure quality and correctness.
