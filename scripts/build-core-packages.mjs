import { copyFile, access, readFile, writeFile } from 'node:fs/promises';
import { readFileSync } from 'node:fs';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { dirname, join, resolve } from 'node:path';
import vue from '@vitejs/plugin-vue';
import { build } from 'vite';

const scriptDirectory = dirname(fileURLToPath(import.meta.url));
const projectRoot = resolve(scriptDirectory, '..');
const rootManifest = JSON.parse(await readFile(join(projectRoot, 'package.json'), 'utf8'));
const packageDirectories = new Map();
for (const workspace of rootManifest.workspaces ?? []) {
    const packageRoot = resolve(projectRoot, workspace);
    const manifest = JSON.parse(await readFile(join(packageRoot, 'package.json'), 'utf8'));
    packageDirectories.set(manifest.name, packageRoot);
}
const packageOrder = [...packageDirectories.keys()];
const requestedPackages = process.argv.slice(2);
if (requestedPackages.some((name) => !packageDirectories.has(name))) {
    throw new Error('Unknown Core npm package requested for build.');
}

// Build local declaration dependencies before a package so it can be packed from a clean checkout.
const buildSet = new Set();
const visiting = new Set();
const buildOrder = [];
const includeDependencies = (packageName) => {
    if (buildSet.has(packageName)) return;
    if (visiting.has(packageName)) throw new Error(`Workspace dependency cycle detected at ${packageName}.`);
    visiting.add(packageName);
    const manifestPath = join(packageDirectories.get(packageName), 'package.json');
    const manifest = JSON.parse(readFileSync(manifestPath, 'utf8'));
    for (const dependency of Object.keys(manifest.dependencies ?? {})) {
        if (packageDirectories.has(dependency)) includeDependencies(dependency);
    }
    visiting.delete(packageName);
    buildSet.add(packageName);
    buildOrder.push(packageName);
};
for (const packageName of requestedPackages.length > 0 ? requestedPackages : packageOrder) {
    includeDependencies(packageName);
}
const targets = buildOrder;
const packageNames = new Set(packageOrder);

const declarationCompiler = join(projectRoot, 'node_modules/vue-tsc/bin/vue-tsc.js');
const external = (id) => id === 'vue'
    || id === 'vue-router'
    || id === 'axios'
    || packageNames.has(id);

for (const packageName of targets) {
    const packageRoot = packageDirectories.get(packageName);
    const manifest = JSON.parse(readFileSync(join(packageRoot, 'package.json'), 'utf8'));
    const isUi = manifest.exports?.['./styles.css'] !== undefined;
    const sourceRoot = isUi ? 'src' : 'resources/js';
    const entry = join(packageRoot, sourceRoot, 'index.ts');
    const outputDirectory = join(packageRoot, 'dist');

    // Build each package independently so its npm artifact includes only its public entry and dependencies.
    await build({
        configFile: false,
        root: packageRoot,
        plugins: [vue()],
        build: {
            lib: {
                entry,
                formats: ['es'],
                fileName: 'index',
            },
            outDir: outputDirectory,
            // Preserve pre-existing generated artifacts; each build overwrites only its own public outputs.
            emptyOutDir: false,
            rollupOptions: { external },
        },
    });

    if (isUi) {
        await copyFile(
            join(packageRoot, 'src/styles/forms.css'),
            join(outputDirectory, 'styles.css'),
        );
    } else {
        // Every published package has a stable stylesheet export, even when all styles live in SFCs.
        try {
            await access(join(outputDirectory, 'index.css'));
        } catch {
            await writeFile(
                join(outputDirectory, 'index.css'),
                '/* This package currently has no extracted stylesheet rules. */\n',
            );
        }
    }

    const compiler = spawnSync(process.execPath, [
        declarationCompiler,
        '--project',
        join(packageRoot, 'tsconfig.build.json'),
    ], { cwd: projectRoot, stdio: 'inherit' });

    if (compiler.error) {
        throw compiler.error;
    }
    if (compiler.status !== 0) {
        process.exitCode = compiler.status ?? 1;
        break;
    }

    console.log(`Built ${packageName} to ${outputDirectory}`);
}
