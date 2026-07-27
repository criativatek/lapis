# ADR-0004 — Pedagogical calculation rules and student-identity encryption

- **Status:** Accepted
- **Date:** 2026-07-18
- **Deciders:** Pedro Alves (product owner)

## Context

Two sets of decisions were pending that the spec forbids inventing:

- **Calculation rules (§1, §13.3):** the domain model (`docs/domain-model.md` §13) listed four questions (Q1–Q4) that block the calculation engine (Fase 2). Their columns exist on `assessment_profile_versions` but were left nullable until decided.
- **Student-identity encryption (§22.3, §31):** storing a student's name requires a proposal before implementing field encryption; §31 lists it among decisions not to take alone.

## Decisions

### Calculation rules — the defaults for every new profile version

These are versioned with the profile (frozen on activation) and editable per version later. New versions are created with them (`ProfileBuilder::create`).

| Rule | Decision | Column value |
|---|---|---|
| **Scale bands (Q1)** | **No automatic conversion.** The system shows the raw value (%); the teacher assigns the level. Complies with §10.4 — no converting descriptors without approved config. | `scale_levels.band_min/max_normalized` stay `NULL`; `normalized_value` `NULL`. The engine returns the raw value and never guesses a level. |
| **Absences (Q2)** | **Exclude and warn.** An absence does not enter the denominator and the system flags insufficient coverage. Never a silent zero. | `absence_mode = 'exclude_all_warn'` |
| **Rounding (Q3)** | **Half up, final only, 0 decimals.** Intermediate values keep full precision; only the final classification is rounded to an integer. | `rounding_mode = 'half_up'`, `rounding_stage = 'final_only'`, `rounding_scale = 0` |
| **Accumulated result (Q4)** | **All valid year elements.** The accumulated result reprocesses every valid assessment element of the year (1st + 2nd semester) as one set with the profile's weights — the literal reading of "todas as aprendizagens do ano letivo". Not an average of period averages. | `accumulated_mode = 'all_valid_year_elements'` |

**Revision — 2026-07-27 (Q1):** the original 2026-07-18 decision remains the default for scales without configured bands, but is superseded for the system scale «Escala 1 a 5». Its approved inclusive normalized bands are: level 1 «Fraco» `0.000000–19.499999`; level 2 «Insuficiente» `19.500000–49.499999`; level 3 «Suficiente» `49.500000–69.499999`; level 4 «Bom» `69.500000–89.499999`; level 5 «Muito Bom» `89.500000–100.000000`. The 0–20, percentage, and custom numeric scales remain without automatic qualitative conversion.

These are defaults, not hard-codes (§4.3): a version can carry different values, and the columns are `CHECK`-constrained to their allowed sets.

**Still open (not blocking):** Q5 — the Intuitivo import format. A real anonymized export file is needed before writing the parser; the spec says CSV/XLSX, a mockup shows `.xml`.

### Student identity — Laravel encryption + blind index

`student_identities` (1:1 with `students`, separate table, own Policy) stores:

- `display_name` — encrypted with Laravel's `encrypted` cast. This uses `APP_KEY` via the framework's authenticated encryption (AES-256-GCM), **not** a bespoke scheme — §22.3 forbids hand-rolled crypto.
- `display_name_index` — a blind index (HMAC-SHA256 of the normalized name, keyed by a dedicated app secret) for exact-match search without decrypting (§22.3).
- The pseudonym (`students.pseudonym_code`, e.g. `ALU-7F2K`) is the only student token that ever crosses the AI boundary (§19.3).

**Consequences / follow-ups to document as they land:**
- Key rotation: re-encrypting `display_name` on `APP_KEY` rotation, and rotating the blind-index HMAC key, need a documented procedure before production.
- Backups: encrypted columns are opaque in dumps; restoring requires the matching keys.
- Search is exact-match only via the blind index; partial/sorted search on names is intentionally not supported (it would leak order).
- No health, special-needs or special-category data (§11.3) — a future `student_support_measures` needs its own specification and reinforced protection.

## Rationale

Every calculation rule took the safe, transparent option: nothing is inferred that the teacher did not configure, absences never become silent zeros, and the accumulated result matches the plain meaning of the mockup's own words. The encryption choice keeps identity data separable and protected from day one (§4.4, privacy from the origin) without inventing cryptography.
