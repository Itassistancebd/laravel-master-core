import { readFile } from 'node:fs/promises';
import { join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { dirname } from 'node:path';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const readJson = async (path) => JSON.parse(await readFile(join(root, path), 'utf8'));
const rootPackage = await readJson('package.json');
const rootComposer = await readJson('composer.json');
const lock = await readJson('package-lock.json');
const composerLock = await readJson('composer.lock');
const profile = await readJson('packages/tools/project-generator/resources/profiles/master-core-v1.json');
const workspaces = new Map();

for (const directory of rootPackage.workspaces ?? []) {
    const manifest = await readJson(join(directory, 'package.json'));
    if (!manifest.name || workspaces.has(manifest.name)) throw new Error(`Missing or duplicate npm workspace name at ${directory}.`);
    workspaces.set(manifest.name, { directory, manifest });
}

const composerManifests = new Map();
for (const path of [
    'packages/core/foundation', 'packages/core/master-core', 'packages/core/settings', 'packages/core/auth',
    'packages/core/user-management', 'packages/core/roles-permissions', 'packages/core/audit-logs',
    'packages/core/module-manager', 'packages/core/system-health', 'packages/core/notifications',
    'packages/core/crud-engine', 'packages/tools/project-generator',
]) {
    const manifest = await readJson(`${path}/composer.json`);
    composerManifests.set(manifest.name, { path, manifest });
}

const errors = [];
for (const [name, { directory, manifest }] of workspaces) {
    if (!name.startsWith('@itassistance-bd/')) errors.push(`${name}: unexpected workspace scope.`);
    if (manifest.private !== false || manifest.license !== 'MIT') errors.push(`${name}: expected public-package metadata and MIT license.`);
    if (manifest.publishConfig?.access !== 'public' || manifest.publishConfig?.registry !== 'https://registry.npmjs.org/') errors.push(`${name}: public npm publish configuration is incomplete.`);
    if (manifest.repository?.url !== 'https://github.com/Itassistancebd/laravel-master-core.git' || manifest.repository?.directory !== directory) errors.push(`${name}: repository metadata does not identify its monorepo directory.`);
    if (manifest.engines?.node !== profile.toolchain.node || manifest.engines?.npm !== profile.toolchain.npm) errors.push(`${name}: engine constraints disagree with the generator profile.`);
    const locked = lock.packages?.[`node_modules/${name}`];
    if (!locked?.link || locked.resolved !== directory) errors.push(`${name}: package-lock workspace link is missing or inconsistent.`);
    for (const [dependency, constraint] of Object.entries(manifest.dependencies ?? {})) {
        if (workspaces.has(dependency) && constraint !== `^${workspaces.get(dependency).manifest.version}`) errors.push(`${name}: internal dependency ${dependency} does not match its workspace version.`);
    }
}

for (const [name, { manifest }] of composerManifests) {
    const rootConstraint = rootComposer.require?.[name] ?? rootComposer['require-dev']?.[name];
    if (rootConstraint !== '^0.1.0') errors.push(`${name}: root Composer host does not require the local package at the expected version.`);
    const lockedPackage = [...(composerLock.packages ?? []), ...(composerLock['packages-dev'] ?? [])].find((item) => item.name === name);
    if (!lockedPackage || !/^0\.1\.0(?:\.0)?$/.test(lockedPackage.version ?? '')) errors.push(`${name}: Composer lock entry is missing or has an unexpected version.`);
    for (const [dependency, constraint] of Object.entries(manifest.require ?? {})) {
        if (composerManifests.has(dependency) && constraint !== '^0.1.0') errors.push(`${name}: internal dependency ${dependency} has unexpected constraint ${constraint}.`);
    }
}

for (const [feature, definition] of Object.entries(profile.metapackage.extra.optional_features)) {
    const composer = [...composerManifests.values()].find(({ manifest }) => manifest.name === definition.composer)?.manifest;
    const npm = workspaces.get(definition.npm)?.manifest;
    if (!composer || !npm || profile.npm_packages[feature]?.name !== npm.name) errors.push(`${feature}: profile identities do not match both package manifests.`);
    const sourceComposerDependencies = Object.keys(composer?.require ?? {}).filter((dependency) => composerManifests.has(dependency)).sort();
    if (JSON.stringify(sourceComposerDependencies) !== JSON.stringify([...definition.composer_dependencies].sort())) errors.push(`${feature}: profile Composer dependency closure differs from its manifest.`);
}

if (!workspaces.has(profile.shared_npm.name)) errors.push('Shared UI identity is missing from npm workspaces.');
if (errors.length > 0) {
    console.error(errors.join('\n'));
    process.exitCode = 1;
} else {
    console.log(`PASS: ${workspaces.size} npm workspaces and ${composerManifests.size} Composer package manifests align with generator profile and lock metadata.`);
}
