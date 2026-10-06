# Changelog

All notable changes to `opening-hours-for-laravel` are documented in this file. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/).

## Unreleased

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
