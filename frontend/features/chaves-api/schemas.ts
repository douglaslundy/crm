import { z } from 'zod';

export const chaveSchema = z.object({
  nome: z.string().trim().min(1, 'Informe um nome para a chave.').max(80, 'Use até 80 caracteres.'),
  ambiente: z.enum(['LIVE', 'TEST'], { error: 'Escolha o ambiente.' }),
});

export type ChaveDados = z.infer<typeof chaveSchema>;
