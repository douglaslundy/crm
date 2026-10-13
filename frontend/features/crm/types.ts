export type StatusDoNegocio = 'ABERTO' | 'GANHO' | 'PERDIDO';
export type TipoDeItem = 'PRODUTO' | 'SERVICO';

export interface Etapa {
  id: string;
  funil_id: string;
  nome: string;
  posicao: number;
  probabilidade: number;
  ativa: boolean;
  dias_para_parado: number | null;
}

export interface Funil {
  id: string;
  nome: string;
  padrao: boolean;
  ativo: boolean;
  posicao: number;
  etapas: Etapa[];
}

export interface MotivoDePerda {
  id: string;
  nome: string;
  ativo: boolean;
}

export interface ConfiguracaoDoCrm {
  vendedor_ve_so_os_seus: boolean;
}

export interface ItemDoNegocio {
  id: string;
  tipo: TipoDeItem;
  item_id: string;
  descricao: string;
  quantidade: number;
  valor_unitario_centavos: number;
}

export interface ClienteDoNegocio {
  id: string;
  nome: string;
  telefone: string | null;
  email: string | null;
  estagio: 'LEAD' | 'CLIENTE';
}

export interface Negocio {
  id: string;
  titulo: string;
  cliente: ClienteDoNegocio | null;
  funil_id: string;
  etapa_id: string;
  responsavel: { id: string; nome: string } | null;
  status: StatusDoNegocio;
  valor_centavos: number;
  previsao_fechamento: string | null;
  posicao_na_etapa: number;
  motivo_de_perda_id: string | null;
  observacao_da_perda: string | null;
  fechado_em: string | null;
  parado: boolean;
  dias_parado: number | null;
  itens: ItemDoNegocio[];
}

export interface ItemEntrada {
  tipo: TipoDeItem;
  item_id: string;
  quantidade: number;
}

export interface NovoNegocioPayload {
  titulo: string;
  funil_id: string;
  etapa_id: string;
  cliente_id?: string;
  novo_lead?: { nome: string; telefone?: string; email?: string };
  previsao_fechamento?: string;
  valor_centavos?: number;
  itens?: ItemEntrada[];
}

export type TipoDeAtividade = 'LIGACAO' | 'REUNIAO' | 'EMAIL' | 'WHATSAPP' | 'TAREFA';

export interface Atividade {
  id: string;
  tipo: TipoDeAtividade;
  titulo: string;
  descricao: string | null;
  agendada_para: string;
  concluida_em: string | null;
  atrasada: boolean;
  cliente: { id: string; nome: string; telefone: string | null; email: string | null } | null;
  negocio: { id: string; titulo: string } | null;
  responsavel: { id: string; nome: string } | null;
}

export type TipoDeRegistro =
  | 'NOTA' | 'ATIVIDADE_CONCLUIDA' | 'NEGOCIO_CRIADO' | 'NEGOCIO_MOVIDO' | 'NEGOCIO_GANHO' | 'NEGOCIO_PERDIDO'
  | 'PROPOSTA_ENVIADA' | 'PROPOSTA_ACEITA' | 'PROPOSTA_RECUSADA' | 'PROPOSTA_NOTA_GERADA';

export interface RegistroDaTimeline {
  id: string;
  tipo: TipoDeRegistro;
  texto: string;
  dados: Record<string, unknown>;
  autor: { id: string; nome: string } | null;
  negocio_id: string | null;
  atividade_id: string | null;
  ocorreu_em: string;
}

export interface ResumoDoCrm {
  hoje: number;
  atrasadas: number;
  parados: number;
}

export interface NovaAtividadePayload {
  tipo: TipoDeAtividade;
  titulo: string;
  descricao?: string;
  agendada_para: string;
  cliente_id?: string;
  negocio_id?: string;
  responsavel_id?: string;
}

export type StatusDaProposta = 'RASCUNHO' | 'ENVIADA' | 'ACEITA' | 'RECUSADA' | 'CANCELADA' | 'EXPIRADA';

export interface ItemDaProposta {
  tipo: TipoDeItem;
  descricao: string;
  quantidade: number;
  valor_unitario_centavos: number;
  total_centavos: number;
}

export interface Proposta {
  id: string;
  numero: number;
  status: StatusDaProposta;
  negocio_id: string;
  cliente_id: string;
  validade_dias: number;
  validade_em: string | null;
  desconto_centavos: number;
  subtotal_centavos: number;
  total_centavos: number;
  observacoes: string | null;
  enviada_em: string | null;
  respondida_em: string | null;
  aceite_nome: string | null;
  motivo_recusa: string | null;
  notas_ids: string[];
  itens: ItemDaProposta[];
  /** Só vem para o vendedor, e só depois de enviada. */
  link?: string | null;
}

export interface NovaPropostaPayload {
  validade_dias?: number;
  desconto_centavos?: number;
  observacoes?: string | null;
}

/** Visão do cliente no link público: nenhum id interno. */
export interface PropostaPublica {
  numero: number;
  status: StatusDaProposta;
  empresa: { nome: string | null; cnpj: string | null };
  cliente: { nome: string | null };
  validade_em: string | null;
  itens: ItemDaProposta[];
  subtotal_centavos: number;
  desconto_centavos: number;
  total_centavos: number;
  observacoes: string | null;
  aceite_nome: string | null;
  respondida_em: string | null;
}
