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
