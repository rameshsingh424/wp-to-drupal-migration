<?php

namespace Drupal\wp_to_drupal_migration\Commands;

use Drupal\wp_to_drupal_migration\Service\WordPressMigrationService;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for WordPress to Drupal migration.
 */
final class WordPressToDrupalMigrationCommands extends DrushCommands {

  /**
   * Constructs the Drush command class.
   */
  public function __construct(
    private readonly WordPressMigrationService $migrationService,
  ) {
    parent::__construct();
  }

  /**
   * Migrates WordPress content into Drupal.
   *
   * @command wp-to-drupal:migrate
   * @aliases wpdm
   *
   * @option limit Maximum number of WordPress posts to process.
   * @option offset Offset for batch migration.
   * @option post-type Limit migration to a WordPress post type.
   * @option update Update existing Drupal nodes created from the same WordPress post ID.
   * @option skip-attachments Skip the initial full attachment indexing pass.
   *
   * @usage drush wp-to-drupal:migrate --update=1
   * @usage drush wp-to-drupal:migrate --post-type=post --limit=100 --offset=0 --update=1
   */
  public function migrate(array $options = [
    'limit' => NULL,
    'offset' => 0,
    'post-type' => NULL,
    'update' => FALSE,
    'skip-attachments' => FALSE,
  ]): void {
    $result = $this->migrationService->migrate([
      'limit' => $options['limit'] !== NULL ? (int) $options['limit'] : NULL,
      'offset' => (int) ($options['offset'] ?? 0),
      'post_type' => $options['post-type'] ?: NULL,
      'update' => (bool) $options['update'],
      'skip_attachments' => (bool) $options['skip-attachments'],
    ]);

    $this->logger()->success(sprintf(
      'Migration finished. Created: %d. Updated: %d. Skipped: %d. Failed: %d. Attachments indexed: %d.',
      $result['created'],
      $result['updated'],
      $result['skipped'],
      $result['failed'],
      $result['attachments_indexed'],
    ));
  }

  /**
   * Clears migration tracking state keys.
   *
   * @command wp-to-drupal:reset-state
   * @aliases wpdm-reset
   *
   * @option type Reset type: all, post, or attachment.
   *
   * @usage drush wp-to-drupal:reset-state
   * @usage drush wp-to-drupal:reset-state --type=post
   * @usage drush wp-to-drupal:reset-state --type=attachment
   */
  public function resetState(array $options = [
    'type' => 'all',
  ]): void {
    $deleted = $this->migrationService->resetState((string) ($options['type'] ?? 'all'));

    $this->logger()->success(sprintf('Deleted %d migration tracking state key(s).', $deleted));
  }

}
