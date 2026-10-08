import { z } from 'zod';
import { EVENTOS_DE_NOTA } from './types';

const NOMES = EVENTOS_DE_NOTA.map((e) => e.valor) as [string, ...string[]];

export const webhookSchema = z.object({
  url: z.string().trim().min(1, 'Informe a URL do webhook.').max(500, 'Use até 500 caracteres.'),
  descricao: z.string().trim().max(80, 'Use até 80 caracteres.'),
  ambiente: z.enum(['HOMOLOGACAO', 'PRODUCAO'], { error: 'Escolha o ambiente.' }),
  eventos: z.array(z.enum(NOMES)),
});

export type WebhookDados = z.infer<typeof webhookSchema>;
