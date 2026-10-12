# AI setup prompt

A prompt for an AI coding agent (Claude Code, Cursor, Copilot and so on). It has the agent write the site's `drush/drush.yml` settings for this package. Once the package is installed, copy the prompt below and paste it into the agent from the root of the Drupal project.

The agent only researches the site and writes `drush/drush.yml`. It never runs the sanitiser. Once you've reviewed the file, run `drush sql:sanitize --dry-run` yourself.

Copy and paste the prompt below into your AI coding agent.

````markdown
# Task: create `drush/drush.yml` for `southwark/drush-sanitize-test-db`

This site uses `southwark/drush-sanitize-test-db`. It extends `drush sql:sanitize` so that a copy of the database can be made safe for local automated testing. Your only job is to create this site's `drush/drush.yml` with the right settings for the package. The settings must make the sanitiser remove personal data and secrets while keeping the content, configuration and accounts the tests need.

Before you start, read the package's `README.md`, `docs/REFERENCE.md` and `drush.yml.example`. You'll find them in `vendor/southwark/drush-sanitize-test-db/`, or wherever Composer installed the package. Use only the option names documented there.

## Rules (never break these)

- **Never run the sanitiser.** That means no `drush sql:sanitize` at all, not even with `--dry-run`. The one exception is `drush sql:sanitize --help`.
- Don't change the database, and don't run any command that writes to it.
- The only file you may create or change is `drush/drush.yml`, plus its backup (see step 3). Don't edit settings files, `composer.json` or anything else.
- Never put secrets, passwords or real personal data in `drush/drush.yml`. It is committed to git.
- Don't add comments to `drush/drush.yml`. Put the reasons for each setting in your report.

## 1. Research (read-only)

Collect these facts. For each one, note where it came from: a file path, a config name or a read-only command's output.

1. **Setup:** the Drupal and Drush versions (`drush status`), and how the site runs locally (DDEV, Lando or something else). Check the package is installed: `drush sql:sanitize --help` lists `--dry-run`.
2. **Production protection:**
   - Is the package in `require` or `require-dev`?
   - Is `drush/Commands/PolicyCommands.php` (copied from the package's `examples/PolicyCommands.php`) present?

   If the package is in `require-dev` and there's no policy file, production is unprotected. Say so in your report, but don't fix it.
3. **Production hostnames:** every one of them. They go in `deny_uri_hosts`, and globs like `'*.example.gov.uk'` are allowed. Look in:
   - `sites.php`
   - `domain.record.*` config
   - `settings*.php`
   - hosting config (`.acquia/`, `pantheon.yml`, `.platform.app.yaml`, `.lagoon.yml`)
   - CI and deploy files, and the README
4. **Production markers:** settings files that only production loads, such as `settings.prod*.php` or hosting includes. They go in `prod_markers`. Check that no local or test environment loads them.
5. **Local URLs:** if the local hostnames don't end in `.ddev.site`, `.lndo.site`, `.localhost` or `.test`, add their suffix to `local_url_suffixes`.
6. **Content fields that may hold personal data:** every field storage (`config/sync/field.storage.*.yml`, custom entity types included) that is either:
   - of type `email`, `telephone`, `address` or `name`, or
   - a text field whose name suggests personal data: `*mail*`, `*phone*`, `*mobile*`, `*minicom*`, `*name*`, `*address*`, `*postcode*`, `*dob*`, `*birth*`, `*nhs*`, `*ni_number*`, `*notes*`, `*applicant*` and similar

   For each field, record:
   - the entity type and the bundles that use it
   - how many rows it has, using read-only queries on the local database if one is available
   - whether its values are shown publicly on the site, and where
7. **Tables:** non-empty tables that aren't content-entity tables and that the package doesn't already handle (see "What a run does" in `docs/REFERENCE.md`). Examples: custom module schemas (`hook_schema()`), leftover legacy or migration tables, and contrib log, queue or tracking tables. For each one, say whether it holds personal or temporary data.
8. **Config:**
   - real email addresses in config, and whether each one is personal or a public/team/agency address
   - credential-like keys that aren't covered by the package's default secret keys
   - what `config_ignore` (if used) keeps out of `drush cim`
9. **Tests:** what the automated tests (Behat, Backstop, PHPUnit and so on) assert on or capture, so you don't sanitise content they depend on. Public contact details visible in visual baselines are a common example.

## 2. Decide, using these rules

- **`field_rules`** (`<entity_type>.<field_name>` or `<entity_type>.<bundle>.<field_name>`: `email | phone | text | 'text:<template>' | blank | keep`, where `%id` in a template becomes the entity ID):
  - Use the bundle form when only some bundles hold personal data, e.g. `taxonomy_term.form_owners.name: 'text:Form owner %id'` renames the terms in one vocabulary that are named after people.
  - Personal data about individuals gets `email`, `phone`, `text` or `blank`, whichever suits the field. That includes:
    - members of the public, applicants and case details
    - unpublished staff details
    - private notes
    - form recipients that are personal addresses
  - Organisational contact details that are already published on the public site get `keep`. This is especially important if tests or visual baselines show them.
  - **If you can't tell whether a field is public or personal, don't guess.** Leave it out of the file and add it to your questions for me.
- **`keep_emails`:** public team or organisation addresses, and obvious placeholders, that appear in config or webform text.
- **`config_email_exclude`** (`config.name` or `config.name:key.path` globs): only config whose addresses aren't personal and are restored from git by `drush cim` anyway. An agency update-notification mailbox is an example.
- **`secret_keys`:** credential-like key names the defaults miss. **`secret_exclude`:** only for values that look secret but aren't.
- **`extra_tables`:** tables to empty because they hold temporary, log or personal data the tests don't need. **`tables_exclude`:** tables the defaults would empty but the tests need.
- **`reviewed_tables`:** tables you've checked that hold no personal data and are kept as they are.
- **`email_domain`:** leave it out unless the site needs a different test domain.
- **Drush options** under `command.sql.sanitize.options`:
  - `sanitize-email: 'user+%uid@example.com'`. Use `%uid` only, so no original data ends up in the result.
  - `sanitize-password`: a test password, never a real one.
  - `sanitize-admin-name` / `sanitize-admin-email`: only if the defaults don't suit.

Leave out any option whose value would be empty or the same as the default.

## 3. Write `drush/drush.yml`

1. **Back up any existing file.** If `drush/drush.yml` exists, copy it to `drush/drush.yml.bak-<YYYYMMDD-HHMMSS>` first. Check the copy is identical to the original before going on.
2. **Create the new `drush/drush.yml`:**
   - Follow the structure and key order of `drush.yml.example`.
   - Add no comments.
   - If the backup holds settings that have nothing to do with this package (anything outside `sanitize_test_db` and `command.sql.sanitize`, e.g. `options.uri`), copy them into the new file unchanged so nothing else breaks. List them in your report.
3. **Check the file.** Confirm it is valid YAML, for example:

   ```bash
   php -r "require 'vendor/autoload.php'; var_export(Symfony\Component\Yaml\Yaml::parseFile('drush/drush.yml'));"
   ```

   Then confirm `drush sql:sanitize --help` still runs. Check every key against `drush.yml.example`, and every `field_rules` entry against an existing entity type and field.

## 4. Report back

Give me:
- the path of the backup, or a note that there was no existing file
- the new `drush/drush.yml`
- any settings carried over from the backup
- a table of every candidate field, table and config address, each with the decision and the reason, and the source of each fact
- the production-protection status from step 1.2
- **open questions:** anything you didn't decide yourself, especially fields where it's unclear whether the data is public or personal, and production hostnames you couldn't confirm
- the next step for me: review the file, then run `drush sql:sanitize --dry-run` on a local copy and work through the coverage report
````
