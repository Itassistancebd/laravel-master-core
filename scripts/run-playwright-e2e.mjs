import { spawn } from 'node:child_process';
import { createServer } from 'node:net';
import { randomBytes } from 'node:crypto';
import { access, lstat, mkdir, mkdtemp, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { isAbsolute, join, relative, resolve, sep } from 'node:path';
import process from 'node:process';
import { resolvePhpRuntime } from './php-runtime.mjs';

const root = resolve(import.meta.dirname, '..');
const { executable: php, version: phpVersion, arguments: phpArguments } = await resolvePhpRuntime(root);
process.stdout.write(`PHP runtime: ${php} (PHP ${phpVersion})\n`);
const id = `${new Date().toISOString().replaceAll(':', '').replaceAll('.', '')}-${randomBytes(3).toString('hex')}`;
const isolatedRoot = await mkdtemp(join(tmpdir(), 'master-core-playwright-'));
const cacheDir = join(root, 'storage', 'framework', `browser-qa-${id}`, 'cache');
const relativeCacheDir = cacheDir.slice(root.length + 1).split('\\').join('/');
const viewDir = join(isolatedRoot, 'views');
const dbPath = join(isolatedRoot, 'browser-qa.sqlite');
const artifacts = resolve(root, 'artifacts', 'browser-qa', id);
await Promise.all([mkdir(cacheDir, { recursive: true }), mkdir(viewDir, { recursive: true }), mkdir(artifacts, { recursive: true })]);
await writeFile(dbPath, '', { flag: 'wx' });

const password = () => randomBytes(32).toString('base64url');
const adminEmail = process.env.PLAYWRIGHT_ADMIN_EMAIL ?? `qa-admin-${randomBytes(4).toString('hex')}@example.test`;
const viewerEmail = process.env.PLAYWRIGHT_VIEWER_EMAIL ?? `qa-viewer-${randomBytes(4).toString('hex')}@example.test`;
const adminPassword = process.env.PLAYWRIGHT_ADMIN_PASSWORD ?? password();
const viewerPassword = process.env.PLAYWRIGHT_VIEWER_PASSWORD ?? password();
for (const email of [adminEmail, viewerEmail]) {
    if (!/^[^\s@]+@example\.test$/i.test(email)) throw new Error('Browser QA accounts must use the reserved example.test domain.');
}

const port = await getFreePort();
const baseUrl = `http://127.0.0.1:${port}`;
const routerPath = join(isolatedRoot, 'router.php');
await writeFile(routerPath, `<?php\n$public = ${JSON.stringify(join(root, 'public'))};\n$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';\n$file = realpath($public.$path);\nif ($file && str_starts_with($file, realpath($public).DIRECTORY_SEPARATOR) && is_file($file)) return false;\nrequire $public.'/index.php';\n`);

const env = {
    ...process.env,
    APP_ENV: 'testing',
    APP_DEBUG: 'true',
    APP_URL: baseUrl,
    APP_KEY: `base64:${randomBytes(32).toString('base64')}`,
    APP_CONFIG_CACHE: `${relativeCacheDir}/config.php`,
    APP_ROUTES_CACHE: `${relativeCacheDir}/routes.php`,
    APP_SERVICES_CACHE: `${relativeCacheDir}/services.php`,
    APP_PACKAGES_CACHE: `${relativeCacheDir}/packages.php`,
    APP_EVENTS_CACHE: `${relativeCacheDir}/events.php`,
    DB_CONNECTION: 'sqlite',
    DB_DATABASE: dbPath,
    DB_URL: '',
    DB_FOREIGN_KEYS: 'true',
    SESSION_DRIVER: 'cookie',
    SESSION_COOKIE: `mc-qa-${id}`,
    CACHE_STORE: 'array',
    MAIL_MAILER: 'array',
    QUEUE_CONNECTION: 'sync',
    LOG_CHANNEL: 'stderr',
    VIEW_COMPILED_PATH: viewDir,
    BCRYPT_ROUNDS: '4',
    PLAYWRIGHT_ADMIN_EMAIL: adminEmail,
    PLAYWRIGHT_ADMIN_PASSWORD: adminPassword,
    PLAYWRIGHT_VIEWER_EMAIL: viewerEmail,
    PLAYWRIGHT_VIEWER_PASSWORD: viewerPassword,
    E2E_BASE_URL: baseUrl,
    E2E_ARTIFACT_DIR: artifacts,
    PLAYWRIGHT_OUTPUT_DIR: join(artifacts, 'test-results'),
    PLAYWRIGHT_HTML_OUTPUT_DIR: join(artifacts, 'playwright-report'),
};

let server;
try {
    await assertIsolatedDatabase({ isolatedRoot, dbPath, cacheDir, env });
    process.stdout.write(`Database migration target: isolated SQLite file ${dbPath}\n`);
    await run(php, [...phpArguments, 'artisan', 'migrate', '--force', '--no-interaction'], { env, cwd: root });
    await run(php, [...phpArguments, 'tests/e2e/prepare-database.php'], { env, cwd: root });
    server = spawn(php, [...phpArguments, '-S', `127.0.0.1:${port}`, '-t', join(root, 'public'), routerPath], { cwd: root, env, stdio: ['ignore', 'pipe', 'pipe'], windowsHide: true });
    server.stdout.on('data', (chunk) => writeServerDiagnostics(process.stdout, chunk, env));
    server.stderr.on('data', (chunk) => writeServerDiagnostics(process.stderr, chunk, env));
    await waitForServer(baseUrl, server);
    const args = [join(root, 'node_modules', '@playwright', 'test', 'cli.js'), 'test'];
    if (process.argv.includes('--headed')) env.PLAYWRIGHT_HEADED = '1';
    const grepIndex = process.argv.indexOf('--grep');
    if (grepIndex >= 0 && process.argv[grepIndex + 1]) args.push('--grep', process.argv[grepIndex + 1]);
    const code = await run(process.execPath, args, { env, cwd: root });
    process.stdout.write(`\nPlaywright artifacts: ${artifacts}\nHTML report: ${join(artifacts, 'playwright-report', 'index.html')}\nIsolated SQLite DB retained at: ${dbPath}\n`);
    process.exitCode = code;
} catch (error) {
    process.stderr.write(`${error.message}\nScreenshots/artifacts: ${artifacts}\nHTML report: ${join(artifacts, 'playwright-report', 'index.html')}\nIsolated SQLite DB retained at: ${dbPath}\n`);
    process.exitCode = 1;
} finally {
    if (server && server.exitCode === null) {
        server.kill();
        await new Promise((resolveExit) => server.once('exit', resolveExit));
    }
}

function run(command, args, options) {
    return new Promise((resolveRun, rejectRun) => {
        const child = spawn(command, args, { ...options, stdio: 'inherit', windowsHide: true, shell: process.platform === 'win32' && command.endsWith('.cmd') });
        child.once('error', rejectRun);
        child.once('exit', (code) => code === 0 ? resolveRun(0) : rejectRun(new Error(`${command} exited with code ${code ?? 'unknown'}.`)));
    });
}

async function assertIsolatedDatabase({ isolatedRoot: isolatedDirectory, dbPath: databasePath, cacheDir: configCacheDirectory, env: childEnv }) {
    const isolatedPath = resolve(isolatedDirectory);
    const isInside = (candidate, parent) => {
        const candidateRelative = relative(resolve(parent), resolve(candidate));
        return candidateRelative !== ''
            && candidateRelative !== '..'
            && !candidateRelative.startsWith(`..${sep}`)
            && !isAbsolute(candidateRelative);
    };

    if (childEnv.DB_CONNECTION !== 'sqlite' || childEnv.DB_URL !== '') {
        throw new Error('Browser tests refused to migrate: test connection must be SQLite and DB_URL must be empty.');
    }
    if (resolve(childEnv.DB_DATABASE) !== resolve(databasePath) || !isInside(databasePath, isolatedPath)) {
        throw new Error('Browser tests refused to migrate: database file is outside the unique isolated temporary directory.');
    }

    const databaseStat = await lstat(databasePath);
    if (!databaseStat.isFile() || databaseStat.isSymbolicLink()) {
        throw new Error('Browser tests refused to migrate: isolated SQLite target is not a regular file.');
    }

    for (const cacheKey of ['APP_CONFIG_CACHE', 'APP_ROUTES_CACHE', 'APP_SERVICES_CACHE', 'APP_PACKAGES_CACHE', 'APP_EVENTS_CACHE']) {
        if (!isInside(join(root, childEnv[cacheKey]), configCacheDirectory)) {
            throw new Error(`Browser tests refused to start: ${cacheKey} is outside this run's unique cache directory.`);
        }
    }
    for (const cacheKey of ['APP_CONFIG_CACHE', 'APP_ROUTES_CACHE', 'APP_SERVICES_CACHE', 'APP_PACKAGES_CACHE', 'APP_EVENTS_CACHE']) {
        try {
            await access(childEnv[cacheKey]);
            throw new Error('Browser tests refused to start: isolated framework cache output already exists.');
        } catch (error) {
            if (error?.code !== 'ENOENT') throw error;
        }
    }
}

function getFreePort() {
    return new Promise((resolvePort, rejectPort) => {
        const listener = createServer();
        listener.once('error', rejectPort);
        listener.listen(0, '127.0.0.1', () => {
            const address = listener.address();
            if (!address || typeof address === 'string') {
                listener.close();
                rejectPort(new Error('Could not reserve an ephemeral local TCP port for the isolated test server.'));
                return;
            }
            listener.close(() => resolvePort(address.port));
        });
    });
}

async function waitForServer(url, child) {
    const deadline = Date.now() + 30_000;
    while (Date.now() < deadline) {
        if (child.exitCode !== null) throw new Error('Isolated Laravel HTTP server exited before becoming ready.');
        try {
            const response = await fetch(`${url}/login`);
            if (response.ok) return;
        } catch {}
        await new Promise((resolveDelay) => setTimeout(resolveDelay, 250));
    }
    throw new Error('Isolated Laravel HTTP server did not become ready within 30 seconds.');
}

function sanitize(value, currentEnv) {
    return value
        .replaceAll(currentEnv.PLAYWRIGHT_ADMIN_PASSWORD, '[REDACTED]')
        .replaceAll(currentEnv.PLAYWRIGHT_VIEWER_PASSWORD, '[REDACTED]')
        .replaceAll(currentEnv.PLAYWRIGHT_ADMIN_EMAIL, '[QA ADMIN]')
        .replaceAll(currentEnv.PLAYWRIGHT_VIEWER_EMAIL, '[QA VIEWER]');
}

function writeServerDiagnostics(output, chunk, currentEnv) {
    for (const line of chunk.toString().split(/\r?\n/)) {
        if (/Development Server|PHP (?:Fatal|Parse|Warning)|testing\.(?:ERROR|CRITICAL)/i.test(line)) {
            output.write(`[e2e-server] ${sanitize(line, currentEnv)}\n`);
        }
    }
}
