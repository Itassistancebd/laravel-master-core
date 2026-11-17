import { spawnSync } from 'node:child_process';
import { mkdir, readFile, writeFile } from 'node:fs/promises';
import { dirname, isAbsolute, join, relative, resolve, sep } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const outputArg = process.argv.slice(2).find((arg) => arg.startsWith('--output='));
if (!outputArg || process.argv.length !== 3) {
    throw new Error('Usage: node scripts/validate-npm-local-consumer.mjs --output=<new-absolute-path-outside-project>.');
}
const output = resolve(outputArg.slice('--output='.length));
const relativeOutput = relative(root, output);
if (!isAbsolute(output) || relativeOutput === '' || (!relativeOutput.startsWith(`..${sep}`) && relativeOutput !== '..')) {
    throw new Error('Consumer output must be a new path outside the source project.');
}
await mkdir(output, { recursive: false });
const tarballs = join(output, 'tarballs');
const consumer = join(output, 'consumer');
await mkdir(tarballs);
await mkdir(consumer);

const rootManifest = JSON.parse(await readFile(join(root, 'package.json'), 'utf8'));
const packages = [];
for (const directory of rootManifest.workspaces ?? []) {
    const packageRoot = resolve(root, directory);
    const manifest = JSON.parse(await readFile(join(packageRoot, 'package.json'), 'utf8'));
    const result = run('npm.cmd', ['pack', '--json', '--ignore-scripts', `--pack-destination=${tarballs}`, '--registry=https://registry.npmjs.org/'], packageRoot);
    let packed;
    try {
        packed = JSON.parse(result.stdout)[0];
    } catch {
        throw new Error(`Could not parse local tarball metadata for ${manifest.name}.`);
    }
    const packageTarball = join(tarballs, packed.filename);
    packages.push({ name: manifest.name, version: manifest.version, filename: packed.filename, path: packageTarball });
}
if (packages.length !== 10) throw new Error(`Expected ten local npm package tarballs; produced ${packages.length}.`);

const dependencies = Object.fromEntries(packages.map((item) => [item.name, `file:../tarballs/${item.filename}`]));
await writeFile(join(consumer, 'package.json'), `${JSON.stringify({ name: 'master-core-local-consumer-check', private: true, type: 'module', dependencies }, null, 2)}\n`, { flag: 'wx' });
run('npm.cmd', ['install', '--ignore-scripts', '--no-audit', '--no-fund', '--registry=https://registry.npmjs.org/'], consumer);

const packageNames = JSON.stringify(packages.map(({ name }) => name));
const importCheck = `const names=${packageNames}; for (const name of names) { await import(name); } console.log('Imported '+names.length+' local package tarballs successfully.');`;
run(process.execPath, ['--input-type=module', '-e', importCheck], consumer);

const report = {
    status: 'PASS',
    installation: 'clean temporary consumer; local npm tarballs; no workspace links',
    registry: 'https://registry.npmjs.org/ for third-party dependencies only',
    lifecycle_scripts: 'disabled',
    consumer,
    tarball_directory: tarballs,
    package_count: packages.length,
    packages: packages.map(({ name, version, filename }) => ({ name, version, filename, status: 'PASS' })),
};
await writeFile(join(output, 'consumer-validation.json'), `${JSON.stringify(report, null, 2)}\n`, { flag: 'wx' });
console.log(`PASS: installed and imported ${packages.length} local package tarballs in ${consumer}. Workspace links were not used.`);
console.log(`Report: ${join(output, 'consumer-validation.json')}. This is not a public registry installation.`);

function run(command, args, cwd) {
    const useCmd = process.platform === 'win32' && command.endsWith('.cmd');
    const executable = useCmd ? (process.env.ComSpec ?? 'cmd.exe') : command;
    const invocation = useCmd ? ['/d', '/s', '/c', `${command} ${args.join(' ')}`] : args;
    const result = spawnSync(executable, invocation, { cwd, encoding: 'utf8', windowsHide: true, shell: false });
    if (result.error) throw new Error(`${command} could not start: ${result.error.message}`);
    if (result.status !== 0) throw new Error(`${command} failed with exit ${result.status ?? 'unknown'}; local consumer validation stopped. Details were withheld.`);
    if (command === process.execPath) process.stdout.write(result.stdout);
    return result;
}
