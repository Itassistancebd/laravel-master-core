import { resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { resolvePhpRuntime } from './php-runtime.mjs';

const root = resolve(fileURLToPath(new URL('..', import.meta.url)));
const runtime = await resolvePhpRuntime(root);
process.stdout.write(`Project PHP runtime: ${runtime.executable} (PHP ${runtime.version})\n`);
