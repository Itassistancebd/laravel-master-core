import { readFile, writeFile } from 'node:fs/promises';
import { dirname, isAbsolute, join, relative, resolve, sep } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const options = Object.fromEntries(process.argv.slice(2).map((arg) => {
    const match = arg.match(/^--(directory|vendor|version|report)=(.+)$/);
    if (!match) throw new Error(`Unknown argument: ${arg}`);
    return [match[1], match[2]];
}));
if (!options.directory || !options.vendor || !options.version || !options.report) {
    throw new Error('Usage: node scripts/validate-composer-distributions.mjs --directory=<snapshot-root> --vendor=<vendor> --version=<semver> --report=<new-file-outside-project>.');
}

const directory = resolve(options.directory);
const reportPath = resolve(options.report);
for (const [label, path] of [['snapshot', directory], ['report', reportPath]]) {
    const relativePath = relative(root, path);
    if (relativePath === '' || (!relativePath.startsWith(`..${sep}`) && relativePath !== '..')) {
        throw new Error(`The ${label} path must be outside the source project.`);
    }
}
const slugs = [
    'laravel-master-core-foundation', 'laravel-master-core', 'laravel-master-core-settings',
    'laravel-master-core-auth', 'laravel-master-core-user-management',
    'laravel-master-core-roles-permissions', 'laravel-master-core-audit-logs',
    'laravel-master-core-module-manager', 'laravel-master-core-system-health',
    'laravel-master-core-notifications', 'laravel-master-core-crud-engine',
    'laravel-master-core-project-generator',
];
const packages = new Map();
for (const slug of slugs) {
    const path = join(directory, slug, 'composer.json');
    const manifest = JSON.parse(await readFile(path, 'utf8'));
    const expectedName = `${options.vendor}/${slug}`;
    const readmePath = join(directory, slug, 'README.md');
    const licensePath = join(directory, slug, 'LICENSE');
    if (manifest.name !== expectedName) throw new Error(`${slug}: expected root package name ${expectedName}.`);
    if (!manifest.type || !manifest.license || manifest.license !== 'MIT') throw new Error(`${slug}: package type/license metadata is incomplete.`);
    const license = await readFile(licensePath, 'utf8');
    if (!license.startsWith('MIT License') || !license.includes('Copyright (c) 2026 IT Assistance BD')) {
        throw new Error(`${slug}: exported MIT LICENSE file is missing or has an unexpected copyright line.`);
    }
    for (const [namespace, source] of Object.entries(manifest.autoload?.['psr-4'] ?? {})) {
        if (typeof source !== 'string' || !await exists(join(directory, slug, source))) {
            throw new Error(`${slug}: PSR-4 path for ${namespace} is missing from the snapshot.`);
        }
    }
    for (const binary of (Array.isArray(manifest.bin) ? manifest.bin : manifest.bin ? [manifest.bin] : [])) {
        if (!await exists(join(directory, slug, binary))) throw new Error(`${slug}: declared binary ${binary} is missing.`);
    }
    if (/example\/laravel-master-core/.test(JSON.stringify(manifest))) throw new Error(`${slug}: placeholder Composer package reference remains.`);
    try {
        const readme = await readFile(readmePath, 'utf8');
        if (/example\/laravel-master-core/.test(readme)) throw new Error(`${slug}: placeholder Composer package reference remains in README.md.`);
        if (/\]\(\.\.\/\.\.\/\.\.\/docs\//.test(readme)) throw new Error(`${slug}: README.md contains a relative monorepo docs link that will break in the package repository.`);
        if (readme.includes(slug) && !readme.includes(expectedName)) throw new Error(`${slug}: package README references its slug without the expected Composer identity ${expectedName}.`);
    } catch (error) {
        if (error.code !== 'ENOENT') throw error;
    }
    packages.set(expectedName, { slug, manifest });
}

for (const { slug, manifest } of packages.values()) {
    for (const [dependency, constraint] of Object.entries(manifest.require ?? {})) {
        if (packages.has(dependency) && constraint !== `^${options.version}`) {
            throw new Error(`${slug}: internal dependency ${dependency} has ${constraint}; expected ^${options.version}.`);
        }
    }
}

const report = {
    status: 'PASS',
    directory,
    vendor: options.vendor,
    version: options.version,
    vendor_ownership: 'not verified by local snapshot validation',
    packages: [...packages].map(([name, { slug, manifest }]) => ({
        name,
        version_constraint: options.version,
        type: manifest.type,
        license: manifest.license,
        internal_dependencies: Object.keys(manifest.require ?? {}).filter((dependency) => packages.has(dependency)),
        status: 'PASS',
    })),
};
await writeFile(reportPath, `${JSON.stringify(report, null, 2)}\n`, { flag: 'wx' });
console.log(`PASS: ${packages.size} Composer snapshots have root manifests, matching identities, valid internal constraints, and present autoload/bin paths.`);
console.log(`Report: ${reportPath}. Vendor ownership was not checked.`);

async function exists(path) {
    try {
        await readFile(path);
        return true;
    } catch (error) {
        if (error.code === 'EISDIR') return true;
        if (error.code === 'ENOENT') return false;
        throw error;
    }
}
