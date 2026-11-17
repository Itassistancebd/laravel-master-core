import { spawnSync } from 'node:child_process';
import { readFile, writeFile } from 'node:fs/promises';
import { dirname, isAbsolute, join, relative, resolve, sep } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const registry = 'https://registry.npmjs.org/';
const scope = '@itassistance-bd/';
const rootManifest = JSON.parse(await readFile(join(root, 'package.json'), 'utf8'));
const packages = new Map();

for (const directory of rootManifest.workspaces ?? []) {
    const path = resolve(root, directory);
    const manifest = JSON.parse(await readFile(join(path, 'package.json'), 'utf8'));
    if (packages.has(manifest.name)) throw new Error(`Duplicate npm package name: ${manifest.name}`);
    packages.set(manifest.name, { directory, path, manifest });
}
if (packages.size !== 10) throw new Error(`Expected ten npm release packages; found ${packages.size}.`);

const versions = new Set([...packages.values()].map(({ manifest }) => manifest.version));
if (versions.size !== 1) throw new Error('npm release packages do not share one version. Align package versions before release preparation.');
const version = [...versions][0];
for (const [name, { directory, path, manifest }] of packages) {
    if (!name.startsWith(scope) || manifest.private !== false || manifest.license !== 'MIT') {
        throw new Error(`${name}: scope, public status, or MIT license metadata is invalid.`);
    }
    if (manifest.publishConfig?.access !== 'public' || manifest.publishConfig?.registry !== registry) {
        throw new Error(`${name}: publishConfig must select public access and ${registry}.`);
    }
    if (manifest.repository?.url !== 'https://github.com/Itassistancebd/laravel-master-core.git'
        || manifest.repository?.directory !== directory) {
        throw new Error(`${name}: repository URL or workspace directory metadata is inconsistent.`);
    }
    if (!manifest.scripts?.build || !manifest.scripts?.prepack || !(manifest.files ?? []).includes('dist')
        || !(manifest.files ?? []).includes('README.md')) {
        throw new Error(`${name}: build/prepack scripts or package files allowlist are incomplete.`);
    }
    for (const [dependency, constraint] of Object.entries(manifest.dependencies ?? {})) {
        if (packages.has(dependency) && constraint !== `^${packages.get(dependency).manifest.version}`) {
            throw new Error(`${name}: internal dependency ${dependency} must target ^${packages.get(dependency).manifest.version}.`);
        }
    }
    const readme = await readFile(join(path, 'README.md'), 'utf8');
    if (!readme.includes(`npm install ${name}`)) throw new Error(`${name}: README must document its external install command.`);
}

const order = topologicalOrder(packages);
const reportPath = parseReportPath(process.argv.slice(2));
const reportRelative = relative(root, reportPath);
if (!isAbsolute(reportPath) || reportRelative === '' || (!reportRelative.startsWith(`..${sep}`) && reportRelative !== '..')) {
    throw new Error('The dry-run report must be written to a new path outside the project.');
}

console.log(`Building ${packages.size} npm packages in dependency order (${version}).`);
run(process.execPath, [join(root, 'scripts/build-core-packages.mjs'), ...order], root, true);

const report = {
    status: 'PASS',
    mode: 'npm pack --dry-run; no package was published',
    registry,
    access: 'public',
    version,
    dependency_order: order,
    packages: [],
};

for (const name of order) {
    const { path, manifest } = packages.get(name);
    const result = run('npm.cmd', ['pack', '--dry-run', '--json', '--ignore-scripts', `--registry=${registry}`], path, false);
    let packed;
    try {
        packed = JSON.parse(result.stdout);
    } catch {
        throw new Error(`npm pack output was not valid JSON for ${name}; no package was published.`);
    }
    const artifact = Array.isArray(packed) ? packed[0] : packed;
    const files = new Set((artifact?.files ?? []).map(({ path: file }) => file));
    const expected = [manifest.exports?.['.']?.import, manifest.exports?.['.']?.types]
        .filter(Boolean)
        .map((file) => file.replace(/^\.\//, ''));
    for (const file of expected) {
        if (!files.has(file)) throw new Error(`${name}: package archive is missing declared export ${file}.`);
    }
    const styleExport = manifest.exports?.['./styles.css']?.import ?? manifest.exports?.['./style.css']?.import;
    if (styleExport && !files.has(styleExport.replace(/^\.\//, ''))) {
        throw new Error(`${name}: package archive is missing declared style export ${styleExport}.`);
    }
    if (!files.has('README.md')) throw new Error(`${name}: package archive does not include README.md.`);
    report.packages.push({
        name,
        version: artifact.version,
        access: manifest.publishConfig.access,
        registry: manifest.publishConfig.registry,
        file_count: artifact.files.length,
        unpacked_size: artifact.unpackedSize,
        integrity: artifact.integrity,
        status: 'PASS',
    });
    console.log(`PASS ${name}@${artifact.version}: ${artifact.files.length} files; public publish configuration verified.`);
}

await writeFile(reportPath, `${JSON.stringify(report, null, 2)}\n`, { flag: 'wx' });
console.log(`Dry-run report written to ${reportPath}. Nothing was published.`);

function topologicalOrder(items) {
    const ordered = [];
    const visiting = new Set();
    const visited = new Set();
    const visit = (name) => {
        if (visited.has(name)) return;
        if (visiting.has(name)) throw new Error(`npm workspace dependency cycle detected at ${name}.`);
        visiting.add(name);
        for (const dependency of Object.keys(items.get(name).manifest.dependencies ?? {})) {
            if (items.has(dependency)) visit(dependency);
        }
        visiting.delete(name);
        visited.add(name);
        ordered.push(name);
    };
    for (const name of items.keys()) visit(name);
    return ordered;
}

function parseReportPath(args) {
    const option = args.find((argument) => argument.startsWith('--report='));
    if (!option || args.length !== 1) throw new Error('Usage: node scripts/prepare-npm-release.mjs --report=<new-absolute-path-outside-project>.');
    return resolve(option.slice('--report='.length));
}

function run(command, args, cwd, inherit) {
    const useCmd = process.platform === 'win32' && command.endsWith('.cmd');
    const executable = useCmd ? (process.env.ComSpec ?? 'cmd.exe') : command;
    const invocation = useCmd ? ['/d', '/s', '/c', `${command} ${args.join(' ')}`] : args;
    const result = spawnSync(executable, invocation, {
        cwd,
        encoding: 'utf8',
        stdio: inherit ? 'inherit' : ['ignore', 'pipe', 'pipe'],
        windowsHide: true,
        shell: false,
    });
    if (result.error) throw new Error(`${command} could not start: ${result.error.message}`);
    if (result.status !== 0) {
        throw new Error(`${command} failed with exit ${result.status ?? 'unknown'}. Stop release preparation; no package was published. Child-process details were withheld.`);
    }
    return result;
}
