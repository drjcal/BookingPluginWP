# Gezonder in Werk Booking

Lets people enrol in the courses of Gezonder in Werk, and lets staff manage the offering, the seats and the resulting enrolments.

## Language

### Offering

**Course**:
A subject that Gezonder in Werk teaches, with a description, an accreditation and a price. A course is not something you can attend on a specific date; its sessions are.
_Avoid_: Training, product

**Session**:
One occasion on which a course takes place, on a date and time, at a location, with an optional capacity. A webinar is a session.
_Avoid_: Edition, event, class

**Cycle**:
A set of sessions sold together at one bundle price.
_Avoid_: Series, package, programme

**Bundle price**:
The single price a participant pays for a whole cycle, instead of the sum of the course prices.
_Avoid_: Cycle price, package price

### Availability

**Bookable**:
A session that is open and not yet past (a session today is still bookable). A cycle is bookable only when it has sessions and all of them are bookable.
_Avoid_: Available, closed (for the negative case, say "not bookable")

**Capacity**:
The maximum number of confirmed enrolments a session accepts. A session without capacity has no limit.
_Avoid_: Seats, limit

**Waitlist**:
The queue of enrolments on a full session that are not yet confirmed.
_Avoid_: Queue, standby

### Enrolment

**Participant**:
The person who attends and is enrolled.
_Avoid_: Attendee, student, customer

**Enrolment**:
One participant's place on one session. A participant is enrolled in a given session at most once while the enrolment is active.
_Avoid_: Booking, registration, inschrijving (in code and docs)

**Cycle enrolment**:
The set of enrolments, one per session, that a participant creates in a single sign-up for a cycle. Each enrolment has its own outcome, so a participant can be confirmed on one session and waitlisted on another.
_Avoid_: Cycle booking, bundle

**Group reference**:
The shared identifier that ties together the enrolments of one cycle enrolment.
_Avoid_: Group id, booking number

**Confirmed**:
The enrolment has a seat on the session.
_Avoid_: Accepted, booked

**Waitlisted**:
The enrolment is on the waitlist and has no seat yet. Staff can promote it to confirmed.
_Avoid_: Pending, queued

**Cancelled**:
The enrolment no longer counts for capacity and no longer blocks a new enrolment by the same participant on that session.
_Avoid_: Deleted, withdrawn

**Promote**:
Staff turn a waitlisted enrolment into a confirmed one.
_Avoid_: Approve, upgrade

**Invoiced**:
Staff have marked the enrolment as invoiced.
_Avoid_: Billed, paid

**Erase**:
Staff remove an enrolment together with the participant's personal data. For a cycle enrolment, the whole group is erased.
_Avoid_: Delete, anonymise

## Relationships

- A **Course** has many **Sessions**.
- A **Cycle** groups several **Sessions**; a session can be part of a cycle and is also offered on its own.
- A **Session** has many **Enrolments**; an **Enrolment** belongs to exactly one session.
- A **Cycle enrolment** is one **Enrolment** per session of the cycle, linked by a **Group reference**. The **Bundle price** is counted once for the whole group.

## Flagged ambiguities

- The code says both "webinar" and "session"; **Session** is canonical, and a webinar is a session held online.
- The code and UI use "closed" in more than one sense; the glossary only defines **Bookable**. Whether a full session counts as closed or goes to the **Waitlist** is not yet settled.
