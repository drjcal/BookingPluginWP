# Gezonder in Werk Booking

WordPress plugin for Gezonder in Werk B.V. that lets healthcare professionals enrol for training courses (nascholingen and webinars) and gives staff one place to manage those enrolments.

## What it does

- **Catalogue.** Staff create courses, dated sessions (with optional capacity) and cycles (a bundle of sessions at one price) in the WordPress admin.
- **Enrolment form.** The shortcode `[gezonder_inschrijfformulier]` shows a form for a session or a whole cycle. It collects participant, BIG number and invoice details plus consent, and validates everything on the server.
- **Seats and waitlist.** A free seat gives a confirmed enrolment; a full session gives a waitlisted one that staff promote by hand. Capacity is never exceeded, and a person cannot enrol twice for the same session.
- **E-mail.** The participant gets a confirmation (or waitlist notice); staff get a notification with a link to the enrolment.
- **Admin.** List, filter and search enrolments, change status, mark as invoiced, add internal notes, export to CSV and delete.
- **Privacy.** Consent is stored, spam is blocked (nonce, honeypot, rate limit), and personal data is anonymised automatically after a configurable retention period.

The interface and e-mails are in Dutch. Requires PHP 8.0+ and WordPress.

## Documentation

- [How it works](docs/HOW-IT-WORKS.md): the product from the point of view of staff and visitors.
- [Architecture](docs/ARCHITECTURE.md): classes, request flow, data model and design decisions.
- [Glossary](docs/GLOSSARY.md): the shared domain language, with terms to avoid and open ambiguities.

## API

`POST /wp-json/gezonder-in-werk/v1/enrolments` creates an enrolment. It returns `201` with the enrolment id, status and a message, or `422 validation_failed` with per-field `errors` and the submitted `values`.

## Tests

Requirements: Node and a running Docker daemon (e.g. Docker Desktop or `colima start`).

```
npm install
npm test
```

This starts `@wordpress/env`, installs PHPUnit 9.6 via Composer inside the container and runs the WordPress integration tests in `tests/integration`.

## Uninstall

Deleting the plugin keeps the enrolment table (personal data) unless the option `gzw_booking_remove_data_on_uninstall` is set to `1` beforehand.

Set it on the Settings screen ("Verwijderen van de plugin"). `PrivacyTest` covers both `uninstall.php` paths and `Installer::uninstall()`.

## Privacy and spam protection

- The form requires both consent checkboxes; the consent is stored on the enrolment (`consent_*` fields, shown in the admin under "Toestemming").
- Submissions carry a nonce (`gzw_nonce`), a honeypot (`gzw_website`) and are rate limited per IP (10 attempts per 10 minutes, filter `gzw_booking_rate_limit`). Rejected submissions are not stored. A page cache that serves the form for longer than the nonce lifetime (12-24 h) makes the nonce stale: keep cached pages short-lived or exclude the form page.
- Staff can delete an enrolment from its detail view; for a cycle submission the whole group is removed.
- Settings has a retention period in months (default 24, 0 keeps everything). A daily WP-Cron job anonymises enrolments whose session date is older than that: personal data is wiped, price, status and the consent record stay. A cycle group is anonymised together once its last webinar is old enough.

## Manual check: `assets/form.js` restore-values

There is no JavaScript test runner, so check by hand after changing `form.js`: open a page with the shortcode, fill the form with an invalid BIG number such as `123 456 789 0`, tick one consent box and leave the other empty, and submit.
Expected: the error appears under the BIG field and the empty consent box, the ticked consent box stays ticked, the fields keep what you typed (the BIG number is shown without spaces as the server returned it), and the hidden nonce field is untouched.
