import { sleep, check } from 'k6';
import http from 'k6/http';
import { contaDoVu, entrar, escrever, limiaresPadrao, erro5xx, API } from './lib/comum.js';

export const options = {
  noCookiesReset: true, // a sessão (login feito uma vez por VU) tem que sobreviver entre iterações
  scenarios: {
    escrita: {
      executor: 'ramping-vus',
      startVUs: 0,
      stages: [{ duration: '2m', target: 50 }, { duration: '10m', target: 50 }, { duration: '30s', target: 0 }],
    },
  },
  thresholds: limiaresPadrao({ escrita: 1500, publica: 1500 }),
};

let logado = false;

export default function () {
  const conta = contaDoVu();
  if (!logado) {
    entrar(conta, 0); // sempre o admin: o vendedor só enxerga os negócios dele
    logado = true;
  }
  const marca = `${__VU}-${__ITER}`;
  const passo = (r, nome) => {
    erro5xx.add(r.status >= 500);
    return check(r, { [nome]: (x) => x.status >= 200 && x.status < 300 });
  };

  const criado = escrever('POST', '/api/app/crm/negocios', {
    titulo: `Carga ${marca}`, funil_id: conta.funil_id, etapa_id: conta.etapa_ids[0], cliente_id: conta.cliente_ids[__ITER % 10],
    itens: [{ tipo: 'PRODUTO', item_id: conta.produto_id, quantidade: 1 }],
  }, 'escrita');
  if (!passo(criado, 'criar negócio')) return;
  const id = criado.json('data.id');

  passo(escrever('POST', `/api/app/crm/negocios/${id}/mover`, { etapa_id: conta.etapa_ids[1], posicao: 0 }, 'escrita'), 'mover negócio');
  passo(escrever('POST', '/api/app/crm/atividades', {
    tipo: 'TAREFA', titulo: `Ligar ${marca}`, agendada_para: new Date(Date.now() + 86400000).toISOString(), negocio_id: id,
  }, 'escrita'), 'criar atividade');

  const proposta = escrever('POST', `/api/app/crm/negocios/${id}/propostas`, { validade_dias: 15 }, 'escrita');
  if (!passo(proposta, 'criar proposta')) return;
  const enviada = escrever('POST', `/api/app/crm/propostas/${proposta.json('data.id')}/enviar`, undefined, 'escrita');
  if (!passo(enviada, 'enviar proposta')) return;

  // O link vem na resposta de `enviar` (o apresentador só traz `link` quando pedido); o token é o último trecho.
  const link = enviada.json('data.link');
  if (!check(enviada, { 'enviar devolve o link': () => typeof link === 'string' && link.includes('/p/') })) return;
  const token = link.split('/p/')[1];
  const aceite = http.post(`${API}/api/public/propostas/${token}/aceitar`, JSON.stringify({ nome: `Cliente ${marca}` }), {
    headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
    tags: { grupo: 'publica' },
  });
  erro5xx.add(aceite.status >= 500);
  // 429 é o throttle de 60/min por IP da rota pública: o k6 sai de um IP só, então é esperado.
  check(aceite, { 'aceite público 2xx ou 429': (x) => (x.status >= 200 && x.status < 300) || x.status === 429 });
  sleep(2 + Math.random() * 3);
}
