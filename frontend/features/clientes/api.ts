import { api } from '@/lib/api';
import type { ClienteFormDados, ContatoFormDados } from './schemas';
import type { Cliente, ClientePayload, Contato } from './types';

export const QUERY_KEY_CLIENTES = ['clientes'] as const;

export function paraPayload(d: ClienteFormDados): ClientePayload {
  return {
    tipo: d.tipo,
    nome: d.nome,
    cpf_cnpj: d.cpf_cnpj || null,
    inscricao_estadual: d.inscricao_estadual || null,
    ie_isento: d.ie_isento,
    email: d.email || null,
    telefone: d.telefone || null,
    logradouro: d.logradouro || null,
    numero: d.numero || null,
    bairro: d.bairro || null,
    cidade: d.cidade || null,
    uf: d.uf ? d.uf.toUpperCase() : null,
    cep: d.cep || null,
    codigo_ibge: null,
    origem: d.origem || null,
  };
}

export const clientesApi = {
  listar: () => api<{ data: Cliente[] }>('/api/app/clientes'),
  criar: (dados: ClientePayload) => api<{ data: Cliente }>('/api/app/clientes', { method: 'POST', body: JSON.stringify(dados) }),
  editar: (id: string, dados: ClientePayload) => api<{ data: Cliente }>(`/api/app/clientes/${id}`, { method: 'PUT', body: JSON.stringify(dados) }),
  converterEmCliente: (id: string) => api<{ data: Cliente }>(`/api/app/clientes/${id}/converter-em-cliente`, { method: 'POST' }),
};

export const contatosApi = {
  criar: (clienteId: string, dados: ContatoFormDados) =>
    api<{ data: Contato }>(`/api/app/clientes/${clienteId}/contatos`, { method: 'POST', body: JSON.stringify(dados) }),
  editar: (clienteId: string, contatoId: string, dados: ContatoFormDados) =>
    api<{ data: Contato }>(`/api/app/clientes/${clienteId}/contatos/${contatoId}`, { method: 'PUT', body: JSON.stringify(dados) }),
};

/** Mesmo endpoint do wizard do emitente (Tarefa 15): é só uma consulta de CEP, sem relação com dado do emitente. */
export const consultarCep = (cep: string) =>
  api<{ data: { logradouro: string; bairro: string; cidade: string; uf: string; codigo_ibge: string } }>(`/api/app/emitente/cep/${cep}`);
