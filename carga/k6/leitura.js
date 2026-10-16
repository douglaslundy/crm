import { sleep, check } from 'k6';
import { contaDoVu, entrar, ler, semVazamento, limiaresPadrao, erro5xx } from './lib/comum.js';

export const options = {
  noCookiesReset: true, // a sessão (login feito uma vez por VU) tem que sobreviver entre iterações
  scenarios: {
    leitura: {
      executor: 'ramping-vus',
      startVUs: 0,
      stages: [{ duration: '2m', target: 100 }, { duration: '10m', target: 100 }, { duration: '30s', target: 0 }],
    },
  },
  thresholds: limiaresPadrao({ leitura: 800 }),
};

let logado = false;
const ROTAS = [
  '/api/app/auth/me', '/api/app/dashboard/fiscal', '/api/app/clientes', '/api/app/notas',
  '/api/app/crm/funis', '/api/app/crm/negocios', '/api/app/crm/resumo', '/api/app/crm/agenda',
];

export default function () {
  const conta = contaDoVu();
  if (!logado) {
    entrar(conta, __VU % 2);
    logado = true;
  }
  for (const rota of ROTAS) {
    const r = ler(rota, 'leitura');
    erro5xx.add(r.status >= 500);
    check(r, { [`${rota} 200`]: (x) => x.status === 200 });
  }
  const clientes = ler('/api/app/clientes', 'leitura');
  erro5xx.add(clientes.status >= 500);
  check(clientes, { 'isolamento: clientes só da própria empresa': (x) => x.status !== 200 || semVazamento(x, conta) });
  sleep(1 + Math.random() * 2);
}
