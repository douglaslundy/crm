import { api } from '@/lib/api';
import type { PropostaPublica } from './types';

const BASE = '/api/public/propostas';
const API_URL = process.env.NEXT_PUBLIC_API_URL ?? 'http://localhost:8000';

/** Cliente fino da API pública da proposta: sem sessão, o token do link identifica tudo. */
export const propostaPublicaApi = {
  ler: (token: string) => api<{ data: PropostaPublica }>(`${BASE}/${token}`),
  aceitar: (token: string, nome: string) =>
    api<{ data: PropostaPublica }>(`${BASE}/${token}/aceitar`, { method: 'POST', body: JSON.stringify({ nome }) }),
  recusar: (token: string, motivo: string) =>
    api<{ data: PropostaPublica }>(`${BASE}/${token}/recusar`, {
      method: 'POST',
      body: JSON.stringify({ motivo: motivo === '' ? null : motivo }),
    }),
  urlDoPdf: (token: string) => `${API_URL}${BASE}/${token}/pdf`,
};
