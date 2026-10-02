import { z } from 'zod';
import { reaisParaCentavos } from '@/lib/formatos';
import type { NotaPayload } from './types';

const DINHEIRO = /^\d+(,\d{1,2})?$/;
const QUANTIDADE = /^\d{1,9}([.,]\d{1,3})?$/;

const itemSchema = z.object({
  produto_id: z.string().uuid('Escolha um produto.'),
  quantidade: z
    .string()
    .regex(QUANTIDADE, 'Use até 3 casas decimais.')
    .refine((v) => Number(v.replace(',', '.')) > 0, 'A quantidade deve ser maior que zero.'),
  valor_unitario: z.string().refine((v) => v === '' || DINHEIRO.test(v), 'Use o formato 10,50.'),
});

const pagamentoSchema = z
  .object({
    tpag: z.enum(['01', '03', '04', '17', '99']),
    xpag: z.string().max(60, 'No máximo 60 caracteres.'),
    valor: z.string().regex(DINHEIRO, 'Use o formato 10,50.'),
  })
  .refine((p) => p.tpag !== '99' || p.xpag.trim() !== '', { message: 'Descreva a forma de pagamento.', path: ['xpag'] });

export const notaSchema = z.object({
  cliente_id: z.string().uuid('Escolha um cliente.'),
  consumidor_final: z.enum(['', 'sim', 'nao']),
  desconto: z.string().regex(DINHEIRO, 'Use o formato 10,50.'),
  informacoes_complementares: z.string().max(5000, 'No máximo 5000 caracteres.'),
  itens: z.array(itemSchema).min(1, 'Adicione ao menos um item.'),
  pagamentos: z.array(pagamentoSchema).min(1, 'Adicione ao menos um pagamento.'),
});

export type NotaFormDados = z.infer<typeof notaSchema>;

export function paraPayload(d: NotaFormDados): NotaPayload {
  return {
    cliente_id: d.cliente_id,
    consumidor_final: d.consumidor_final === '' ? null : d.consumidor_final === 'sim',
    desconto_centavos: reaisParaCentavos(d.desconto),
    informacoes_complementares: d.informacoes_complementares.trim() === '' ? null : d.informacoes_complementares,
    itens: d.itens.map((i) => ({
      produto_id: i.produto_id,
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
