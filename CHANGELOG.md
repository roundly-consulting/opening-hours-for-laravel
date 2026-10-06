# Changelog

All notable changes to `opening-hours-for-laravel` are documented in this file. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/).

## Unreleased

## 1.1.0 - 2026-10-06

### Added

- `Availability\Slot` implements `Arrayable` and `JsonSerializable`: `json_encode($slot)` and
  `response()->json($slots)` give the `toArray()` shape (`start`, `end`, `available`,
  `remaining_capacity`, `reason`, ISO-8601 times in the output timezone).

### Changed

- Slot JSON now has the `toArray()` shape; before, it carried camelCase keys (`remainingCapacity`)
  and UTC times. Upgrade: a client that read `remainingCapacity` reads `remaining_capacity`, and
  times carry the output timezone's offset instead of `Z`.
- `max_query_days` also counts a booking's before-buffer, duration and after-buffer, and now limits
  `isOpenDuring()`, `isClosedDuring()` and availability checks. `nextAvailableSlot()` throws
  `QueryRangeTooLargeException` when a single slot with its buffers is longer than `max_query_days`.
  Upgrade: raise `max_query_days` if a booking or a checked span can be longer.
- The `whereOpenAt()` and `whereOpenThroughout()` horizon now ends at `now + days_ahead − 1 day`; an
  instant in the last day before `now + days_ahead` throws `OutsideMaterializedHorizonException`
  instead of answering "closed". Upgrade: raise `materialize.days_ahead` by one to keep the same
  reach.
- Documentation: the README hero image uses an absolute URL, so it renders on Packagist and other
  sites.
- Maintenance: `composer.json` `homepage` and `support.docs` now point to the documentation site.

### Fixed

- Slot grids skipped lines across DST changes: a daily line on the spring-forward day, the first
  line after the gap, and the repeated hour on the fall-back day.
- A definition written inside a host transaction that rolled back stayed cached under a revision
  the next write reuses, so reads served the rolled-back hours.
- `isAlwaysOpen()` ignored the gap at a hand-over to a schedule that covers the week only through
  overnight spill, so `nextClose()` and `previousClose()` returned null there.
- `SlotCollection` broke `map()`, `groupBy()`, `chunk()`, `pluck()` and `mapInto()` followed by
  `toArray()`, dropped `keyBy()` keys, and serialized to JSON in a different shape than `toArray()`.
- `MaterializeIntervalsJob` was never unique: every change and every `opening-hours:materialize` run
  queued another job for the same calendar.
- `ScheduleBuilder::fromData()` followed by `from()` or `until()` dropped the other bound of a dated
  window.
- A date outside 1900–2200 built from a `LocalDate` was saved, after which every read of the
  calendar threw; it is now refused with an `invalid_date` violation before anything is written.
- Slot durations and buffers widened a slot search past `max_query_days` without a check, so a huge
  value exhausted memory or threw a `TypeError`, and availability checks, `isOpenDuring()` and
  `isClosedDuring()` had no span limit at all; all of them now throw `QueryRangeTooLargeException`.
- A before-buffer of a day or more hid valid slots at the start of a long opening run.
- `nextAvailableSlot()` restarted the step sequence at every internal search chunk, so its answer
  depended on `max_query_days` and could be a slot `slots()` never lists.
- `currentPeriod()` took the label, capacity and source of a long run only from the part around the
  instant, so the same run was described differently depending on when it was asked.
- `isOpenAt()` and `currentRange()` missed a period of the next day when a fall-back just after
  midnight (America/St_Johns until 2011) made the clock read the day before again.
- A run joined across a skipped local day (Pacific/Apia 2011-12-30) reported a later start when asked
  at its first instant on the far side of the gap.
- A yearly `02-29` schedule was reported as `ambiguous_schedule_window` against any dated window longer
  than a year, even one without a Feb 29.
- An exception built from a ranges array with gaps or string keys (`array_filter()`, named entries)
  crashed validation with "Undefined array key" or a `TypeError`.
- With `mergeOverlapping`, a violation on a merged range named a post-merge range index, and one
  on a range spilling into the next day of the top-level `week` named `week.week.<day>`; both now
  name the day.
- `ValidTimeRange` passed a range whose label or meta exceeded the configured limits, which the save
  then refused.
- A week-array exception span with an invalid start date (`"2026-13-01 to 2026-12-30"`) was reported
  on the whole key instead of its start date.
- Restoring a soft-deleted calendar by syncing it skipped the `limits.calendars` check, in production
  and in `OpeningHours::fake()`.
- `opening-hours:prune --exceptions-after-days=0` deleted a one-off exception still in effect in a
  calendar west of UTC: the cutoff now uses today's date at UTC−12.
- Materialization threw `QueryRangeTooLargeException` on a 25-hour fall-back day when
  `max_query_days` was 1.
- `whereOpenAt()` and `whereOpenThroughout()` accepted instants up to `now + days_ahead`, past what the
  last daily run had stored, and silently answered "closed" there; the horizon now ends at
  `now + days_ahead − 1 day`.
- Materialized intervals of a soft-deleted owner ignored its timezone hook and dynamic exceptions.
- `Calendar::factory()` and `Interval::factory()` faked an integer `owner_id`, which PostgreSQL refuses
  when `key_type` is `uuid` or `ulid`.

## 1.0.0 - 2026-10-03

Initial public release.

### Added

- Opening hours for any Eloquent model via the `HasOpeningHours` trait, with several named
  calendars per model.
- Weekly schedules with several ranges per day, overnight ranges, 24-hour and closed days.
- Seasonal schedules with priorities, and exceptions for single dates, date spans, yearly holidays
  and movable holidays such as Easter.
- A fluent builder (`editOpeningHours()`) and array input with optimistic concurrency through
  revisions.
- A full query API — `isOpenAt()`, `nextOpen()`, `nextClose()`, `forDate()`, `forWeek()`, open
  durations — with one explicit rule for daylight-saving changes.
- schema.org structured data (`toStructuredData()`) and API resources for status and definitions.
- Availability checks and bookable slots with capacity, buffers, minimum notice and a horizon,
  fed by any busy-period source, including an Eloquent query.
- A `ValidOpeningHours` validation rule with readable messages in English and Slovak that name the
  exact day, range or exception at fault.
- Per-revision caching and eager loading with `withOpeningHours()`, plus `OpeningHoursUpdated` and
  `OpeningHoursDeleted` events.
- Opt-in materialized intervals for SQL scopes such as `whereOpenAt()` and `whereOpenThroughout()`.
- Artisan commands `opening-hours:show`, `opening-hours:prune` and `opening-hours:materialize`.
- The `OpeningHours` facade covers the whole API: `OpeningHours::exceptions($owner)` adds
  (`closed()`, `open()`, `add()`), lists (`all()`) and removes (`remove()`) single exceptions
  race-safely — without rewriting the rest of the definition — and `delete()` takes `force: true`.
- `OpeningHours::fake()` records every write (facade, injected manager, builder and owner trait)
  without touching the database, with `assertSynced()`, `assertExceptionAdded()`,
  `assertExceptionRemoved()`, `assertDeleted()`, `assertRefreshed()` and their opposites.
- `ExceptionData::make()` builds an exception from dates, month-days and range strings.
