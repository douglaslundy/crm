import { z } from 'zod';

const vazioOuTexto = (max: number) => z.string().trim().max(max).optional().or(z.literal(''));

export const servicoSchema = z.object({
  nome: z.string().trim().min(1, 'Informe o nome.').max(150),
  preco_centavos: z.number().int().min(0, 'Informe um preço válido.'),
  codigo_lc116: z.string().trim().regex(/^(\d{2}\.\d{2})?$/, 'Use o formato 00.00.').optional().or(z.literal('')),
  c_trib_nac: z.string().trim().regex(/^(\d{6})?$/, 'Use 6 dígitos.').optional().or(z.literal('')),
  codigo_municipal: z.string().trim().regex(/^(\d{3})?$/, 'Use 3 dígitos.').optional().or(z.literal('')),
  aliquota_iss: z.string().trim().optional().or(z.literal('')),
  nbs: vazioOuTexto(9),
});

export type ServicoFormDados = z.infer<typeof servicoSchema>;
