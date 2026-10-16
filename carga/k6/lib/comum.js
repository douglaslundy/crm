import http from 'k6/http';
import { check, fail } from 'k6';
import { Rate } from 'k6/metrics';

export const API = __ENV.API_URL || 'http://app:8080';
// O Sanctum só trata como SPA (sessão) quem manda Origin/Referer de um domínio de SANCTUM_STATEFUL_DOMAINS.
const ORIGEM = __ENV.ORIGEM || 'http://192.168.0.233:3200';

export const erro5xx = new Rate('erro_5xx');

const dados = JSON.parse(open('/dados/contas.json'));
export const senha = dados.senha;
export const contas = dados.empresas;

// id (cliente, negócio) → índice da empresa dona: base do teste de isolamento.
const dono = new Map();
for (const c of contas) {
  for (const id of [...c.cliente_ids, ...c.negocio_ids]) dono.set(id, c.indice);
}
export function donoDe(id) {
  return dono.get(id);
}

export function contaDoVu() {
  return contas[(__VU - 1) % contas.length];
}

function cabecalhos(extra = {}) {
  return { Accept: 'application/json', Origin: ORIGEM, Referer: `${ORIGEM}/`, ...extra };
}

function xsrf() {
  const lista = http.cookieJar().cookiesForURL(API)['XSRF-TOKEN'];
  return lista && lista.length ? decodeURIComponent(lista[0]) : '';
}

/** Login por sessão (cookie), como o frontend faz. Chamar uma vez por VU. */
export function entrar(conta, indiceDoUsuario = 0) {
  http.get(`${API}/sanctum/csrf-cookie`, { headers: cabecalhos(), tags: { grupo: 'login' } });
  const r = http.post(
    `${API}/api/app/auth/login`,
    JSON.stringify({ email: conta.usuarios[indiceDoUsuario].email, password: senha }),
    { headers: cabecalhos({ 'Content-Type': 'application/json', 'X-XSRF-TOKEN': xsrf() }), tags: { grupo: 'login' } },
  );
  if (!check(r, { 'login 2xx': (x) => x.status >= 200 && x.status < 300 })) fail(`login falhou: ${r.status}`);
}

export function ler(caminho, grupo) {
  return http.get(`${API}${caminho}`, { headers: cabecalhos(), tags: { grupo } });
}

export function escrever(metodo, caminho, corpo, grupo) {
  return http.request(metodo, `${API}${caminho}`, corpo === undefined ? null : JSON.stringify(corpo), {
    headers: cabecalhos({ 'Content-Type': 'application/json', 'X-XSRF-TOKEN': xsrf() }),
    tags: { grupo },
  });
}

/** Nenhum id da resposta pertence a outra empresa semeada. */
export function semVazamento(resposta, conta) {
  const itens = (resposta.json('data') || []).map((x) => x.id);
  return itens.every((id) => donoDe(id) === undefined || donoDe(id) === conta.indice);
}

/** Metas da spec: erros 5xx < 1%, checks > 99% e p95 por grupo de pedidos (em ms). */
export function limiaresPadrao(grupos) {
  const t = { erro_5xx: ['rate<0.01'], checks: ['rate>0.99'] };
  for (const [grupo, p95] of Object.entries(grupos)) t[`http_req_duration{grupo:${grupo}}`] = [`p(95)<${p95}`];
  return t;
}
