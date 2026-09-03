# Temporary Capability Grants Design

## Purpose

Allow a platform operator to grant one organization temporary access to one or more existing module keys without changing its plan, plan version, subscription, or the commercial voucher subsystem.

## Architecture

The subsystem has immutable presets and voucher definitions, immutable grant instances with frozen module pivots, and immutable redemption records. A voucher redemption and a direct assignment both create the same `capability_grants` record. Only voucher disablement and grant revocation are mutable lifecycle operations.

`Entitlements::resolve()` remains the only access decision point. After normal plan, suspension, retained-read-only, and organization override resolution, active non-revoked grants upgrade a module to `Allowed`, except where an in-force `enabled=false` organization override explicitly locks it.

## Flows

- Platform operators maintain internal presets and issue capability vouchers from a preset or an ad-hoc module list.
- An authorized organization owner redeems a code from plan settings. Redemption locks the voucher row, revalidates its window, restriction, duplicate use, and derived capacity, then atomically creates the grant and redemption.
- Platform operators assign modules directly from the account detail page with a mandatory reason and may revoke any grant.
- Expiry is date-derived. No scheduler deletes or mutates history.

## Boundaries and safety

All module keys are resolved from `modules`; the frontend receives the catalogue and never hardcodes it. The commercial voucher tables and all plan-composition tables remain untouched. Platform writes use platform audit events; voucher redemption records an organization-scoped event. ULIDs are used for exposed voucher, grant, and redemption records.

## Verification

Feature tests cover resolver precedence, dates, revocation, tenant isolation, multiple and overlapping grants, voucher windows/restrictions/capacity/idempotency, authorization, and audit actors. Pint, Larastan, frontend lint/type checks/build, targeted tests, and the full PHPUnit suite are required before completion.
