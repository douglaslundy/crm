import { api } from '@/lib/api';
import type { CscFormDados, DadosDaEmpresaFormDados, DadosFiscaisFormDados } from './schemas';
import type { Emitente, ModeloDocumento } from './types';

export const QUERY_KEY_EMITENTE = ['emitente'] as const;

export const emitenteApi = {
  buscar: () => api<{ data: Emitente }>('/api/app/emitente'),
  consultarCep: (cep: string) =>
    api<{ data: { logradouro: string; bairro: string; cidade: string; uf: string; codigo_ibge: string } }>(`/api/app/emitente/cep/${cep}`),
  atualizarEmpresa: (d: DadosDaEmpresaFormDados) =>
    api<{ data: Emitente }>('/api/app/emitente/empresa', {
      method: 'PUT',
      body: JSON.stringify({ cep: d.cep || null, logradouro: d.logradouro || null, numero: d.numero || null, bairro: d.bairro || null, cidade: d.cidade || null, uf: d.uf || null, codigo_ibge: d.codigo_ibge || null }),
    }),
  atualizarFiscal: (d: DadosFiscaisFormDados) =>
    api<{ data: Emitente }>('/api/app/emitente/fiscal', {
      method: 'PUT',
      body: JSON.stringify({ regime_tributario: d.regime_tributario || null, inscricao_estadual: d.inscricao_estadual || null, inscricao_municipal: d.inscricao_municipal || null, cnae: d.cnae || null }),
    }),
  uploadCertificado: (arquivo: File, senha: string) => {
    const corpo = new FormData();
    corpo.append('arquivo', arquivo);
    corpo.append('senha', senha);
    return api<{ data: Emitente }>('/api/app/emitente/certificado', { method: 'POST', body: corpo });
  },
  atualizarCsc: (d: CscFormDados) =>
    api<{ data: Emitente }>('/api/app/emitente/csc', {
      method: 'PUT',
      body: JSON.stringify({
        csc_id_homologacao: d.csc_id_homologacao || null, csc_token_homologacao: d.csc_token_homologacao || null,
        csc_id_producao: d.csc_id_producao || null, csc_token_producao: d.csc_token_producao || null,
      }),
    }),
  atualizarSerie: (modelo: ModeloDocumento, serie: string, proximoNumero: number) =>
    api<{ data: Emitente }>(`/api/app/emitente/series/${modelo}`, { method: 'PUT', body: JSON.stringify({ serie, proximo_numero: proximoNumero }) }),
  confirmarProducao: () => api<{ data: Emitente }>('/api/app/emitente/ambiente/producao', { method: 'POST', body: JSON.stringify({ confirmo: true }) }),
};
