import { z } from 'zod';
import { itemSchema, DINHEIRO, pagamentoSchema, servicoItemSchema } from '@/features/notas/schemas';
import { reaisParaCentavos } from '@/lib/formatos';
import type { VendaPayload } from './types';

export const vendaSchema = z.object({
  cliente_id: z.string().uuid('Escolha um cliente.'),
  desconto: z.string().regex(DINHEIRO, 'Use o formato 10,50.'),
  // O limite de 255 é o da DPS, a menor das duas notas.
  informacoes_complementares: z.string().max(255, 'No máximo 255 caracteres.'),
  itens_produtos: z.array(itemSchema).min(1, 'Adicione ao menos um produto.'),
  itens_servicos: z.array(servicoItemSchema).min(1, 'Adicione ao menos um serviço.'),
  pagamentos: z.array(pagamentoSchema).min(1, 'Adicione ao menos um pagamento.'),
});

export type VendaFormDados = z.infer<typeof vendaSchema>;

export function paraPayloadDeVenda(d: VendaFormDados): VendaPayload {
  return {
    cliente_id: d.cliente_id,
    desconto_centavos: reaisParaCentavos(d.desconto),
    informacoes_complementares: d.informacoes_complementares.trim() === '' ? null : d.informacoes_complementares,
    itens_produtos: d.itens_produtos.map((i) => ({
      produto_id: i.produto_id,
      quantidade: i.quantidade.replace(',', '.'),
      valor_unitario_centavos: i.valor_unitario === '' ? null : reaisParaCentavos(i.valor_unitario),
    })),
    itens_servicos: d.itens_servicos.map((i) => ({
      servico_id: i.servico_id,
      quantidade: i.quantidade.replace(',', '.'),
      valor_unitario_centavos: i.valor_unitario === '' ? null : reaisParaCentavos(i.valor_unitario),
    })),
    pagamentos: d.pagamentos.map((p) => ({
      tpag: p.tpag,
      xpag: p.xpag.trim() === '' ? null : p.xpag,
      valor_centavos: reaisParaCentavos(p.valor),
    })),
  };
}
