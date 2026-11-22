# Architecture

How the plugin is put together. For vocabulary see [GLOSSARY.md](GLOSSARY.md); for setup, tests, privacy and uninstall see the [README](../README.md).

All code lives in the namespace `GezonderInWerk\Booking`, in `includes/class-*.php`. Classes are static and loaded by hand from `gezonder-in-werk-booking.php`, which also wires every hook. Requires PHP 8.0+.

## Request flow: a public enrolment

```
[gezonder_inschrijfformulier]  ->  assets/form.js  ->  POST /wp-json/gezonder-in-werk/v1/enrolments
                                                         |
                                                    Rest_Controller::create
                                                         |
              Spam_Guard::check -> Enrolment_Validator::validate -> Enrolment_Repository::enrol / enrol_cycle
                                                         |
                                       Enrolment_Messages::outcome + Enrolment_Mailer::send_for_submission
```

1. `Form_Shortcode` renders the form (courses, sessions and open cycles come from `Catalog_Repository`) and includes the nonce from `Spam_Guard::nonce()`.
2. `Rest_Controller::create` runs `Spam_Guard::check` (nonce, honeypot, per-IP rate limit). Rejected submissions are never stored.
3. `Enrolment_Validator::validate` checks every field defined in `Enrolment_Fields` and resolves the target: a session id, or a cycle (see `cycle_id_from`). On failure the controller answers `422 validation_failed` with `errors` and the submitted `values`, so the form can restore what the user typed.
4. `Enrolment_Repository::enrol` (single session) or `enrol_cycle` (all sessions of a cycle, all or nothing) stores the result. Outcomes: `closed`, `duplicate` (both mapped to a 422 on the relevant field) or a storage error (500).
5. Success returns `201` with `id`, `status` (`confirmed` or `waitlisted`) and a message; cycle submissions also return `ids` and `group_reference`. A confirmation e-mail goes out through `Enrolment_Mailer`. The admin notification carries an experimental (beta) SnelStart booking CSV from `Snelstart_Export` (via `Mail_Adapter::send`'s `$attachments`) once all four ledger settings are filled in.

## Data model

Five tables, created by `Installer::create_tables` with `dbDelta`:

| Table | Holds |
| --- | --- |
| courses | Title, description, accreditation, price. No date. |
| sessions | Dated occurrence of a course: date, times, location, `is_open`, optional `capacity`. |
| cycles | Title and bundle price. |
| cycle_sessions | Links a cycle to its sessions. |
| enrolments | One person for one session: personal, billing and consent fields, `price_cents`, `status`, `invoiced`, `internal_note`, `created_at`, `anonymised_at`, `active_session_id`. |

Table names come from `Installer::*_table()` (WordPress prefix applied). The schema version is `Installer::DB_VERSION`, stored in the option `gzw_booking_db_version`. `Installer::maybe_upgrade` runs on `init` and re-applies the schema when the version differs, so updates need no re-activation.

`Enrolment_Fields` is the single definition of the enrolment fields: group, label, validation, max length, whether it appears in the list or in search, and its SQL column (`column_sql`). Add a field there and the form, validator, admin detail view, export and schema follow.

## Capacity, waitlist and duplicates

- Every write that depends on seats runs inside `Catalog_Repository::with_session_lock` (or `with_session_locks` for cycles), so concurrent submissions cannot exceed capacity and a closing session cannot get a confirmed enrolment.
- Inside the lock a free seat gives `confirmed`, otherwise `waitlisted`. Staff promote a waitlisted enrolment by hand (`Enrolment_Repository::promote`).
- `active_session_id` is the session id for non-cancelled enrolments and `NULL` for cancelled ones. A unique key on `(active_session_id, email)` is the backstop against duplicate enrolments, and cancelling releases both the seat and the e-mail address.
- In a cycle submission the bundle price is stored once, on the first session's enrolment (the others carry 0). All enrolments share a group reference and each keeps its own seat or waitlist outcome.

## Admin

| Class | Screen | Notes |
| --- | --- | --- |
| `Admin_Page` | Top-level menu: courses and their sessions | Forms post to `admin_post_gzw_*` handlers. |
| `Cycles_Admin` | Cycles submenu | Create, edit, delete cycles. |
| `Enrolments_Admin` | Enrolments menu: list, filters, detail | Update status, invoiced flag and note; promote; delete (a whole group for cycles). |
| `Enrolments_Export` | CSV export of the filtered list | Values starting with formula characters are neutralised. |
| `Settings_Page` | Settings submenu | Retention months, notification e-mail, SnelStart ledger numbers, remove-data-on-uninstall. |

Enrolment screens are gated by the capability `gzw_manage_enrolments` (`Installer::CAP_MANAGE_ENROLMENTS`), granted to administrators when the schema is created or upgraded. Every `admin_post` handler checks it plus a nonce.

## Labels

`Labels` is the one place for enrolment wording and formatting: status, session, cycle, date and price. UI and e-mail code call it instead of formatting inline. `Enrolment_Messages` holds the confirmation text shown to the submitter.

## Privacy lifecycle

- `Spam_Guard`: nonce, honeypot, rate limit (filter `gzw_booking_rate_limit`).
- `Retention`: a daily WP-Cron job (`Retention::HOOK`, scheduled when the schema is created or upgraded, unscheduled on deactivation) calls `Enrolment_Repository::anonymise_expired`. Personal data is wiped; price, status and consent stay. A cycle group is anonymised once its last session is old enough.
- `Installer::uninstall` and `uninstall.php` drop the tables only when `gzw_booking_remove_data_on_uninstall` is `1`.

## Tests

WordPress integration tests in `tests/integration`, one file per behaviour area (submission, capacity and waitlist, cycles, e-mail, admin list, export, privacy). `tests/bootstrap.php` and `tests/helpers.php` set up the environment. See the README for how to run them. `assets/form.js` has no automated test; use the manual check in the README.
