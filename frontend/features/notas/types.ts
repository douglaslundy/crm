export type StatusNota = 'RASCUNHO' | 'PROCESSANDO' | 'AUTORIZADA' | 'REJEITADA' | 'ERRO' | 'CANCELADA';
export type FormaPagamento = '01' | '03' | '04' | '17' | '99';

export const ROTULO_STATUS: Record<StatusNota, string> = {
  RASCUNHO: 'Rascunho',
  PROCESSANDO: 'Processando',
  AUTORIZADA: 'Autorizada',
  REJEITADA: 'Rejeitada',
  ERRO: 'Erro',
  CANCELADA: 'Cancelada',
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
  produto_id: string;
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

export interface Nota {
  id: string;
  status: StatusNota;
  ambiente: 'HOMOLOGACAO' | 'PRODUCAO' | null;
  numero: number | null;
  serie: string | null;
  chave: string | null;
  protocolo: string | null;
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
  created_at: string;
  cliente?: { id: string; nome: string };
  itens?: NotaItem[];
  pagamentos?: NotaPagamento[];
  eventos?: NotaEvento[];
}

export type NotaStatus = Pick<Nota, 'id' | 'status' | 'numero' | 'serie' | 'chave' | 'cstat' | 'motivo' | 'mensagem_erro'>;

export interface NotaPayload {
  cliente_id: string;
  consumidor_final: boolean | null;
  desconto_centavos: number;
  informacoes_complementares: string | null;
  itens: { produto_id: string; quantidade: string; valor_unitario_centavos: number | null }[];
  pagamentos: { tpag: FormaPagamento; xpag: string | null; valor_centavos: number }[];
}
