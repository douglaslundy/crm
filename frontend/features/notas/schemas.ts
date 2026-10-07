import { z } from 'zod';
import { reaisParaCentavos } from '@/lib/formatos';
import type { InutilizacaoPayload, NotaDeProdutosPayload, NotaDeServicosPayload } from './types';

export const DINHEIRO = /^\d+(,\d{1,2})?$/;
const QUANTIDADE = /^\d{1,9}([.,]\d{1,3})?$/;

export const itemSchema = z.object({
  produto_id: z.string().uuid('Escolha um produto.'),
  quantidade: z
    .string()
    .regex(QUANTIDADE, 'Use até 3 casas decimais.')
    .refine((v) => Number(v.replace(',', '.')) > 0, 'A quantidade deve ser maior que zero.'),
  valor_unitario: z.string().refine((v) => v === '' || DINHEIRO.test(v), 'Use o formato 10,50.'),
});

export const pagamentoSchema = z
  .object({
    tpag: z.enum(['01', '03', '04', '17', '99']),
    xpag: z.string().max(60, 'No máximo 60 caracteres.'),
    valor: z.string().regex(DINHEIRO, 'Use o formato 10,50.'),
  })
  .refine((p) => p.tpag !== '99' || p.xpag.trim() !== '', { message: 'Descreva a forma de pagamento.', path: ['xpag'] });

export const notaSchema = z
  .object({
    modelo: z.enum(['NFE', 'NFCE']),
    cliente_id: z.string(),
    consumidor_final: z.enum(['', 'sim', 'nao']),
    desconto: z.string().regex(DINHEIRO, 'Use o formato 10,50.'),
    informacoes_complementares: z.string().max(5000, 'No máximo 5000 caracteres.'),
    itens: z.array(itemSchema).min(1, 'Adicione ao menos um item.'),
    pagamentos: z.array(pagamentoSchema).min(1, 'Adicione ao menos um pagamento.'),
  })
  // NFC-e vende sem identificar o consumidor; a NF-e sempre tem destinatário.
  .superRefine((d, ctx) => {
    if (d.modelo === 'NFE' && !z.string().uuid().safeParse(d.cliente_id).success) {
      ctx.addIssue({ code: 'custom', path: ['cliente_id'], message: 'Escolha um cliente.' });
    }
  });

export type NotaFormDados = z.infer<typeof notaSchema>;

export function paraPayload(d: NotaFormDados): NotaDeProdutosPayload {
  return {
    modelo: d.modelo,
    cliente_id: d.cliente_id === '' ? null : d.cliente_id,
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

export const servicoItemSchema = z.object({
  servico_id: z.string().uuid('Escolha um serviço.'),
  quantidade: z
    .string()
    .regex(QUANTIDADE, 'Use até 3 casas decimais.')
    .refine((v) => Number(v.replace(',', '.')) > 0, 'A quantidade deve ser maior que zero.'),
  valor_unitario: z.string().refine((v) => v === '' || DINHEIRO.test(v), 'Use o formato 10,50.'),
});

export const notaDeServicoSchema = z.object({
  cliente_id: z.string().uuid('Escolha um cliente.'),
  desconto: z.string().regex(DINHEIRO, 'Use o formato 10,50.'),
  // O limite de 255 é do campo de informações complementares da DPS.
  informacoes_complementares: z.string().max(255, 'No máximo 255 caracteres.'),
  itens: z.array(servicoItemSchema).min(1, 'Adicione ao menos um serviço.'),
});

export type NotaDeServicoDados = z.infer<typeof notaDeServicoSchema>;

export function paraPayloadDeServico(d: NotaDeServicoDados): NotaDeServicosPayload {
  return {
    modelo: 'DPS',
    cliente_id: d.cliente_id,
    consumidor_final: null,
    desconto_centavos: reaisParaCentavos(d.desconto),
    informacoes_complementares: d.informacoes_complementares.trim() === '' ? null : d.informacoes_complementares,
    itens: d.itens.map((i) => ({
      servico_id: i.servico_id,
      quantidade: i.quantidade.replace(',', '.'),
      valor_unitario_centavos: i.valor_unitario === '' ? null : reaisParaCentavos(i.valor_unitario),
    })),
  };
}

export const cancelamentoSchema = z.object({
  justificativa: z
    .string()
    .transform((v) => v.trim())
    .pipe(z.string().min(15, 'A justificativa deve ter de 15 a 255 caracteres.').max(255, 'A justificativa deve ter de 15 a 255 caracteres.')),
});

export type CancelamentoDados = z.input<typeof cancelamentoSchema>;

const INTEIRO_POSITIVO = /^[1-9]\d{0,8}$/;

export const inutilizacaoSchema = z
  .object({
    modelo: z.enum(['NFE', 'NFCE']),
    serie: z.string().regex(/^\d{1,3}$/, 'Informe a série (até 3 dígitos).'),
    numero_inicial: z.string().regex(INTEIRO_POSITIVO, 'Informe um número maior que zero.'),
    numero_final: z.string().regex(INTEIRO_POSITIVO, 'Informe um número maior que zero.'),
    justificativa: z.string().trim().min(15, 'A justificativa deve ter de 15 a 255 caracteres.').max(255, 'A justificativa deve ter de 15 a 255 caracteres.'),
  })
  .refine((d) => !INTEIRO_POSITIVO.test(d.numero_inicial) || !INTEIRO_POSITIVO.test(d.numero_final) || Number(d.numero_inicial) <= Number(d.numero_final), {
    message: 'O número inicial não pode passar do final.',
    path: ['numero_inicial'],
  });

export type InutilizacaoDados = z.infer<typeof inutilizacaoSchema>;

export function paraPayloadInutilizacao(d: InutilizacaoDados): InutilizacaoPayload {
  return { modelo: d.modelo, serie: d.serie, numero_inicial: Number(d.numero_inicial), numero_final: Number(d.numero_final), justificativa: d.justificativa.trim() };
}
