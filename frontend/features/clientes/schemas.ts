import { z } from 'zod';

const vazioOuTexto = (max: number) => z.string().trim().max(max).optional().or(z.literal(''));

export const clienteSchema = z.object({
  tipo: z.enum(['PF', 'PJ'], { error: 'Escolha o tipo.' }),
  nome: z.string().trim().min(1, 'Informe o nome.').max(150),
  cpf_cnpj: vazioOuTexto(18),
  inscricao_estadual: vazioOuTexto(20),
  ie_isento: z.boolean(),
  email: z.string().trim().email('Informe um e-mail válido.').optional().or(z.literal('')),
  telefone: vazioOuTexto(20),
  logradouro: vazioOuTexto(150),
  numero: vazioOuTexto(20),
  bairro: vazioOuTexto(100),
  cidade: vazioOuTexto(100),
  uf: vazioOuTexto(2),
  cep: vazioOuTexto(9),
  origem: vazioOuTexto(60),
});

export type ClienteFormDados = z.infer<typeof clienteSchema>;

export const contatoSchema = z.object({
  nome: z.string().trim().min(1, 'Informe o nome.').max(150),
  cargo: vazioOuTexto(100),
  email: z.string().trim().email('Informe um e-mail válido.').optional().or(z.literal('')),
  telefone: vazioOuTexto(20),
});

export type ContatoFormDados = z.infer<typeof contatoSchema>;
