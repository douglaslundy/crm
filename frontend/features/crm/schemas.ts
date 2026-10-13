import { z } from 'zod';

export const negocioSchema = z
  .object({
    titulo: z.string().trim().min(1, 'Informe o título.').max(150, 'Use até 150 caracteres.'),
    modo: z.enum(['EXISTENTE', 'NOVO']),
    cliente_id: z.string(),
    lead_nome: z.string().trim().max(150, 'Use até 150 caracteres.'),
    lead_telefone: z.string().trim().max(20, 'Use até 20 caracteres.'),
    lead_email: z.string().trim().max(150, 'Use até 150 caracteres.'),
    previsao_fechamento: z.string(),
    valor_reais: z.string().trim().refine((v) => v === '' || /^\d+(,\d{1,2})?$/.test(v), 'Use o formato 99,90.'),
  })
  .superRefine((d, ctx) => {
    if (d.modo === 'EXISTENTE' && d.cliente_id === '') {
      ctx.addIssue({ code: 'custom', path: ['cliente_id'], message: 'Escolha o cliente.' });
    }
    if (d.modo === 'NOVO') {
      if (d.lead_nome === '') ctx.addIssue({ code: 'custom', path: ['lead_nome'], message: 'Informe o nome do lead.' });
      if (d.lead_telefone === '' && d.lead_email === '') {
        ctx.addIssue({ code: 'custom', path: ['lead_telefone'], message: 'Informe telefone ou e-mail.' });
      }
    }
  });

export type NegocioFormDados = z.infer<typeof negocioSchema>;
