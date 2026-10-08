export type CicloDeCobranca = 'MENSAL' | 'ANUAL';
export type MetodoDePagamento = 'CARTAO' | 'BOLETO' | 'PIX';
export type StatusDaCobranca = 'PENDENTE' | 'PAGA' | 'VENCIDA' | 'CANCELADA';
export type StatusDaAssinaturaDeCobranca = 'PENDENTE' | 'ATIVA' | 'CANCELADA';

export const ROTULO_CICLO: Record<CicloDeCobranca, string> = { MENSAL: 'Mensal', ANUAL: 'Anual' };
export const ROTULO_METODO: Record<MetodoDePagamento, string> = { CARTAO: 'Cartão', BOLETO: 'Boleto', PIX: 'PIX' };
export const ROTULO_STATUS_COBRANCA: Record<StatusDaCobranca, string> = { PENDENTE: 'Pendente', PAGA: 'Paga', VENCIDA: 'Vencida', CANCELADA: 'Cancelada' };

export interface Cobranca {
  id: string;
  tipo: 'ASSINATURA' | 'EXCEDENTE';
  valor_centavos: number;
  vence_em: string;
  status: StatusDaCobranca;
  metodo: MetodoDePagamento | null;
  boleto_url: string | null;
  boleto_linha_digitavel: string | null;
  pago_em: string | null;
  pix_qr_code: string | null;
  pix_qr_code_base64: string | null;
  pix_expira_em: string | null;
  mes_referencia: string | null;
  documentos_excedentes: number | null;
  valor_unitario_centavos: number | null;
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

/** O pagamento avulso (excedente) é por PIX ou cartão, nunca boleto. */
export interface PagarPayload {
  metodo: 'CARTAO' | 'PIX';
  token_do_cartao: string | null;
}
