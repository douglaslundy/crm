import type { FormaPagamento, ModeloDaNota, StatusNota } from '@/features/notas/types';

export interface VendaNota {
  id: string;
  modelo: ModeloDaNota;
  status: StatusNota;
  numero: number | null;
  serie: string | null;
  subtotal_centavos: number;
  desconto_centavos: number;
  total_centavos: number;
}

export interface Venda {
  id: string;
  cliente?: { id: string; nome: string };
  desconto_centavos: number;
  subtotal_centavos: number;
  total_centavos: number;
  notas?: VendaNota[];
}

export interface VendaPayload {
  cliente_id: string;
  desconto_centavos: number;
  informacoes_complementares: string | null;
  itens_produtos: { produto_id: string; quantidade: string; valor_unitario_centavos: number | null }[];
  itens_servicos: { servico_id: string; quantidade: string; valor_unitario_centavos: number | null }[];
  pagamentos: { tpag: FormaPagamento; xpag: string | null; valor_centavos: number }[];
}

/** O que aconteceu com cada nota ao emitir: `bloqueio` só vem quando a nota não saiu. */
export interface NotaDaEmissao {
  nota_id: string;
  modelo: ModeloDaNota;
  status: StatusNota;
  bloqueio: { codigo: string; mensagem: string } | null;
}

export interface ResultadoDaEmissaoDaVenda {
  id: string;
  notas: NotaDaEmissao[];
}
