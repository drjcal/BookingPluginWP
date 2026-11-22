# Glossary

The vocabulary for this plugin. Code, issues and UI wording should use these terms.
Dutch UI labels are in brackets.

| Term | Meaning |
| --- | --- |
| **Course** (Cursus/Webinar) | A thing people can follow: title, description and price. Has no date of its own. |
| **Session** (Sessie) | One dated occurrence of a Course: date, start and end time, location and capacity. An Enrolment always targets one Session. |
| **Cycle** (Cyclus) | A bundle of Sessions sold together at one bundle price. Enrolling in a Cycle creates one Enrolment per Session, all or nothing. |
| **Enrolment** (Inschrijving) | One person's registration for one Session. Has a status: confirmed, waitlisted or cancelled. At most one active Enrolment per Session and email. |
| **Waitlist** (Wachtlijst) | The `waitlisted` status: the Session is full, so the Enrolment is not confirmed yet. Staff promote it to confirmed by hand when a seat frees up. |
| **Billing details** (Factuurgegevens) | The invoice fields of an Enrolment (for example company and invoice address), kept apart from the participant's personal details. |
| **Group reference** | A shared reference on the Enrolments created by one Cycle submission, so they can be handled together (for example deletion and anonymisation). |
| **Labels** | The single module (`Labels`) that owns enrolment wording and formatting: status, session, date, price, seat counts and the invoiced state. |
| **Format** (Soort) | Whether a Session is a webinar or a live training. It decides when the enrolee gets the link or practical information (`Expectation_Texts`). Existing Sessions default to webinar. |
| **Expectation text** | The sentence telling the enrolee when information arrives, with the calculated send date. Shown on the form, the confirmation screen and the confirmation e-mail; a waitlisted Enrolment gets it only after promotion. |
