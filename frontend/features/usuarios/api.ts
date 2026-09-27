import { api } from '@/lib/api';
import type { ConviteDados, EdicaoDados } from './schemas';
import type { UsuarioDaEmpresa } from './types';

export const QUERY_KEY_USUARIOS = ['usuarios'] as const;

type Resposta = { data: UsuarioDaEmpresa };

export const usuariosApi = {
  listar: () => api<{ data: UsuarioDaEmpresa[] }>('/api/app/usuarios'),
  convidar: (dados: ConviteDados) => api<Resposta>('/api/app/usuarios', { method: 'POST', body: JSON.stringify(dados) }),
  editar: (id: string, dados: EdicaoDados) => api<Resposta>(`/api/app/usuarios/${id}`, { method: 'PUT', body: JSON.stringify(dados) }),
  desativar: (id: string) => api<Resposta>(`/api/app/usuarios/${id}/desativar`, { method: 'POST' }),
  reativar: (id: string) => api<Resposta>(`/api/app/usuarios/${id}/reativar`, { method: 'POST' }),
};
