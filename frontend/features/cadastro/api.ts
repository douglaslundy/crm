import type { Plano } from '@/features/assinatura/types';
import type { Usuario } from '@/features/auth/types';
import { api } from '@/lib/api';
import type { CadastroDados } from './schemas';

export interface DadosCnpj {
  razao_social: string;
  nome_fantasia: string | null;
}

export type CadastroEntrada = Omit<CadastroDados, 'nome_fantasia'> & { nome_fantasia: string | null; plano_id: string };

export const cadastroApi = {
  planos: () => api<{ data: Plano[] }>('/api/publico/planos'),
  consultarCnpj: (cnpj: string) => api<{ data: DadosCnpj }>(`/api/publico/cnpj/${cnpj}`),
  cadastrar: (dados: CadastroEntrada) =>
    api<{ data: Usuario }>('/api/publico/cadastro', { method: 'POST', body: JSON.stringify(dados) }),
};
