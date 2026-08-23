# Multi-domain simple instrument creation

## Goal

Allow Criação simples to build a canonical assessment with several domains and several real questions per domain, while keeping advanced creation and existing instruments compatible.

## Canonical structure

- One unnamed implicit `InstrumentGroup` contains every quick-created item.
- Every question is an `InstrumentItem`, globally ordered and coded `Q1` through `Qn` inside that group.
- Every ordinary question starts with one `ItemDomainAllocation` at 100% for its home domain.
- A question may exceptionally use several canonical allocations whose percentages total 100%.
- No migration, parallel table, score, result, or completion record is created.

## Simple-mode interaction

The teacher selects assessed domains, then chooses a visible question count for each. A newly selected domain starts with one question. Reducing from one question removes the domain explicitly; a selected domain can never silently remain with zero questions.

The declared total starts at 100 points. Questions receive a visible initial even distribution, with any decimal remainder placed on the final item so the displayed sum is exact. Every item cotação remains editable. Domain points and percentages are derived live from item points and allocations.

Normal questions show their code, cotação, home domain, and a collapsed “Editar domínios” action. The expanded exceptional editor uses percentages and requires a total of 100%. Advanced creation continues to use its existing point-based allocation editor.

## Mode transition

Simple and advanced modes edit the same in-memory items. Switching never clears or recreates them. Simple mode remains available for one implicit group, non-bonus point-scored items, valid global Q codes, and allocations representable by the compact editor. Explicit groups, bonuses, or special structures keep simple mode unavailable.

## Workbook

The correction workbook keeps its visible header on row 1 and students on row 2. It has exactly one column per real item. Headings expose the domain, question and maximum cotação; fills and borders visually group adjacent single-domain items. Multi-domain items are marked without duplication. Existing defined names `LAPIS_ITEM_<column>` continue to map each column to the real item ULID.

## Safety and compatibility

`InstrumentRequest` and `InstrumentBuilder` continue to enforce class authorization, tenant ownership, profile-domain membership, period/year membership, totals and 100% allocation sums. Saving leaves status prepared and creates no `StudentItemScore`. Existing v0.57.x one-domain/one-item records continue through the normal edit flow.

