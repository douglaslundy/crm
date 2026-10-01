import { z } from 'zod';

const vazioOuTexto = (max: number) => z.string().trim().max(max).optional().or(z.literal(''));

export const dadosDaEmpresaSchema = z.object({
  cep: vazioOuTexto(9),
  logradouro: vazioOuTexto(150),
  numero: vazioOuTexto(20),
  bairro: vazioOuTexto(100),
  cidade: vazioOuTexto(100),
  uf: vazioOuTexto(2),
  codigo_ibge: vazioOuTexto(7),
});
export type DadosDaEmpresaFormDados = z.infer<typeof dadosDaEmpresaSchema>;

export const dadosFiscaisSchema = z.object({
  regime_tributario: z.enum(['SIMPLES', 'MEI', 'NORMAL']).optional().or(z.literal('')),
  inscricao_estadual: vazioOuTexto(20),
  inscricao_municipal: vazioOuTexto(20),
  cnae: vazioOuTexto(10),
});
export type DadosFiscaisFormDados = z.infer<typeof dadosFiscaisSchema>;

export const certificadoSchema = z.object({ senha: z.string().min(1, 'Informe a senha do certificado.') });
export type CertificadoFormDados = z.infer<typeof certificadoSchema>;

export const cscSchema = z.object({
  csc_id_homologacao: vazioOuTexto(10),
  csc_token_homologacao: vazioOuTexto(100),
  csc_id_producao: vazioOuTexto(10),
  csc_token_producao: vazioOuTexto(100),
});
export type CscFormDados = z.infer<typeof cscSchema>;

export const serieSchema = z.object({
  serie: z.string().trim().min(1, 'Informe a série.').max(3),
  proximo_numero: z.coerce.number().int().min(1, 'O número inicial deve ser pelo menos 1.'),
});
export type SerieFormDados = z.infer<typeof serieSchema>;
