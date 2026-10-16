import http from 'k6/http';
import { check, sleep } from 'k6';
import { Counter, Trend } from 'k6/metrics';
import { contas, API, erro5xx } from './lib/comum.js';

const emitidas = new Counter('notas_emitidas');
const autorizadas = new Counter('notas_autorizadas');
const ateAutorizar = new Trend('tempo_ate_autorizar_ms', true);

export const options = {
  scenarios: {
    emissao: { executor: 'constant-arrival-rate', rate: 20, timeUnit: '1s', duration: '10m', preAllocatedVUs: 50, maxVUs: 200 },
  },
  thresholds: {
    erro_5xx: ['rate<0.01'],
    checks: ['rate>0.99'],
    // A fila drena: 95% das notas acompanhadas autorizam em até 60 s.
    tempo_ate_autorizar_ms: ['p(95)<60000'],
  },
};

export default function () {
  const conta = contas[(__VU + __ITER) % contas.length];
  const cab = {
    Authorization: `Bearer ${conta.chave_api}`, Accept: 'application/json', 'Content-Type': 'application/json',
    'Idempotency-Key': `carga-${__VU}-${__ITER}-${Date.now()}`,
  };
  const r = http.post(`${API}/api/v1/notas`, JSON.stringify({
    modelo: 'NFE', cliente: { id: conta.cliente_ids[__ITER % 10] }, desconto_centavos: 0,
    itens: [{ produto_id: conta.produto_id, quantidade: '1' }], pagamentos: [{ tpag: '01', valor_centavos: 1000 }],
  }), { headers: cab, tags: { grupo: 'emissao' } });
  erro5xx.add(r.status >= 500);
  // 429 = limite do plano de carga (300 req/min por chave): tolerado, não é falha da fila.
  if (!check(r, { 'emitir 202 ou 429': (x) => x.status === 202 || x.status === 429 }) || r.status !== 202) return;
  emitidas.add(1);

  // Amostra: 1 em cada 10 notas é acompanhada até autorizar.
  if (__ITER % 10 !== 0) return;
  const id = r.json('data.id');
  const inicio = Date.now();
  for (let t = 0; t < 30; t++) {
    sleep(2);
    const s = http.get(`${API}/api/v1/notas/${id}/status`, { headers: cab, tags: { grupo: 'emissao-status' } });
    erro5xx.add(s.status >= 500);
    if (s.status === 200 && s.json('data.status') === 'AUTORIZADA') {
      autorizadas.add(1);
      ateAutorizar.add(Date.now() - inicio);
      return;
    }
  }
}
