# 0007. Class names state their role

- **Status:** Accepted
- **Date:** 2026-11-02

## Context

A reader should know what a class is without opening it. Laravel's own convention
omits suffixes for enums, interfaces and events; this project prefers explicitness.

## Decision

Technical roles carry a suffix: `Enum`, `Interface`, `DTO`, `Action`, `Service`,
`Event`, `Listener`, `Job`, `Command` (plus the Laravel ones that already have it:
`Controller`, `Request`, `Resource`, `Exception`, `Seeder`).

No suffix for:

- **Eloquent models**: the table name is derived from the class name.
- **Traits**: Laravel binds `initialize<TraitName>` and `boot<TraitName>` methods to
  the trait name, and a mismatch fails silently. The `Has...` prefix already signals a trait.
- **Domain concepts** such as `Money`, and gateway implementations named after what they are (`FakeGateway`).

Pest architecture tests enforce the suffixes inside the role-specific folders.

## Consequences

- (+) Roles are visible in imports and stack traces.
- (-) Longer names; deviates from the usual Laravel idiom.

## Alternatives considered

- **Laravel idiom (no suffixes)**: shorter, but roles are implicit.
- **Suffix everything, including models and traits**: breaks Eloquent table inference and trait initialization.
