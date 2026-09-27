import { z } from 'zod';

const papel = z.enum(['PROPRIETARIO', 'ADMIN', 'FISCAL', 'VENDEDOR', 'LEITURA'], { error: 'Escolha o papel.' });
const nome = z.string().trim().min(1, 'Informe o nome.').max(120, 'Use até 120 caracteres.');

export const conviteSchema = z.object({ nome, email: z.string().trim().email('Informe um e-mail válido.'), papel });
export const edicaoSchema = z.object({ nome, papel });

export type ConviteDados = z.infer<typeof conviteSchema>;
export type EdicaoDados = z.infer<typeof edicaoSchema>;
