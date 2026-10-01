import { z } from 'zod';

const vazioOuTexto = (max: number) => z.string().trim().max(max).optional().or(z.literal(''));
const origemTexto = z.string().trim().refine(
  (v) => v === '' || (/^\d+$/.test(v) && Number(v) >= 0 && Number(v) <= 8),
  'Origem deve ser um número de 0 a 8.',
);

export const produtoSchema = z.object({
  sku: z.string().trim().min(1, 'Informe o SKU.').max(60),
  nome: z.string().trim().min(1, 'Informe o nome.').max(150),
  unidade: z.string().trim().min(1, 'Informe a unidade.').max(10),
  preco_centavos: z.number().int().min(0, 'Informe um preço válido.'),
  gtin: vazioOuTexto(14),
  ncm: vazioOuTexto(20),
  cest: vazioOuTexto(20),
  origem: origemTexto,
  tributacao_icms: z.enum(['NORMAL', 'ST']).optional().or(z.literal('')),
});

export type ProdutoFormDados = z.infer<typeof produtoSchema>;

export const categoriaFiscalSchema = z.object({
  categoria: z.string().trim().min(1, 'Informe a categoria.').max(60),
  ncm: vazioOuTexto(20),
  origem: origemTexto,
  tributacao_icms: z.enum(['NORMAL', 'ST']).optional().or(z.literal('')),
});

export type CategoriaFiscalFormDados = z.infer<typeof categoriaFiscalSchema>;
