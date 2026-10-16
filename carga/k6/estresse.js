import { sleep } from 'k6';
import { contaDoVu, entrar, ler, erro5xx } from './lib/comum.js';

export const options = {
  noCookiesReset: true, // a sessão (login feito uma vez por VU) tem que sobreviver entre iterações
  scenarios: {
    estresse: {
      executor: 'ramping-vus',
      startVUs: 0,
      stages: [
        { duration: '2m', target: 100 }, { duration: '3m', target: 100 },
        { duration: '2m', target: 200 }, { duration: '3m', target: 200 },
        { duration: '2m', target: 300 }, { duration: '3m', target: 300 },
        { duration: '1m', target: 0 },
      ],
    },
  },
  // Teto de segurança (VPS compartilhada): aborta cedo, sem esperar o fim da rampa.
  thresholds: {
    'http_req_duration{grupo:leitura}': [{ threshold: 'p(95)<5000', abortOnFail: true, delayAbortEval: '30s' }],
    erro_5xx: [{ threshold: 'rate<0.10', abortOnFail: true, delayAbortEval: '30s' }],
  },
};

let logado = false;
const ROTAS = ['/api/app/auth/me', '/api/app/clientes', '/api/app/notas', '/api/app/crm/negocios', '/api/app/crm/resumo'];

export default function () {
  const conta = contaDoVu();
  if (!logado) {
    entrar(conta, __VU % 2);
    logado = true;
  }
  for (const rota of ROTAS) {
    const r = ler(rota, 'leitura');
    erro5xx.add(r.status >= 500);
  }
  sleep(0.5 + Math.random());
}
