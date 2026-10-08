// Copia o contrato (fonte: docs/api/openapi.yaml) para public/, de onde a página /docs/api o serve.
import { copyFileSync, existsSync, mkdirSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const raiz = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const origem = resolve(raiz, '..', 'docs', 'api', 'openapi.yaml');
const destino = resolve(raiz, 'public', 'openapi.yaml');

if (!existsSync(origem)) {
  console.error(`Contrato não encontrado em ${origem}.`);
  process.exit(1);
}
mkdirSync(dirname(destino), { recursive: true });
copyFileSync(origem, destino);
console.log('openapi.yaml copiado para public/.');
