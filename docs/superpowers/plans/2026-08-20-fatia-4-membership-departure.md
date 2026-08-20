# Fatia 4 Membership Departure Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Implement safe institutional membership departure, ownership transfer, and reassignment of orphaned classes with complete tenant, audit, history-preservation, and impersonation coverage.

**Architecture:** HTTP controllers perform policy checks, impersonation refusal, input validation, and redirects; focused Actions own transactional state changes and repeat critical guards under row locks. Class reassignment is derived from the absence of `class_teachers` rows, avoiding stored state that can drift. Existing tenancy and audit services remain the only context and traceability mechanisms.

**Tech Stack:** Laravel 13, PHP 8.4, Eloquent, Inertia, PHPUnit 12, Pint, Larastan.

## Global Constraints

- No migrations and no new membership role concept.
- Never delete pedagogical records or modify historical `*_by`/`causer_id` values.
- All mutating HTTP paths refuse support impersonation.
- All class queries execute within `CurrentOrganization`; all target users come from the active organization's members.
- Exposed `SchoolClass` identifiers use ULID; existing `User` binding continues to use its established primary key convention.
- UI messages are pt-PT; code and database vocabulary are English.
- Complete only after targeted tests, Pint, Larastan, and `composer ci:check` pass.

---

### Task 1: Shared impersonation guard and membership domain errors

**Files:**
- Create: `app/Http/Controllers/Concerns/RefusesDuringImpersonation.php`
- Create: `app/Support/Organizations/MembershipException.php`
- Modify: `app/Http/Controllers/TeamController.php`
- Test: `tests/Feature/Organizations/OrganizationMembershipTest.php`

**Interfaces:**
- Produces: `refuseDuringImpersonation(Request $request): void` and named membership exception constructors.

- [ ] Write HTTP tests proving impersonated mutations return 403 and owner-leave returns the exact required message.
- [ ] Run the focused tests and confirm red failures.
- [ ] Extract the unchanged guard into the trait and define distinct exception factories for every invalid membership state.
- [ ] Run the focused tests and confirm the shared behavior remains green.

### Task 2: Leave, remove, and transfer Actions

**Files:**
- Create: `app/Actions/Organizations/LeaveOrganization.php`
- Create: `app/Actions/Organizations/RemoveOrganizationMember.php`
- Create: `app/Actions/Organizations/TransferOrganizationOwnership.php`
- Test: `tests/Feature/Organizations/OrganizationMembershipTest.php`

**Interfaces:**
- Consumes: `MembershipException`, `CurrentOrganization`, `AuditLog`, organization membership and class teacher relations.
- Produces: `leave(Organization, User): void`, `remove(Organization, User, User): void`, and `transfer(Organization, User, User): Organization`.

- [ ] Add one red behavior test at a time for guard order, tenant isolation, detached teaching assignments, preserved accounts/memberships/pedagogical rows, audit events, and immediate ownership refresh.
- [ ] Implement each transaction with `lockForUpdate()`, repeat concurrency-sensitive guards under the lock, detach only tenant-local teacher pivots, and record its audit event inside `runFor()`.
- [ ] Run `php artisan test --filter=OrganizationMembershipTest` after each vertical slice.

### Task 3: Membership policy, controllers, and routes

**Files:**
- Create: `app/Policies/OrganizationMembershipPolicy.php`
- Create: `app/Http/Controllers/OrganizationMembershipController.php`
- Modify: `app/Http/Controllers/TeamController.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/Organizations/OrganizationMembershipTest.php`

**Interfaces:**
- Produces: `POST organizations/leave`, `DELETE team/members/{member}`, and `POST team/members/{member}/transfer-ownership`.

- [ ] Write endpoint tests for member/owner/non-member/cross-tenant/impersonation behavior and redirects.
- [ ] Implement the institutional-owner policy and thin controllers, including validation-style errors and required dashboard/team redirects.
- [ ] Verify routes with `php artisan route:list --path=team` and `--path=organizations/leave`.

### Task 4: Derived class reassignment

**Files:**
- Modify: `app/Models/SchoolClass.php`
- Modify: `app/Policies/SchoolClassPolicy.php`
- Create: `app/Actions/Organizations/AssignClassTeacher.php`
- Create: `app/Http/Controllers/ClassReassignmentController.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/Organizations/ClassReassignmentTest.php`

**Interfaces:**
- Produces: `scopeNeedingReassignment(Builder): Builder`, `assignTeacher(User, SchoolClass): bool`, `assign(SchoolClass, User, User): void`, `GET classes/reassignment`, and `POST classes/reassignment/{class}/assign`.

- [ ] Add red scope tests for no classes, a sole departed teacher, and a remaining co-teacher.
- [ ] Implement the documented derived scope and narrow policy ability.
- [ ] Add red endpoint/action tests for valid assignment, cross-tenant rejection, impersonation, audit, and unchanged authorship/students/enrollments.
- [ ] Implement a locked orphan check, membership guard, owner-role attach, audit record, safe Inertia projection, and tenant-member target resolution.
- [ ] Run `php artisan test --filter=ClassReassignmentTest`.

### Task 5: Release documentation and full verification

**Files:**
- Modify: `config/app.php`
- Modify: `CHANGELOG.md`
- Modify: `docs/status.md`

**Interfaces:**
- Produces: a documented release with verified commands.

- [ ] Increment the pre-1.0 version and document Fatia 4, derived reassignment, tenant isolation, audit, and history preservation.
- [ ] Run `php artisan test --filter="OrganizationMembership|ClassReassignment"` and retain full output.
- [ ] Run `composer lint`, `composer types:check`, and `composer ci:check`; fix every regression.
- [ ] Review `git diff --check`, the final route list, and changed files against all non-negotiables.
