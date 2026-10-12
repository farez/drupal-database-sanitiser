<?php

declare(strict_types=1);

namespace SanitizeTestDb\Step;

use Drupal\Core\Entity\Sql\SqlEntityStorageInterface;
use SanitizeTestDb\Context;

/**
 * Applies per-site field rules: "entity_type[.bundle].field_name: action".
 *
 * Actions: email, phone, text, text:<template>, blank, keep. "%id" in a text
 * template becomes the entity ID. A bundle in the key limits the rule to that
 * bundle (e.g. one vocabulary). Works on any fieldable entity type by
 * resolving tables and the main property column from the table mapping, and
 * updates the data and revision tables directly.
 */
final class FieldRulesStep extends StepBase {

  public const ACTIONS = ['email', 'phone', 'text', 'blank', 'keep'];

  public const PHONE = '020 7946 0000';

  public const TEXT = 'Sanitised text';

  /**
   * {@inheritdoc}
   */
  public function label(): string {
    return 'Field rules: sanitise listed content fields';
  }

  /**
   * {@inheritdoc}
   */
  public function applies(Context $context): bool {
    return $context->settings->fieldRules() !== [];
  }

  /**
   * {@inheritdoc}
   */
  public function errors(Context $context): array {
    $errors = [];
    foreach ($context->settings->fieldRules() as $key => $action) {
      try {
        $this->resolve($context, (string) $key, (string) $action);
      }
      catch (\InvalidArgumentException $e) {
        $errors[] = $e->getMessage();
      }
    }
    return $errors;
  }

  /**
   * {@inheritdoc}
   */
  public function plan(Context $context): array {
    $lines = [];
    foreach ($this->targets($context) as $target) {
      $count = $this->pending($context, $target)->countQuery()->execute()->fetchField();
      $action = $target['action'] === 'text' ? "'{$target['text']}'" : $target['action'];
      $lines[] = "{$target['key']} → $action: $count values in {$target['table']}";
    }
    return $lines ?: ['only "keep" rules'];
  }

  /**
   * {@inheritdoc}
   */
  public function apply(Context $context): void {
    foreach ($this->targets($context) as $target) {
      $table = $target['table'];
      $column = $target['column'];
      switch ($target['action']) {
        case 'email':
        case 'text':
          $query = $context->database->update($table)->isNotNull($column);
          if ($affixes = $this->affixes($context, $target)) {
            $query->expression($column, $context->concatExpression($target['id_column']), $affixes);
          }
          else {
            $query->fields([$column => $target['text']]);
          }
          $this->scope($context, $query, $target);
          $query->execute();
          break;

        case 'phone':
          $query = $context->database->update($table)->fields([$column => self::PHONE])->isNotNull($column);
          $this->scope($context, $query, $target);
          $query->execute();
          break;

        case 'blank':
          $query = $target['dedicated']
            ? $context->database->delete($table)
            : $context->database->update($table)->fields([$column => NULL]);
          $this->scope($context, $query, $target);
          $query->execute();
          break;
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function verify(Context $context): array {
    $failures = [];
    foreach ($this->targets($context) as $target) {
      if ($count = $this->pending($context, $target)->countQuery()->execute()->fetchField()) {
        $failures[] = "{$target['key']}: $count values in {$target['table']} not sanitised";
      }
    }
    return $failures;
  }

  /**
   * Rows that still need the rule applied.
   */
  private function pending(Context $context, array $target) {
    $column = $target['column'];
    $query = $context->database->select($target['table'], 't')->isNotNull("t.$column");
    $this->scope($context, $query, $target, 't.');
    switch ($target['action']) {
      case 'email':
        $query->condition("t.$column", 'user+%@' . $context->database->escapeLike($context->emailDomain()), 'NOT LIKE');
        break;

      case 'phone':
        $query->condition("t.$column", self::PHONE, '<>');
        break;

      case 'text':
        if ($affixes = $this->affixes($context, $target)) {
          $query->where("t.$column <> " . $context->concatExpression("t.{$target['id_column']}"), $affixes);
        }
        else {
          $query->condition("t.$column", $target['text'], '<>');
        }
        break;
    }
    return $query;
  }

  /**
   * Prefix and suffix around the entity ID, or [] for a fixed value.
   *
   * @return array<string, string>
   */
  private function affixes(Context $context, array $target): array {
    if ($target['action'] === 'email') {
      return [':sanitize_prefix' => 'user+', ':sanitize_suffix' => '@' . $context->emailDomain()];
    }
    if (!str_contains($target['text'], '%id')) {
      return [];
    }
    [$prefix, $suffix] = explode('%id', $target['text'], 2);
    return [':sanitize_prefix' => $prefix, ':sanitize_suffix' => $suffix];
  }

  /**
   * Limits a query to the rule's bundle, if it has one.
   *
   * Revision tables of shared storage have no bundle column, so those are
   * matched by ID against the base table.
   *
   * @param \Drupal\Core\Database\Query\ConditionInterface $query
   *   A select, update or delete query on the target table.
   */
  private function scope(Context $context, $query, array $target, string $alias = ''): void {
    if ($target['bundle'] === NULL) {
      return;
    }
    if ($target['bundle_column'] !== NULL) {
      $query->condition($alias . $target['bundle_column'], $target['bundle']);
      return;
    }
    $ids = $context->database->select($target['base_table'], 'b')
      ->fields('b', [$target['id_key']])
      ->condition('b.' . $target['bundle_key'], $target['bundle']);
    $query->condition($alias . $target['id_column'], $ids, 'IN');
  }

  /**
   * One target per table for every non-"keep" rule.
   */
  private function targets(Context $context): array {
    $targets = [];
    foreach ($context->settings->fieldRules() as $key => $action) {
      if ($action === 'keep') {
        continue;
      }
      try {
        array_push($targets, ...$this->resolve($context, (string) $key, (string) $action));
      }
      catch (\InvalidArgumentException) {
        // Reported by errors(); a real run never gets here with one.
      }
    }
    return $targets;
  }

  /**
   * Splits a rule into its parts and checks its syntax.
   *
   * @return array{entity_type_id: string, bundle: ?string, field_name: string, action: string, text: string}
   *
   * @throws \InvalidArgumentException
   */
  public static function parse(string $key, string $rule): array {
    [$action, $template] = array_pad(explode(':', $rule, 2), 2, NULL);
    if (!in_array($action, self::ACTIONS, TRUE)) {
      throw new \InvalidArgumentException("field_rules.$key: unknown action '$rule' (use " . implode(', ', self::ACTIONS) . ', or text:<template>)');
    }
    if ($template !== NULL && $action !== 'text') {
      throw new \InvalidArgumentException("field_rules.$key: only 'text' takes a template ('$rule')");
    }
    if ($template !== NULL && ($template === '' || substr_count($template, '%id') > 1)) {
      throw new \InvalidArgumentException("field_rules.$key: a text template must not be empty and may use %id once ('$rule')");
    }
    $parts = explode('.', $key);
    if (in_array('', $parts, TRUE) || !in_array(count($parts), [2, 3], TRUE)) {
      throw new \InvalidArgumentException("field_rules.$key: use entity_type.field_name or entity_type.bundle.field_name");
    }
    [$entity_type_id, $bundle, $field_name] = count($parts) === 3 ? $parts : [$parts[0], NULL, $parts[1]];
    return [
      'entity_type_id' => $entity_type_id,
      'bundle' => $bundle,
      'field_name' => $field_name,
      'action' => $action,
      'text' => $template ?? self::TEXT,
    ];
  }

  /**
   * Validates a rule and resolves its tables and columns.
   *
   * @throws \InvalidArgumentException
   */
  private function resolve(Context $context, string $key, string $rule): array {
    ['entity_type_id' => $entity_type_id, 'bundle' => $bundle, 'field_name' => $field_name, 'action' => $action, 'text' => $text] = self::parse($key, $rule);
    $manager = $context->entityTypeManager();
    if (!$manager->hasDefinition($entity_type_id)) {
      throw new \InvalidArgumentException("field_rules.$key: unknown entity type '$entity_type_id'");
    }
    $entity_type = $manager->getDefinition($entity_type_id);
    $storage = $manager->getStorage($entity_type_id);
    $definition = $context->entityFieldManager()->getFieldStorageDefinitions($entity_type_id)[$field_name] ?? NULL;
    if (!$definition || !$storage instanceof SqlEntityStorageInterface) {
      throw new \InvalidArgumentException("field_rules.$key: unknown field '$field_name' on '$entity_type_id'");
    }
    $bundle_key = $entity_type->getKey('bundle') ?: NULL;
    if ($bundle !== NULL) {
      if ($bundle_key === NULL) {
        throw new \InvalidArgumentException("field_rules.$key: '$entity_type_id' has no bundles");
      }
      if (!isset($context->bundleInfo($entity_type_id)[$bundle])) {
        throw new \InvalidArgumentException("field_rules.$key: unknown bundle '$bundle' of '$entity_type_id'");
      }
      if (!isset($context->entityFieldManager()->getFieldDefinitions($entity_type_id, $bundle)[$field_name])) {
        throw new \InvalidArgumentException("field_rules.$key: '$bundle' has no field '$field_name'");
      }
    }
    if ($action === 'keep') {
      return [];
    }
    $mapping = $storage->getTableMapping();
    $dedicated = $mapping->requiresDedicatedTableStorage($definition);
    $column = $mapping->getFieldColumnName($definition, $definition->getMainPropertyName());
    $id_key = $entity_type->getKey('id');
    $id_column = $dedicated ? 'entity_id' : $id_key;
    $base_table = $storage->getBaseTable();
    $targets = [];
    foreach ($mapping->getAllFieldTableNames($field_name) as $table) {
      if (!$context->tableExists($table)) {
        continue;
      }
      $bundle_column = NULL;
      if ($bundle !== NULL) {
        $bundle_column = $dedicated ? 'bundle' : ($context->database->schema()->fieldExists($table, $bundle_key) ? $bundle_key : NULL);
      }
      $targets[] = compact('key', 'action', 'text', 'table', 'column', 'id_column', 'dedicated', 'bundle', 'bundle_column', 'bundle_key', 'base_table', 'id_key');
    }
    return $targets;
  }

}
