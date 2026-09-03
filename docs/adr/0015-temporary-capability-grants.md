# ADR-0015 — Temporary capability grants are neither plans nor commercial vouchers

## Status

Accepted — 2026-09-03.

## Context

Support and training sometimes require temporary access to capabilities already present in the platform catalogue. Changing an organization's plan or contracted `PlanVersion` would rewrite a commercial fact merely to solve an operational need. Reusing commercial vouchers would also mix access with price and term, contradicting their explicit boundary.

## Decision

Temporary access is stored in a separate subsystem. A direct platform assignment and a redeemable capability code both create an immutable `capability_grants` row with a frozen module set, start, expiry, source, and audit trail. Expiry is derived from timestamps; revocation marks the row and never deletes history.

`Entitlements::resolve()` is the only consumer. It applies active grants after organization overrides, but only as an upgrade to `Allowed`. An in-force `enabled=false` override remains an explicit administrative lock and wins over every temporary grant.

Voucher redemption serializes admission with `lockForUpdate()` on the capability-voucher row. Capacity is the count of redemption rows inside that transaction, never a mutable counter. One organization may redeem a code only once.

## Consequences

- Plans, plan versions, subscriptions, and commercial vouchers remain unchanged.
- Overlapping grants are additive: one active grant is enough.
- Access returns to the normal plan-version answer immediately after expiry or revocation, without a scheduler.
- The module catalogue is the only vocabulary; neither controllers nor Vue pages invent capability keys.
- Platform operators can audit issuance, direct assignment, revocation, and disablement; organizations retain their own redemption event.

No student, class, assessment, or other pedagogical data is read or sent by this subsystem. Only organization, operator, capability, timing, and stated administrative reason are stored.
