import { z } from 'zod';
import { senhaForte } from '@/features/auth/schemas';
import { validarCnpj } from '@/lib/cnpj';

export const CAMPOS_EMPRESA = ['cnpj', 'razao_social', 'nome_fantasia'] as const;

export const cadastroSchema = z
  .object({
    cnpj: z.string().refine(validarCnpj, 'Informe um CNPJ válido.'),
    razao_social: z.string().trim().min(1, 'Informe a razão social.').max(150, 'Use até 150 caracteres.'),
    nome_fantasia: z.string().trim().max(150, 'Use até 150 caracteres.'),
    responsavel_nome: z.string().trim().min(1, 'Informe seu nome.').max(120, 'Use até 120 caracteres.'),
    email: z.string().trim().email('Informe um e-mail válido.'),
    password: senhaForte,
    password_confirmation: z.string(),
    aceite_termos: z.boolean().refine((v) => v, 'Você precisa aceitar os termos de uso.'),
  })
  .refine((d) => d.password === d.password_confirmation, {
    path: ['password_confirmation'],
    message: 'As senhas não conferem.',
  });

export type CadastroDados = z.infer<typeof cadastroSchema>;
