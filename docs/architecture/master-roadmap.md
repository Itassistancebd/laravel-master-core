# Master Core Roadmap

This roadmap records repository phase history and the Core Completion Campaign separately. The campaign's phase numbers came from its request and do not rename the repository's historical package phases.

## Core Completion Campaign (2026-11-17)

| Campaign phase | Scope | Repository record | Status | Exit condition still open |
|---|---|---|---|---|
| 4 | Roles & Permissions completion | Phase 6 Roles & Permissions | **PASS** | No campaign blocker. Host-specific authorization review remains a deployment responsibility. |
| 6 | Settings Management completion | Phase 3 Settings | **PASS** | No campaign blocker. |
| 8 | CRUD Engine completion | Phase 9 CRUD Engine | **PASS** | Categories pilot is registered and browser accepted. |
| 10 | Package & Distribution | Phase 10 Master Core Package | **PARTIAL** | Local npm scope migration, lock metadata, PHP/npm tests, build, package dry runs, and responsive browser suites pass. GitHub org is confirmed but has no public repositories; npm ownership and Composer vendor remain unverified, so clean external consumer acceptance is PARTIAL. |
| 11 | Project Generator | Phase 11 Project Generator | **PARTIAL** | Generator profile and focused tests pass; a real host install is still blocked on verified Composer/npm registries and trusted-source configuration. |
| 12 | Admin UI refinement | Campaign-only | **PASS** | 17 registered Admin routes passed responsive Chromium checks at desktop, tablet, and mobile sizes; evidence and absent UI routes are documented in the [Phase 12 report](phase12-admin-ui-refinement-implementation-report.md). |
| 14 | RBAC live QA | Campaign-only; reuses Phase 6 | **PASS** | Isolated QA passed. No configured live role grants or Super Admin account was changed. |

Detailed evidence and blockers are in the [Core Completion Campaign report](core-completion-campaign-2026-11-17.md). The Phase 8 Categories adoption is documented in the [pilot report](phase8-crud-engine-categories-pilot.md). Phase 10 package names and release evidence are tracked in the [identity migration record](phase10-package-identity-migration-record.md) and [identity/release proposal](phase10-package-identity-and-release-proposal.md).

## Repository phase history

| Repository phase | Package or area | Current record |
|---|---|---|
| 1 | Foundation | `README.md` and package guide |
| 2 | Zero-table Core schema | [Phase 2 completion](phase2-completion.md) |
| 3 | Settings | [Phase 3 completion](phase3-settings-completion.md) |
| 4 | Authentication & Security | [Phase 4 completion](phase4-auth-security-completion.md) |
| 5 | User Management | [Phase 5 completion](phase5-user-management-completion.md) |
| 6 | Roles & Permissions | [Phase 6 completion](phase6-roles-permissions-completion.md) |
| 7 | Audit Logs | [Phase 7 completion](phase7-audit-logs-completion.md) |
| 8 | Module Registry & Manager | [Phase 8 completion](phase8-module-manager-completion.md) |
| 9 | System Health & Readiness | [Phase 9 completion](phase9-system-health-readiness-completion.md) |
| 9 | CRUD Engine (historical duplicate label) | [CRUD Engine report](phase9-crud-engine-implementation-report.md) |
| 10 | Notifications | [Phase 10 completion](phase10-notifications-completion.md) |
| 10 | Master Core package distribution | [Package report](phase10-master-core-package-implementation-report.md) |
| 11 | Project Generator | [Generator report](phase11-project-generator-implementation-report.md) |

The duplicate repository phase labels are retained to preserve existing records. Phase 16 Plugin Architecture has not started.
