# Gezonder in Werk Booking

WordPress plugin for course enrolment (see issue #1). Shortcode: `[gezonder_inschrijfformulier]`.
Endpoint: `POST /wp-json/gezonder-in-werk/v1/enrolments`.

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
