import type { ItemConsumo, Modulo, Recurso } from '@/features/assinatura/types';
import type { SituacaoAssinatura } from '@/features/auth/types';

export const SITUACOES = ['PENDENTE', 'TESTE', 'ATIVA', 'INADIMPLENTE', 'SUSPENSA', 'CANCELADA'] as const satisfies readonly SituacaoAssinatura[];

export interface Metricas {
  por_situacao: Record<SituacaoAssinatura, number>;
  novas_30_dias: number;
  mrr_centavos: number;
}

export interface Empresa {
  id: string;
  cnpj: string;
  razao_social: string;
  nome_fantasia: string | null;
  situacao: SituacaoAssinatura;
  teste_termina_em: string | null;
  situacao_alterada_em: string | null;
  plano: { id: string; nome: string; preco_mensal_centavos: number } | null;
  criada_em: string;
  consumo?: ItemConsumo[];
}

export interface PaginaCursor<T> {
  data: T[];
  meta: { next_cursor: string | null; prev_cursor: string | null; per_page: number };
}

export interface PlanoEntrada {
  nome: string;
  descricao: string | null;
  preco_mensal_centavos: number;
  preco_anual_centavos: number | null;
  dias_teste: number;
  politica_excedente: 'BLOQUEAR' | 'COBRAR';
  preco_documento_excedente_centavos: number | null;
  ativo: boolean;
  visivel: boolean;
  ordem: number;
  modulos: Modulo[];
  limites: Record<Recurso, number>;
}

export interface FiltrosEmpresas {
  situacao: SituacaoAssinatura | '';
  plano_id: string;
  busca: string;
}

export interface Excesso {
  recurso: Recurso;
  uso: number;
  limite: number;
}
