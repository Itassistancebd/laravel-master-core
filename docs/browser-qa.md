# Local Browser QA

The project uses Playwright Test with its locally installed Chromium browser. The browser tests start a separate Laravel HTTP server on an available local port and provision a fresh SQLite database for each run. They do not connect to the development MySQL database or use the owner's account. The runner stops only the PHP server process it starts; other listeners are left alone.

## Prerequisites

- Node.js and npm installed, with `npm.cmd install` already completed.
- PHP 8.4+ available on `PATH`, or set `PHP_BINARY` to the intended executable.
- Laravel Composer dependencies installed.
- A production Vite build present at `public/build/manifest.json`.
- Chromium installed with `npx.cmd playwright install chromium`.

The runner creates a unique temporary SQLite database, temporary cache/view directories, and high-entropy test passwords in memory. Laravel's encrypted cookie session is held only in the isolated browser context; no authentication state is written to a Playwright storage-state file. By default it generates both test account emails and passwords for the run. Optional `PLAYWRIGHT_ADMIN_EMAIL`, `PLAYWRIGHT_ADMIN_PASSWORD`, `PLAYWRIGHT_VIEWER_EMAIL`, and `PLAYWRIGHT_VIEWER_PASSWORD` variables override those values; both emails must use the reserved `example.test` domain. Do not reuse a real account or password. Values are passed through child-process environment only and are not written to source files.

## Commands (PowerShell)

```powershell
npm.cmd run test:e2e
npm.cmd run test:e2e:headed
npm.cmd run test:e2e:responsive
npm.cmd run test:e2e:report
```

To capture a new production bundle before browser testing:

```powershell
npm.cmd run build
npm.cmd run test:e2e
```

The run prints the unique screenshot artifact directory and temporary database path, but never prints test passwords. Each screenshot is attached to its Playwright test and also saved under `artifacts/browser-qa/<run-id>/`. HTML reports are written to `playwright-report/`; raw results go to `test-results/`. These generated paths are ignored by Git.

## Coverage

`tests/browser/support.ts` provides UI sign-in/sign-out, protected navigation, common form/table/dialog helpers, viewport changes, screenshots, and redacted browser console/page-error attachments on failures. Tests use Playwright roles and labels.

`tests/browser/phase15-users.spec.ts` checks the Dashboard and Users page, import toolbar permissions, template download, preview validation, duplicate/existing/invalid email handling, confirmed inactive import, row error CSV, server export across more than one page with active filters, UTF-8 BOM/formula protection, and denial for an ordinary signed-in account. Import/export mutation tests run only against generated SQLite data.

The browser runner intentionally retains temporary test databases and artifacts for inspection; remove them manually only when the owner chooses to clean up.
