export type StatusDoNegocio = 'ABERTO' | 'GANHO' | 'PERDIDO';
export type TipoDeItem = 'PRODUTO' | 'SERVICO';

export interface Etapa {
  id: string;
  funil_id: string;
  nome: string;
  posicao: number;
  probabilidade: number;
  ativa: boolean;
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
