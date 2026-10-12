# drush-sanitize-test-db reference

Full detail for [`southwark/drush-sanitize-test-db`](../README.md): what a run does, how the environment check works, every configuration option, the coverage report, and the caveats.

## Contents

- [What a run does](#what-a-run-does)
- [Environment check](#environment-check)
- [Configuration reference](#configuration-reference)
- [Coverage report](#coverage-report)
- [Notes and caveats](#notes-and-caveats)
- [CI](#ci)

## What a run does

The steps run in this order. They run *after* Drush core's and contrib sanitisers, because they use a `*` post-command hook:

| Step | What it does | Runs when |
|---|---|---|
| Drush core | Every user email → `--sanitize-email` pattern; every password → `--sanitize-password`; empties `sessions`; overwrites user field values | always (Drush) |
| Webform plugin | Empties `webform_submission`, `webform_submission_data` and `webform_submission_log` (IP addresses, uids, tokens, drafts) | Webform is installed |
| Users | Renames uid > 1 to `user<uid>`; sets uid 1 name, mail and status from `--sanitize-admin-name` / `--sanitize-admin-email`; empties `users_data` | always |
| External auth | Empties `authmap` (SSO identities) | the table exists |
| Group | Deletes invitations (invitee emails); relabels memberships with the sanitised username | Group is installed |
| Config emails | Replaces every email address in **all** config, in every collection, with `--sanitize-admin-email` | always |
| Webform extras | Deletes the `webform.element.message` state key | Webform is installed |
| Secrets | Blanks config values under secret-like keys; sets reCAPTCHA to Google's public test keys; blanks Key module `config`-provider values; regenerates `system.private_key` and `system.cron_key`; deletes secret-like state keys | always |
| Revision logs | Blanks revision log messages on every revisionable entity type | always |
| Field rules | Applies the site's `field_rules` | rules are configured |
| Tables | Empties `watchdog`, `flood`, `queue`, `batch`, `semaphore`, `key_value_expire`, `history`, `cache_*`, `cachetags`, `SimpleSAMLphp_*`, `config_export`, `config_import`, `config_snapshot`, plus `extra_tables` | the tables exist |
| Verify | Entity counts unchanged; user names, emails and uid 1 as expected; no emails or secrets left in config; no state left; field rules applied; tables empty | always |

Content, taxonomy, media and groups are **kept**. The only personal data changed in content is what field rules cover.

## Environment check

`sql:sanitize` runs only when one of the two routes passes:

- **Local**, which needs all of these:
  - an opt-in: `IS_DDEV_PROJECT=true` (DDEV), `LANDO=ON` (Lando), or `$settings['allow_sql_sanitize'] = TRUE;` in `settings.local.php` for any other local setup
  - a local DB host: loopback, a private IP, a socket, SQLite, or a Docker service name without dots
  - a local site URL: `localhost`, or a hostname ending in `.ddev.site`, `.lndo.site`, `.localhost` or `.test` (plus any `local_url_suffixes`). Pass `--uri` if Drush can't resolve the URL.
- **Hosted**, for stage or CI environments that genuinely need to sanitise: that environment's own settings file sets `$settings['sanitize_test_db_allowed_environment'] = 'stage';`. It must be a non-empty name. This replaces the three local checks **only**. The environment name is shown in the confirmation prompt and the dry run, and logged. **Never set it on production.**

Both routes also need these two checks to pass:

- none of the `prod_markers` files has been loaded (e.g. `settings.prod.aws.php`)
- the site's host isn't in `deny_uri_hosts`

Every failure is listed in the error message.

## Configuration reference

Settings live under `sanitize_test_db` in the site's `drush/drush.yml`. See [drush.yml.example](../drush.yml.example).

```yaml
command:
  sql:
    sanitize:
      options:
        sanitize-email: 'user+%uid@example.com'   # only %uid: no original data in the result
        sanitize-password: admin
        # sanitize-admin-name: admin
        # sanitize-admin-email: admin@example.com
sanitize_test_db:
  prod_markers: [settings.prod.php]
  deny_uri_hosts: [www.example.gov.uk, '*.example.gov.uk']
  local_url_suffixes: []        # added to the defaults
  extra_tables: [mymodule_audit_log, 'legacy_*']   # globs: tables to empty, added to the defaults
  tables_exclude: []            # globs, added to [SimpleSAMLphp_tableVersion]
  config_email_exclude: []      # "config.name" or "config.name:key.path" globs
  keep_emails: []               # public addresses to leave alone
  secret_keys: []               # added to the defaults (client_secret, password, api_key, token, …)
  secret_exclude: []            # "config.name:key.path" globs
  reviewed_tables: []           # globs: reviewed, no personal data (coverage report)
  email_domain: example.com     # default: the domain of --sanitize-admin-email
  field_rules:
    node.field_case_officer_email: email   # → user+<entity id>@<email_domain>
    node.field_venue_phone: phone          # → 020 7946 0000 (Ofcom drama number)
    node.field_applicant_name: text        # → "Sanitised text"
    paragraph.field_private_notes: blank   # values deleted
    node.field_public_email: keep          # reviewed, leave as is
    taxonomy_term.form_owners.name: 'text:Form owner %id'   # one vocabulary only → "Form owner 12"
```

`extra_tables` lists the tables to empty. Entries are table names or globs (`'legacy_*'`, quoted because of the `*`) and are added to the defaults shown under "Tables" in [What a run does](#what-a-run-does). Each matching table is truncated, then checked to be empty. A pattern that matches no table is ignored. `tables_exclude` wins over both lists, so use it to keep a table that a default glob would empty.

Field rule keys take one of two forms:

- `<entity_type>.<field_name>` applies to every bundle that has the field.
- `<entity_type>.<bundle>.<field_name>` applies to that bundle only: one vocabulary, content type, media type and so on. For example, `taxonomy_term.form_owners.name` renames the terms in the `form_owners` vocabulary and leaves every other vocabulary alone. Base fields such as a term's `name` or a node's `title` work too.

Actions:

| Action | Result |
|---|---|
| `email` | `user+<entity id>@<email_domain>` |
| `phone` | `020 7946 0000` (Ofcom drama number) |
| `text` | `Sanitised text` |
| `text:<template>` | the template, with `%id` replaced by the entity ID. `'text:Form owner %id'` gives `Form owner 12`. Quote it in YAML. `%id` may appear once, or not at all for a fixed value. |
| `blank` | values deleted |
| `keep` | reviewed, left as is |

Only values that are already set are changed. A bundle-scoped rule doesn't cover the field's other bundles, so the coverage report still lists the field until it has a rule without a bundle, or a `keep`.

`field_rules` works on any fieldable entity type, custom ones included. It updates the field's data and revision tables. An unknown entity type, field or action is a configuration error: the dry run lists it, and a real run refuses to start.

## Coverage report

`--dry-run` also lists:

- **tables** that hold rows but that no step sanitises and nobody has reviewed (`reviewed_tables`). Content entity tables are excluded.
- **likely personal-data fields with no rule:** `email`, `telephone` and `address` fields, and fields named `*mail*`, `*phone*`, `*mobile*` or `*minicom*`. User fields are left to Drush core.

Review each item, then add it to `field_rules`, `extra_tables` or `reviewed_tables`. Run it again whenever modules or fields change.

## Notes and caveats

- Not transactional. Run it on a **copy**, and snapshot first.
- Config is written straight to active storage, so no config events fire. Caches are emptied at the end.
- `drush config:import` afterwards restores whatever is committed in config sync, secrets included. Keep secrets out of config sync.
- The dry run ends by throwing Drush's `UserAbortException`, like answering "no" at the prompt, so it exits with code 1.

## CI

CI should also run `drush sql:sanitize --help`, `--dry-run` and a full run on fresh installs of Drupal 10 + Drush 12 and Drupal 11 + Drush 13.
