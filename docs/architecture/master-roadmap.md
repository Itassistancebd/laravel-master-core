# Master Core Roadmap

This roadmap records repository phase history and the Core Completion Campaign separately. The campaign's phase numbers came from its request and do not rename the repository's historical package phases.

## Core Completion Campaign (2026-11-17)

| Campaign phase | Scope | Repository record | Status | Exit condition still open |
|---|---|---|---|---|
| 4 | Roles & Permissions completion | Phase 6 Roles & Permissions | **PASS** | No campaign blocker. Host-specific authorization review remains a deployment responsibility. |
| 6 | Settings Management completion | Phase 3 Settings | **PASS** | No campaign blocker. |
| 8 | CRUD Engine completion | Phase 9 CRUD Engine | **PASS (historical)** | The Categories pilot was accepted and later removed from the current product; the generic CRUD Engine remains. |
| 10 | Package & Distribution | Phase 10 Master Core Package | **PARTIAL** | Ten npm builds/pack dry-runs and local tarball consumer imports pass; `itassistancebd` is an npm organization owner with account 2FA enabled, but all ten registry names return 404 and organization-wide 2FA enforcement is unverified. Twelve Composer snapshots and a 96-archive `--prefer-dist` consumer validate locally; Packagist vendor ownership is unverified. See the [package release guide](../development/package-release-guide.md) and [integrated report](phase10-11-integrated-distribution.md). |
| 11 | Project Generator | Phase 11 Project Generator | **PARTIAL** | Monorepo-template generation and third-party Composer/npm installation passed twice from local source; the published `main` still lacks the installer, so public-source generation and fresh-PC installation remain unverified. Standalone feature-selection mode retains trusted-source checks. See the [generator guide](phase11-project-generator-usage.md) and [integrated report](phase10-11-integrated-distribution.md). |
| 12 | Admin UI refinement | Campaign-only | **PASS** | Revalidated: all 17 registered Admin routes at 1440x900, 768x1024, and 390x844; dedicated responsive suite 5/5 and full suite 8/8 passed with no unexpected console/API/overflow failures. Evidence and screenshots are in the [Phase 12 report](phase12-admin-ui-refinement-implementation-report.md). |
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

## Global CLI and GitHub source workflow (2026-11-18)

**PARTIAL.** A local Windows bootstrap/CLI implementation and guarded GitHub maintainer workflow are prepared. The public `main` branch does not yet contain them. `master new` invokes the Phase 11 monorepo-template installer, copies the existing Laravel application, and uses local path/workspace sources for internal packages. Normal Composer/npm registries serve third-party dependencies. A clean public-source generation remains unverified; this environment's isolated Composer install stalled on outbound HTTPS. See the [CLI and distribution architecture](global-cli-and-monorepo-distribution.md), [installation guide](../development/installation.md), [global CLI guide](../development/global-cli.md), [maintainer workflow](../development/maintainer-workflow.md), and [release/update guide](../development/release-and-updates.md).
