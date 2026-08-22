# Reuse Assessment Profile Implementation Plan

> **For agentic workers:** Implement inline in this session; no sub-agent delegation was requested.

**Goal:** Reuse an active assessment profile's structure in an existing academic year of the same organization.

**Architecture:** A thin package adapter retargets the existing configuration export format. Controller endpoints authorize the source, preview the existing import plan, validate the returned package against a freshly generated canonical profile payload, and delegate writes to the existing importer.

**Tech Stack:** Laravel 13, PHP 8.4, Inertia 3, Vue 3, TypeScript, PHPUnit 12.

## Global Constraints

- Keep tenancy in `CurrentOrganization` and rely on tenant-scoped model lookups.
- Use only `module:assessment_profiles` and `AssessmentProfilePolicy::update`.
- Do not change the three existing configuration-sharing actions.
- Never create an academic year or touch student-related data.

### Task 1: Backend flow

- [ ] Add `BuildYearReusePackage::handle(AssessmentProfile, AcademicYear): array`.
- [ ] Add the three module-gated routes.
- [ ] Add form, preview, and confirm controller methods with authorization, tenant lookups, package integrity validation, audit, toast, and redirect.

### Task 2: Inertia UI

- [ ] Add the year-selection page using `useForm`.
- [ ] Add the plan preview page using the existing four status labels and submit the complete package.
- [ ] Add a `Copy` action beside Edit on the profile index.

### Task 3: Feature coverage and verification

- [ ] Cover independent draft creation, unchanged source, same-year rejection, idempotency, owner-only access, cross-tenant target rejection, and absence of student data.
- [ ] Run the available PHP gates only if PHP/Composer exist.
- [ ] Run `npm run lint:check` and `npm run types:check`, correcting introduced failures.
