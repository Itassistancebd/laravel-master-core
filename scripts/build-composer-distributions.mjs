import { access, copyFile, cp, mkdir, readFile, writeFile } from 'node:fs/promises';
import { dirname, join, relative, resolve, sep } from 'node:path';
import { fileURLToPath } from 'node:url';

const projectRoot = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const packages = [
    ['packages/core/foundation', 'laravel-master-core-foundation'],
    ['packages/core/master-core', 'laravel-master-core'],
    ['packages/core/settings', 'laravel-master-core-settings'],
    ['packages/core/auth', 'laravel-master-core-auth'],
    ['packages/core/user-management', 'laravel-master-core-user-management'],
    ['packages/core/roles-permissions', 'laravel-master-core-roles-permissions'],
    ['packages/core/audit-logs', 'laravel-master-core-audit-logs'],
    ['packages/core/module-manager', 'laravel-master-core-module-manager'],
    ['packages/core/system-health', 'laravel-master-core-system-health'],
    ['packages/core/notifications', 'laravel-master-core-notifications'],
    ['packages/core/crud-engine', 'laravel-master-core-crud-engine'],
    ['packages/tools/project-generator', 'laravel-master-core-project-generator'],
];

const options = parseArguments(process.argv.slice(2));
const vendor = options.vendor;
const version = options.version;
if (!vendor || !/^[a-z0-9][a-z0-9_.-]*$/i.test(vendor) || vendor.toLowerCase() === 'example') {
    throw new Error('Pass a verified Composer vendor with --vendor=<vendor>. Placeholder vendors are rejected.');
}
if (!version || !/^\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?$/.test(version)) {
    throw new Error('Pass an explicit release version with --version=<semver>.');
}
if (!options.output) {
    throw new Error('Pass an output directory outside the project with --output=<absolute-path>.');
}

const output = resolve(options.output);
const relativeOutput = relative(projectRoot, output);
if (relativeOutput === '' || (!relativeOutput.startsWith(`..${sep}`) && relativeOutput !== '..')) {
    throw new Error('Distribution output must be outside the source project.');
}
try {
    await access(output);
    throw new Error('Distribution output already exists; choose a new empty path.');
} catch (error) {
    if (error.code !== 'ENOENT') throw error;
}

const sourceManifests = [];
const sourceNames = new Map();
for (const [sourceRelative, packageSlug] of packages) {
    const manifest = JSON.parse(await readFile(join(projectRoot, sourceRelative, 'composer.json'), 'utf8'));
    if (manifest.name !== `example/${packageSlug}`) {
        throw new Error(`${sourceRelative}/composer.json does not match the expected source package identity.`);
    }
    sourceNames.set(manifest.name, packageSlug);
    sourceManifests.push({ sourceRelative, packageSlug, manifest });
}
for (const { sourceRelative, manifest } of sourceManifests) {
    for (const [dependency, constraint] of Object.entries(manifest.require ?? {})) {
        if (sourceNames.has(dependency) && constraint !== `^${version}`) {
            throw new Error(`${sourceRelative}: internal dependency ${dependency} uses ${constraint}; expected ^${version} for this release snapshot.`);
        }
    }
}

await mkdir(output, { recursive: false });
const index = [];
for (const { sourceRelative, packageSlug } of sourceManifests) {
    const source = join(projectRoot, sourceRelative);
    const destination = join(output, packageSlug);
    await cp(source, destination, { recursive: true, errorOnExist: true, force: false, dereference: false });
    await copyFile(join(projectRoot, 'LICENSE'), join(destination, 'LICENSE'));
    const manifestPath = join(destination, 'composer.json');
    const manifest = JSON.parse(await readFile(manifestPath, 'utf8'));
    manifest.name = `${vendor}/${packageSlug}`;
    const rewritten = rewritePackageReferences(manifest, vendor);
    await writeFile(manifestPath, `${JSON.stringify(rewritten, null, 2)}\n`);
    const readmePath = join(destination, 'README.md');
    try {
        const sourceReadme = await readFile(readmePath, 'utf8');
        let distributionReadme = sourceReadme
            .replace(/example\/laravel-master-core/g, `${vendor}/laravel-master-core`)
            .replace(/\]\(\.\.\/\.\.\/\.\.\/docs\/([^)]+)\)/g, `](https://github.com/Itassistancebd/laravel-master-core/blob/main/docs/$1)`);
        if (packageSlug === 'laravel-master-core') {
            distributionReadme = distributionReadme
                .replace('used by the local monorepo.', 'intended for Composer consumers.')
                .replace('The Composer vendor name is a placeholder. Registry publication must wait until the project owner confirms control of the Composer vendor and source repository.', `The configured vendor \`${vendor}\` is a candidate used for this local snapshot. Verify Packagist namespace ownership and the package repository before publication.`);
        }
        await writeFile(readmePath, distributionReadme);
    } catch (error) {
        if (error.code !== 'ENOENT') throw error;
    }
    index.push({ name: rewritten.name, version, source: sourceRelative, output: packageSlug });
}

const lines = [
    '# Laravel Master Core Composer distribution snapshot',
    '',
    `Composer vendor: \`${vendor}\``,
    `Release version: \`${version}\``,
    '',
    'This directory contains reproducible source snapshots for the twelve Composer packages in the Laravel Master Core monorepo.',
    'The vendor and version were supplied to the export command; their availability and ownership are not verified by this tool.',
    'Each folder has its own root-level `composer.json` and can be used to create or update one package repository after identity and access checks.',
    'This snapshot export does not preserve per-package Git history. Keep the monorepo as the source of truth and use a separately reviewed history-preserving split process if package history is required.',
    `For a twelve-repository release, review each package snapshot from the same monorepo source commit, create an annotated v${version} tag in each corresponding package repository, and confirm that Packagist resolves that tag before consumers require it. The vendor and repository ownership must be verified separately.`,
    '',
    '## Packages',
    '',
    ...index.map((item) => `- \`${item.name}\` (${item.version}) — \`${item.output}/\``),
    '',
    'Do not publish or push this output until each namespace, repository, package tree, version, and release artifact has been reviewed.',
    '',
];
await writeFile(join(output, 'README.md'), lines.join('\n'), { flag: 'wx' });
await writeFile(join(output, 'snapshot-manifest.json'), `${JSON.stringify({ schema_version: 1, vendor, version, packages: index }, null, 2)}\n`, { flag: 'wx' });
console.log(`Prepared ${index.length} Composer package snapshots at ${output}. No repositories or registry entries were created.`);

function parseArguments(args) {
    const parsed = {};
    for (const argument of args) {
        const match = argument.match(/^--(vendor|version|output)=(.+)$/);
        if (!match) throw new Error(`Unknown argument: ${argument}`);
        parsed[match[1]] = match[2];
    }
    return parsed;
}

function rewritePackageReferences(value, namespace) {
    if (Array.isArray(value)) return value.map((item) => rewritePackageReferences(item, namespace));
    if (!value || typeof value !== 'object') {
        return typeof value === 'string'
            ? value.replace(/example(?=\/laravel-master-core(?:[-/]|$))/g, namespace)
            : value;
    }
    return Object.fromEntries(Object.entries(value).map(([key, item]) => [
        key.replace(/example(?=\/laravel-master-core(?:[-/]|$))/g, namespace),
        rewritePackageReferences(item, namespace),
    ]));
}
