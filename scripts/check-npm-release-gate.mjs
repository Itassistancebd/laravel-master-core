import { readFile } from 'node:fs/promises';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const registry = 'https://registry.npmjs.org/';

async function main() {
    const args = new Map(process.argv.slice(2).map((arg) => {
        const split = arg.indexOf('=');
        return split < 0 ? [arg, ''] : [arg.slice(0, split), arg.slice(split + 1)];
    }));
    const name = args.get('--name');
    const version = args.get('--version');
    if (args.size !== 2 || !name || !version) {
        throw new Error('Usage: node scripts/check-npm-release-gate.mjs --name=<workspace-package> --version=<manifest-version>.');
    }

    const rootManifest = JSON.parse(await readFile(resolve(root, 'package.json'), 'utf8'));
    const packages = new Map();
    for (const directory of rootManifest.workspaces ?? []) {
        const manifest = JSON.parse(await readFile(resolve(root, directory, 'package.json'), 'utf8'));
        packages.set(manifest.name, { directory, manifest });
    }
    const selected = packages.get(name);
    if (!selected) throw new Error(`${name} is not a registered workspace package.`);
    const { manifest } = selected;
    if (!name.startsWith('@itassistance-bd/')) throw new Error(`${name} is outside the approved npm scope.`);
    if (manifest.version !== version) throw new Error(`${name} manifest is ${manifest.version}; requested release is ${version}.`);
    if (!/^0\.\d+\.\d+$/.test(version)) throw new Error(`Refusing unsupported release version ${version}; confirm the package's pre-1.0 release policy first.`);
    if (manifest.private !== false || manifest.license !== 'MIT'
        || manifest.publishConfig?.access !== 'public'
        || manifest.publishConfig?.registry !== 'https://registry.npmjs.org/') {
        throw new Error(`${name} is not explicitly configured as a public MIT package on registry.npmjs.org.`);
    }

    const metadata = await getPackage(name);
    if (metadata?.versions?.[version]) throw new Error(`${name}@${version} already exists; immutable version overwrite is refused.`);

    for (const [dependency, range] of Object.entries(manifest.dependencies ?? {})) {
        if (!packages.has(dependency)) continue;
        const dependencyMetadata = await getPackage(dependency);
        const matches = Object.keys(dependencyMetadata?.versions ?? {}).some((candidate) => satisfiesCaret(candidate, range));
        if (!matches) throw new Error(`${name} requires ${dependency}@${range}, but no compatible public version is available.`);
    }

    console.log(`PASS ${name}@${version}: public identity, immutable version, and published internal dependencies verified.`);
}

main().catch((error) => {
    console.error(`NPM release gate failed: ${error.message}`);
    process.exitCode = 1;
});

async function getPackage(packageName) {
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), 15_000);
    let response;
    try {
        response = await fetch(`${registry}${encodeURIComponent(packageName)}`, {
            headers: { accept: 'application/vnd.npm.install-v1+json' },
            signal: controller.signal,
        });
    } finally {
        clearTimeout(timeout);
    }
    if (response.status === 404) {
        await response.body?.cancel();
        return null;
    }
    if (!response.ok) throw new Error(`Registry lookup for ${packageName} returned HTTP ${response.status}.`);
    return response.json();
}

function satisfiesCaret(candidate, range) {
    const baseMatch = /^\^(\d+)\.(\d+)\.(\d+)$/.exec(range);
    const candidateMatch = /^(\d+)\.(\d+)\.(\d+)$/.exec(candidate);
    if (!baseMatch || !candidateMatch) throw new Error(`Unsupported internal dependency range ${range}; release gate refuses to guess.`);
    const base = baseMatch.slice(1).map(Number);
    const current = candidateMatch.slice(1).map(Number);
    if (base[0] === 0 && base[1] === 0) return current[0] === 0 && current[1] === 0 && current[2] === base[2];
    if (current[0] !== base[0]) return false;
    if (base[0] === 0 && current[1] !== base[1]) return false;
    for (let index = 0; index < 3; index += 1) {
        if (current[index] !== base[index]) return current[index] > base[index];
    }
    return true;
}
