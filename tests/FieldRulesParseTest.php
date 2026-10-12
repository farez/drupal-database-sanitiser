<?php

declare(strict_types=1);

namespace SanitizeTestDb\Tests;

use PHPUnit\Framework\TestCase;
use SanitizeTestDb\Step\FieldRulesStep;

/**
 * Unit tests for FieldRulesStep::parse().
 */
final class FieldRulesParseTest extends TestCase {

  /**
   * Tests: a rule without a bundle applies to every bundle.
   */
  public function testFieldRuleWithoutBundle(): void {
    $this->assertSame([
      'entity_type_id' => 'node',
      'bundle' => NULL,
      'field_name' => 'field_phone',
      'action' => 'phone',
      'text' => FieldRulesStep::TEXT,
    ], FieldRulesStep::parse('node.field_phone', 'phone'));
  }

  /**
   * Tests: a bundle-scoped rule with a text template.
   */
  public function testBundleScopedTextTemplate(): void {
    $this->assertSame([
      'entity_type_id' => 'taxonomy_term',
      'bundle' => 'form_owners',
      'field_name' => 'name',
      'action' => 'text',
      'text' => 'Form owner %id',
    ], FieldRulesStep::parse('taxonomy_term.form_owners.name', 'text:Form owner %id'));
  }

  /**
   * Tests: a template may contain colons.
   */
  public function testTemplateMayContainColons(): void {
    $this->assertSame('Owner: %id', FieldRulesStep::parse('node.title', 'text:Owner: %id')['text']);
  }

  /**
   * Tests: invalid rules are rejected.
   *
   * @dataProvider invalidRules
   */
  public function testRejectsInvalidRules(string $key, string $rule): void {
    $this->expectException(\InvalidArgumentException::class);
    FieldRulesStep::parse($key, $rule);
  }

  /**
   * Invalid keys and actions.
   */
  public static function invalidRules(): array {
    return [
      'unknown action' => ['node.field_x', 'scramble'],
      'template on email' => ['node.field_x', 'email:x%id'],
      'empty template' => ['node.field_x', 'text:'],
      'two %id' => ['node.field_x', 'text:%id-%id'],
      'no field' => ['node', 'text'],
      'too many parts' => ['node.page.field_x.value', 'text'],
      'empty part' => ['node..field_x', 'text'],
    ];
  }

}
