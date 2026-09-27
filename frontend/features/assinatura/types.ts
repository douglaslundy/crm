import type { SituacaoAssinatura } from '@/features/auth/types';

export const MODULOS = ['FISCAL_NFE', 'FISCAL_NFCE', 'FISCAL_NFSE', 'CRM', 'API'] as const;
export type Modulo = (typeof MODULOS)[number];

export const RECURSOS = ['USUARIOS', 'CLIENTES', 'PRODUTOS', 'SERVICOS', 'DOCUMENTOS_MES', 'API_REQUISICOES_MIN'] as const;
export type Recurso = (typeof RECURSOS)[number];

export const ILIMITADO = -1;

export interface Plano {
  id: string;
  nome: string;
  descricao: string | null;
  preco_mensal_centavos: number;
  preco_anual_centavos: number | null;
  dias_teste: number;
  politica_excedente: 'BLOQUEAR' | 'COBRAR';
  preco_documento_excedente_centavos: number | null;
  modulos: Modulo[];
  limites: Record<Recurso, number>;
  ativo: boolean;
  visivel: boolean;
  ordem: number;
  empresas?: number;
}

export interface ItemConsumo {
  recurso: Recurso;
  uso: number;
  limite: number;
}

export interface Assinatura {
  situacao: SituacaoAssinatura;
  teste_termina_em: string | null;
  plano: Plano | null;
  consumo: ItemConsumo[];
}

export const ROTULO_MODULO: Record<Modulo, string> = {
  FISCAL_NFE: 'NF-e', FISCAL_NFCE: 'NFC-e', FISCAL_NFSE: 'NFS-e', CRM: 'CRM', API: 'API pública',
};

export const ROTULO_RECURSO: Record<Recurso, string> = {
  USUARIOS: 'Usuários', CLIENTES: 'Clientes', PRODUTOS: 'Produtos', SERVICOS: 'Serviços',
  DOCUMENTOS_MES: 'Documentos por mês', API_REQUISICOES_MIN: 'Requisições de API por minuto',
};

export const ROTULO_SITUACAO: Record<SituacaoAssinatura, string> = {
  PENDENTE: 'Pendente', TESTE: 'Em teste', ATIVA: 'Ativa', INADIMPLENTE: 'Inadimplente', SUSPENSA: 'Suspensa', CANCELADA: 'Cancelada',
};
