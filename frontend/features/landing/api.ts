import { api } from '@/lib/api';

export interface Desafio {
  pergunta: string;
  token: string;
}

export interface ContatoEntrada {
  nome: string;
  email: string;
  mensagem: string;
  desafio_token: string;
  desafio_resposta: string;
  /** Campo armadilha: tem que ir vazio. */
  site: string;
}

export const contatoApi = {
  desafio: () => api<{ data: Desafio }>('/api/publico/contato/desafio'),
  enviar: (dados: ContatoEntrada) =>
    api<{ data: { recebido: boolean } }>('/api/publico/contato', { method: 'POST', body: JSON.stringify(dados) }),
};
