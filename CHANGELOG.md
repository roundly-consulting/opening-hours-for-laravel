# Changelog

All notable changes to `opening-hours-for-laravel` will be documented in this file.

## 1.0.0 — unreleased

- Opening hours for any Eloquent model: named calendars, weekly schedules (multiple ranges per day,
  overnight and 24-hour ranges), seasonal schedules with priorities, one-off and yearly exceptions,
  dynamic exception providers (Easter offsets built in).
- Immutable query API with one explicit DST boundary rule, verified against a brute-force oracle.
- Canonical array/DTO input, legacy week-array import, `ValidOpeningHours` / `ValidTimeRange` rules,
  fluent builders with optimistic concurrency.
- Availability checks and bookable slots with capacity, buffers, notice and horizon, fed by generic
  busy-period providers (array, closure, composite, null, Eloquent).
- Revision-keyed definition cache, events, API resources, schema.org structured data.
- Opt-in materialized intervals with `whereOpenAt()` / `whereOpenThroughout()` SQL scopes.
- `opening-hours:show`, `opening-hours:prune`, `opening-hours:materialize` commands; `en` and `sk` translations.
