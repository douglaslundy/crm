export type CicloDeCobranca = 'MENSAL' | 'ANUAL';
export type MetodoDePagamento = 'CARTAO' | 'BOLETO';
export type StatusDaCobranca = 'PENDENTE' | 'PAGA' | 'VENCIDA' | 'CANCELADA';
export type StatusDaAssinaturaDeCobranca = 'PENDENTE' | 'ATIVA' | 'CANCELADA';

export const ROTULO_CICLO: Record<CicloDeCobranca, string> = { MENSAL: 'Mensal', ANUAL: 'Anual' };
export const ROTULO_METODO: Record<MetodoDePagamento, string> = { CARTAO: 'Cartão', BOLETO: 'Boleto' };
export const ROTULO_STATUS_COBRANCA: Record<StatusDaCobranca, string> = { PENDENTE: 'Pendente', PAGA: 'Paga', VENCIDA: 'Vencida', CANCELADA: 'Cancelada' };

export interface Cobranca {
  id: string;
  tipo: 'ASSINATURA';
  valor_centavos: number;
  vence_em: string;
  status: StatusDaCobranca;
  metodo: MetodoDePagamento;
  boleto_url: string | null;
  boleto_linha_digitavel: string | null;
  pago_em: string | null;
}

export interface AssinaturaDeCobranca {
  id: string;
  plano_id: string;
  ciclo: CicloDeCobranca;
  metodo: MetodoDePagamento;
  situacao: StatusDaAssinaturaDeCobranca;
  paga_ate: string | null;
  cancelada_em: string | null;
}

export interface CheckoutPayload {
  plano_id: string;
  ciclo: CicloDeCobranca;
  metodo: MetodoDePagamento;
  token_do_cartao: string | null;
}

export interface CheckoutResultado {
  assinatura: AssinaturaDeCobranca;
  cobranca: Cobranca;
}
