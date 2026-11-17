import { spawnSync } from 'node:child_process';
import { readFile } from 'node:fs/promises';
import { resolve, join } from 'node:path';
import process from 'node:process';

export async function resolvePhpRuntime(root) {
    const configPath = join(root, '.php-runtime.local.json');
    let config = {};
    if (process.platform === 'win32') {
        try {
            config = JSON.parse(await readFile(configPath, 'utf8'));
        } catch {
            if (!process.env.PHP_BINARY) {
                throw new Error(`Project PHP runtime is not configured. Create ${configPath} with the PHP 8.4+ executable path (see docs/development/local-development.md).`);
            }
        }
    }

    let php = process.env.PHP_BINARY || config.php;
    const extensions = config.extensions ?? [];
    if (!Array.isArray(extensions) || extensions.some((name) => typeof name !== 'string' || !/^[a-z0-9_-]+$/i.test(name))) {
        throw new Error(`Invalid extensions list in ${configPath}; use PHP extension names such as "zip".`);
    }
    const phpArguments = extensions.flatMap((name) => ['-d', `extension=${name}`]);
    php ??= 'php';

    const result = spawnSync(php, [...phpArguments, '-r', 'echo PHP_VERSION;'], { encoding: 'utf8', windowsHide: true });
    const version = result.stdout?.trim() ?? '';
    if (result.error || result.status !== 0 || !/^8\.(?:4|[5-9]|[1-9]\d)\./.test(version)) {
        const found = result.error?.message ?? (version || `exit ${result.status}`);
        throw new Error(`Laravel Master Core requires PHP 8.4+. Selected executable '${php}' reports '${found}'. Update .php-runtime.local.json or PHP_BINARY.`);
    }

    if (extensions.includes('zip')) {
        const zipCheck = spawnSync(php, [...phpArguments, '-r', 'exit(extension_loaded(chr(122).chr(105).chr(112)) ? 0 : 1);'], { encoding: 'utf8', windowsHide: true });
        if (zipCheck.error || zipCheck.status !== 0) {
            throw new Error(`The project runtime requested PHP's zip extension, but it could not be loaded by '${php}'. Check the matching PHP 8.4 extension files.`);
        }
    }

    return { executable: resolve(php), version, arguments: phpArguments };
}
