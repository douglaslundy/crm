export type StatusNota = 'RASCUNHO' | 'PROCESSANDO' | 'AUTORIZADA' | 'REJEITADA' | 'ERRO' | 'CANCELADA' | 'CANCELANDO' | 'CONTINGENCIA';
export type FormaPagamento = '01' | '03' | '04' | '17' | '99';

export const ROTULO_STATUS: Record<StatusNota, string> = {
  RASCUNHO: 'Rascunho',
  PROCESSANDO: 'Processando',
  AUTORIZADA: 'Autorizada',
  REJEITADA: 'Rejeitada',
  ERRO: 'Erro',
  CANCELADA: 'Cancelada',
  CANCELANDO: 'Cancelando',
  CONTINGENCIA: 'Em contingência',
};

export const ROTULO_PAGAMENTO: Record<FormaPagamento, string> = {
  '01': 'Dinheiro',
  '03': 'Cartão de crédito',
  '04': 'Cartão de débito',
  '17': 'PIX',
  '99': 'Outros',
};

export interface NotaItem {
  id: number;
  produto_id: string | null;
  ordem: number;
  sku: string | null;
  descricao: string | null;
  unidade: string | null;
  quantidade: string;
  valor_unitario_centavos: number;
  desconto_centavos: number;
  total_centavos: number;
  cfop: string | null;
  csosn: string | null;
  servico_id: string | null;
  c_trib_nac: string | null;
}

export interface NotaPagamento {
  tpag: FormaPagamento;
  xpag: string | null;
  valor_centavos: number;
}

export interface NotaEvento {
  status_de: StatusNota | null;
  status_para: StatusNota;
  detalhe: string | null;
  em: string;
}

/** `DPS` é a NFS-e Nacional (o modelo da série e da nota no backend). */
export type ModeloDaNota = 'NFE' | 'NFCE' | 'DPS';

export const ROTULO_MODELO: Record<ModeloDaNota, string> = { NFE: 'NF-e', NFCE: 'NFC-e', DPS: 'NFS-e' };

export interface Nota {
  id: string;
  modelo: ModeloDaNota;
  contingencia_limite_em: string | null;
  contingencia_motivo: string | null;
  contingencia_tipo: 'OFFLINE_NFCE' | 'EPEC' | null;
  epec_protocolo: string | null;
  epec_registrado_em: string | null;
  status: StatusNota;
  ambiente: 'HOMOLOGACAO' | 'PRODUCAO' | null;
  numero: number | null;
  serie: string | null;
  chave: string | null;
  protocolo: string | null;
  numero_nfse: string | null;
  venda_id: string | null;
  cstat: string | null;
  motivo: string | null;
  mensagem_erro: string | null;
  natureza_operacao: string;
  consumidor_final: boolean | null;
  subtotal_centavos: number;
  desconto_centavos: number;
  total_centavos: number;
  informacoes_complementares: string | null;
  emitido_em: string | null;
  cancelamento_justificativa: string | null;
  cancelado_em: string | null;
  cancelamento_protocolo: string | null;
  cancelamento_cstat: string | null;
  cancelamento_motivo: string | null;
  created_at: string;
  cliente?: { id: string; nome: string } | null;
  itens?: NotaItem[];
  pagamentos?: NotaPagamento[];
  eventos?: NotaEvento[];
}

export type NotaStatus = Pick<Nota, 'id' | 'status' | 'numero' | 'serie' | 'chave' | 'cstat' | 'motivo' | 'mensagem_erro' | 'cancelamento_cstat' | 'cancelamento_motivo'>;

export interface NotaDeProdutosPayload {
  modelo: Exclude<ModeloDaNota, 'DPS'>;
  cliente_id: string | null;
  consumidor_final: boolean | null;
  desconto_centavos: number;
  informacoes_complementares: string | null;
  itens: { produto_id: string; quantidade: string; valor_unitario_centavos: number | null }[];
  pagamentos: { tpag: FormaPagamento; xpag: string | null; valor_centavos: number }[];
}

export interface NotaDeServicosPayload {
  modelo: 'DPS';
  cliente_id: string;
  consumidor_final: null;
  desconto_centavos: number;
  informacoes_complementares: string | null;
  itens: { servico_id: string; quantidade: string; valor_unitario_centavos: number | null }[];
}

export type NotaPayload = NotaDeProdutosPayload | NotaDeServicosPayload;

export type StatusInutilizacao = 'INUTILIZADA' | 'RECUSADA' | 'ERRO';

export const ROTULO_STATUS_INUTILIZACAO: Record<StatusInutilizacao, string> = {
  INUTILIZADA: 'Inutilizada',
  RECUSADA: 'Recusada pela SEFAZ',
  ERRO: 'Erro técnico',
};

export interface Inutilizacao {
  id: string;
  modelo: ModeloDaNota;
  serie: string;
  numero_inicial: number;
  numero_final: number;
  justificativa: string;
  ambiente: 'HOMOLOGACAO' | 'PRODUCAO';
  status: StatusInutilizacao;
  cstat: string | null;
  motivo: string | null;
  protocolo: string | null;
  created_at: string;
}

export interface InutilizacaoPayload {
  modelo: ModeloDaNota;
  serie: string;
  numero_inicial: number;
  numero_final: number;
  justificativa: string;
}
