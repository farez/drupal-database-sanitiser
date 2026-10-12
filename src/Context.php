<?php

declare(strict_types=1);

namespace SanitizeTestDb;

use Drupal\Core\Config\StorageInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Everything a step needs: settings, command options and Drupal services.
 */
final class Context {

  public function __construct(
    public readonly Settings $settings,
    public readonly array $options,
    public readonly Connection $database,
  ) {}

  /**
   * Creates a context using the current Drupal database connection.
   */
  public static function create(Settings $settings, array $options): self {
    return new self($settings, $options, \Drupal::database());
  }

  /**
   * Username for uid 1.
   */
  public function adminName(): string {
    return (string) ($this->options['sanitize-admin-name'] ?? 'admin');
  }

  /**
   * Email for uid 1, also used to replace emails in config.
   */
  public function adminEmail(): string {
    return (string) ($this->options['sanitize-admin-email'] ?? 'admin@example.com');
  }

  /**
   * Domain treated as "already sanitised"; also used for field rule emails.
   */
  public function emailDomain(): string {
    $domain = (string) ($this->settings->get('email_domain') ?: substr(strrchr($this->adminEmail(), '@') ?: '@example.com', 1));
    if (!preg_match('/^[a-z0-9.-]+$/i', $domain)) {
      throw new \InvalidArgumentException("Invalid email_domain '$domain'.");
    }
    return strtolower($domain);
  }

  /**
   * Drush convention: an option is disabled by 'no' or '0'.
   */
  public function enabled(string $option, bool $default = TRUE): bool {
    if (!array_key_exists($option, $this->options) || $this->options[$option] === NULL) {
      return $default;
    }
    $value = $this->options[$option];
    return $value !== FALSE && $value !== 'no' && $value !== '0' && $value !== 0;
  }

  /**
   * Whether a (prefix-less) table exists.
   */
  public function tableExists(string $table): bool {
    return $this->database->schema()->tableExists($table);
  }

  /**
   * Number of rows in a table.
   */
  public function rowCount(string $table): int {
    return (int) $this->database->select($table)->countQuery()->execute()->fetchField();
  }

  /**
   * Whether a module is enabled.
   */
  public function moduleExists(string $module): bool {
    return \Drupal::moduleHandler()->moduleExists($module);
  }

  /**
   * The entity type manager.
   */
  public function entityTypeManager(): EntityTypeManagerInterface {
    return \Drupal::entityTypeManager();
  }

  /**
   * The entity field manager.
   */
  public function entityFieldManager(): EntityFieldManagerInterface {
    return \Drupal::service('entity_field.manager');
  }

  /**
   * Bundles of an entity type, keyed by bundle name.
   */
  public function bundleInfo(string $entity_type_id): array {
    return \Drupal::service('entity_type.bundle.info')->getBundleInfo($entity_type_id);
  }

  /**
   * Active config storage for every collection, keyed by collection name.
   *
   * @return array<string, StorageInterface>
   */
  public function configStorages(): array {
    /** @var \Drupal\Core\Config\StorageInterface $storage */
    $storage = \Drupal::service('config.storage');
    $storages = [StorageInterface::DEFAULT_COLLECTION => $storage];
    foreach ($storage->getAllCollectionNames() as $collection) {
      $storages[$collection] = $storage->createCollection($collection);
    }
    return $storages;
  }

  /**
   * Email scrubber configured for this run.
   */
  public function emailScrubber(): EmailScrubber {
    return new EmailScrubber($this->adminEmail(), $this->emailDomain(), $this->settings->list('keep_emails'));
  }

  /**
   * SQL expression concatenating a column between two literal arguments.
   */
  public function concatExpression(string $column): string {
    return $this->database->driver() === 'mysql'
      ? "CONCAT(:sanitize_prefix, $column, :sanitize_suffix)"
      : "(:sanitize_prefix || $column || :sanitize_suffix)";
  }

}
