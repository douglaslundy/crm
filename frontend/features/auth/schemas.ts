import { z } from 'zod';

const email = z.string().trim().email('Informe um e-mail válido.');

export const loginSchema = z.object({
  email,
  password: z.string().min(1, 'Informe a senha.'),
});

export const esqueciSenhaSchema = z.object({ email });

export const senhaForte = z
  .string()
  .min(8, 'A senha precisa ter pelo menos 8 caracteres.')
  .regex(/[A-Z]/, 'Inclua pelo menos uma letra maiúscula.')
  .regex(/[a-z]/, 'Inclua pelo menos uma letra minúscula.')
  .regex(/[0-9]/, 'Inclua pelo menos um número.');

export const redefinirSenhaSchema = z
  .object({
    token: z.string().min(1),
    email,
    password: senhaForte,
    password_confirmation: z.string(),
  })
  .refine((d) => d.password === d.password_confirmation, {
    path: ['password_confirmation'],
    message: 'As senhas não conferem.',
  });

export type LoginDados = z.infer<typeof loginSchema>;
export type EsqueciSenhaDados = z.infer<typeof esqueciSenhaSchema>;
export type RedefinirSenhaDados = z.infer<typeof redefinirSenhaSchema>;
