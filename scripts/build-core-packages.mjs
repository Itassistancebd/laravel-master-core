import { copyFile, access, writeFile } from 'node:fs/promises';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { dirname, join, resolve } from 'node:path';
import vue from '@vitejs/plugin-vue';
import { build } from 'vite';

const scriptDirectory = dirname(fileURLToPath(import.meta.url));
const projectRoot = resolve(scriptDirectory, '..');
const packageOrder = [
    '@itassistance-bd/ui',
    '@itassistance-bd/auth',
    '@itassistance-bd/settings-ui',
    '@itassistance-bd/user-management',
    '@itassistance-bd/roles-permissions',
    '@itassistance-bd/audit-logs',
    '@itassistance-bd/module-manager',
    '@itassistance-bd/system-health',
    '@itassistance-bd/notifications',
    '@itassistance-bd/crud-engine',
];
const packageDirectories = {
    '@itassistance-bd/ui': 'ui',
    '@itassistance-bd/auth': 'auth',
    '@itassistance-bd/settings-ui': 'settings',
    '@itassistance-bd/user-management': 'user-management',
    '@itassistance-bd/roles-permissions': 'roles-permissions',
    '@itassistance-bd/audit-logs': 'audit-logs',
    '@itassistance-bd/module-manager': 'module-manager',
    '@itassistance-bd/system-health': 'system-health',
    '@itassistance-bd/notifications': 'notifications',
    '@itassistance-bd/crud-engine': 'crud-engine',
};
const packageDependencies = {
    '@itassistance-bd/ui': [],
    '@itassistance-bd/settings-ui': ['@itassistance-bd/ui'],
    '@itassistance-bd/auth': ['@itassistance-bd/ui'],
    '@itassistance-bd/user-management': ['@itassistance-bd/ui', '@itassistance-bd/auth'],
    '@itassistance-bd/roles-permissions': ['@itassistance-bd/ui', '@itassistance-bd/auth'],
    '@itassistance-bd/audit-logs': ['@itassistance-bd/ui'],
    '@itassistance-bd/module-manager': ['@itassistance-bd/ui', '@itassistance-bd/auth'],
    '@itassistance-bd/system-health': ['@itassistance-bd/ui', '@itassistance-bd/auth'],
    '@itassistance-bd/notifications': ['@itassistance-bd/ui'],
    '@itassistance-bd/crud-engine': ['@itassistance-bd/ui'],
};

const requestedPackages = process.argv.slice(2);
if (requestedPackages.some((name) => !packageOrder.includes(name))) {
    throw new Error('Unknown Core npm package requested for build.');
}

// Build local declaration dependencies before a package so it can be packed from a clean checkout.
const buildSet = new Set();
const includeDependencies = (packageName) => {
    for (const dependency of packageDependencies[packageName]) {
        includeDependencies(dependency);
    }
    buildSet.add(packageName);
};
for (const packageName of requestedPackages.length > 0 ? requestedPackages : packageOrder) {
    includeDependencies(packageName);
}
const targets = packageOrder.filter((name) => buildSet.has(name));

const declarationCompiler = join(projectRoot, 'node_modules/vue-tsc/bin/vue-tsc.js');
const external = (id) => id === 'vue'
    || id === 'vue-router'
    || id === 'axios'
    || id.startsWith('@itassistance-bd/');

for (const packageName of targets) {
    const packageRoot = join(projectRoot, 'packages/core', packageDirectories[packageName]);
    const isUi = packageName === '@itassistance-bd/ui';
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
