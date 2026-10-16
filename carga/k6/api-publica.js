import http from 'k6/http';
import { check, sleep } from 'k6';
import { contas, API, erro5xx } from './lib/comum.js';

export const options = {
  scenarios: {
    normal: { executor: 'constant-vus', vus: 30, duration: '5m', exec: 'normal' },
    abusivo: { executor: 'constant-arrival-rate', rate: 15, timeUnit: '1s', duration: '5m', preAllocatedVUs: 20, exec: 'abusivo' },
  },
  thresholds: {
    erro_5xx: ['rate<0.01'],
    'http_req_duration{grupo:api-normal}': ['p(95)<800'],
    checks: ['rate>0.99'],
  },
};

const cab = (conta) => ({ Authorization: `Bearer ${conta.chave_api}`, Accept: 'application/json' });

export function normal() {
  // A conta 0 é a do cenário abusivo (estoura o limite de propósito): o tráfego normal usa as outras.
  const conta = contas[1 + ((__VU - 1) % (contas.length - 1))];
  for (const rota of ['/api/v1/empresa', '/api/v1/notas']) {
    const r = http.get(`${API}${rota}`, { headers: cab(conta), tags: { grupo: 'api-normal' } });
    erro5xx.add(r.status >= 500);
    check(r, { 'api normal 200': (x) => x.status === 200 });
  }
  // 2 pedidos a cada ~1 s por chave = ~120/min: abaixo dos 300/min do plano de carga.
  sleep(1);
}

export function abusivo() {
  // Sempre a mesma chave: 15 req/s = 900/min, muito acima do limite.
  const r = http.get(`${API}/api/v1/empresa`, { headers: cab(contas[0]), tags: { grupo: 'api-abusivo' } });
  erro5xx.add(r.status >= 500);
  check(r, { 'abusivo: só 200 ou 429': (x) => x.status === 200 || x.status === 429 });
  if (r.status === 429) check(r, { '429 traz Retry-After': (x) => x.headers['Retry-After'] !== undefined });
}
