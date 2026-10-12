# drush-sanitize-test-db

Extends `drush sql:sanitize` so it can turn a copy of a Drupal production or dev database into one that's safe for local automated testing. It runs in a local dev environment only, has a `--dry-run` option, and sanitises far more than Drush core does. It also verifies the sanitisation.

Supports Drupal 10.3+ and 11, with Drush 12.5+ and 13.

## Install

This is an internal package, not published to Packagist. Add it to the site's `composer.json`:

```json
{
  "repositories": [
    { "type": "vcs", "url": "https://github.com/farez/drush-sanitize-test-db" }
  ],
  "require-dev": {
    "southwark/drush-sanitize-test-db": "^0.1"
  }
}
```

Then install:

```bash
composer update southwark/drush-sanitize-test-db
```


**Protecting the production environment.**

Install as a dev dependency in composer (`require-dev`) and add a Drush policy file.

No sanitising code reaches production when it builds with `composer install --no-dev`. Copy [examples/PolicyCommands.php](examples/PolicyCommands.php) to the site's `drush/Commands/PolicyCommands.php` and commit it. It applies the same checks everywhere, so it protects production.

## Backup first!

Goes without saying, you should make a backup of your database before sanitising it. 

```bash
drush cr # we don't need to backup the caches
drush sql-dump --gzip --result-file=/path/to/db-file.sql
```

## Use

```bash
drush sql:sanitize --dry-run    # report, change nothing
drush sql:sanitize -y           # sanitise + verify
```

Put the defaults in the site's `drush/drush.yml` so a plain `drush sql:sanitize` does the right thing. See [drush.yml.example](drush.yml.example). The settings you will want first:

```yaml
command:
  sql:
    sanitize:
      options:
        sanitize-email: 'user+%uid@example.com'
        sanitize-password: admin
sanitize_test_db:
  field_rules:
    node.field_applicant_name: text        # → "Sanitised text"
    node.field_case_officer_email: email   # → user+<entity id>@<email_domain>
    node.field_venue_phone: phone          # → 020 7946 0000 (Ofcom drama number)
    paragraph.field_private_notes: blank   # values deleted
    taxonomy_term.form_owners.name: 'text:Form owner %id'   # one vocabulary only → "Form owner 12"
  extra_tables:                            # tables to empty (globs)
    - mymodule_audit_log
    - 'legacy_*'
```

`field_rules` is how you decide what happens to personal data in content. Actions are `email`, `phone`, `text`, `text:<template>` (`%id` becomes the entity ID), `blank` and `keep`. Key a rule as `<entity_type>.<field>` for every bundle, or `<entity_type>.<bundle>.<field>` for one bundle, such as a single vocabulary.

`extra_tables` lists the tables to empty completely, such as custom log, queue or legacy tables. These are added to the built-in list (`watchdog`, `flood`, `cache_*` and so on). Glob patterns like `'legacy_*'` need quotes in YAML.

To have an AI coding agent research the site and write `drush/drush.yml` for you, use the prompt in [docs/ai-setup-prompt.md](docs/ai-setup-prompt.md). The agent doesn't run the sanitiser, so review the file and do the dry run yourself.

It is advised that you test it on a database first.

## Tests

```bash
composer install && vendor/bin/phpunit
# or, from a project that requires this package:
vendor/bin/phpunit -c vendor/southwark/drush-sanitize-test-db/phpunit.xml.dist
```

## Documentation

[docs/REFERENCE.md](docs/REFERENCE.md) has the full detail:

- what each sanitisation step does, and in what order
- how the environment check works (local vs. hosted, `prod_markers`, `deny_uri_hosts`)
- every `sanitize_test_db` option
- the coverage report — how to find personal data that no rule covers yet
- caveats and CI setup

[docs/ai-setup-prompt.md](docs/ai-setup-prompt.md) is a copy-paste prompt for an AI coding agent to write a site's `drush/drush.yml`.
