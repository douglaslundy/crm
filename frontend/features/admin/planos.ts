import { z } from 'zod';
import { ILIMITADO, MODULOS, RECURSOS, type Plano, type Recurso } from '@/features/assinatura/types';
import { centavosParaReais, reaisParaCentavos } from '@/lib/formatos';
import type { PlanoEntrada } from './types';

const reais = z.string().trim().regex(/^\d{1,7}(,\d{1,2})?$/, 'Use o formato 99,90.');

const limite = z
  .object({ valor: z.number().or(z.nan()), ilimitado: z.boolean() })
  .refine((l) => l.ilimitado || (Number.isInteger(l.valor) && l.valor >= 0), {
    path: ['valor'],
    message: 'Informe um número inteiro a partir de 0.',
  });

const formaDosLimites = Object.fromEntries(RECURSOS.map((r) => [r, limite])) as Record<Recurso, typeof limite>;

export const planoFormSchema = z
  .object({
    nome: z.string().trim().min(1, 'Informe o nome.').max(60, 'Use até 60 caracteres.'),
    descricao: z.string().max(500, 'Use até 500 caracteres.'),
    preco_mensal: reais,
    preco_anual: z.union([z.literal(''), reais]),
    dias_teste: z.number({ error: 'Informe um número.' }).int().min(0).max(90, 'Use no máximo 90 dias.'),
    politica_excedente: z.enum(['BLOQUEAR', 'COBRAR']),
    preco_excedente: z.union([z.literal(''), reais]),
    ativo: z.boolean(),
    visivel: z.boolean(),
    ordem: z.number({ error: 'Informe um número.' }).int().min(0),
    modulos: z.array(z.enum(MODULOS)),
    limites: z.object(formaDosLimites),
  })
  .refine((d) => d.politica_excedente === 'BLOQUEAR' || d.preco_excedente !== '', {
    path: ['preco_excedente'],
    message: 'Informe o preço por documento excedente.',
  });

export type PlanoFormDados = z.infer<typeof planoFormSchema>;

export function planoParaForm(plano?: Plano): PlanoFormDados {
  const anual = plano?.preco_anual_centavos ?? null;
  const excedente = plano?.preco_documento_excedente_centavos ?? null;

  return {
    nome: plano?.nome ?? '',
    descricao: plano?.descricao ?? '',
    preco_mensal: plano ? centavosParaReais(plano.preco_mensal_centavos) : '',
    preco_anual: anual === null ? '' : centavosParaReais(anual),
    dias_teste: plano?.dias_teste ?? 0,
    politica_excedente: plano?.politica_excedente ?? 'BLOQUEAR',
    preco_excedente: excedente === null ? '' : centavosParaReais(excedente),
    ativo: plano?.ativo ?? true,
    visivel: plano?.visivel ?? true,
    ordem: plano?.ordem ?? 0,
    modulos: plano?.modulos ?? [],
    limites: Object.fromEntries(
      RECURSOS.map((r) => {
        const valor = plano?.limites[r] ?? 0;
        return [r, { valor: valor === ILIMITADO ? 0 : valor, ilimitado: valor === ILIMITADO }];
      }),
    ) as PlanoFormDados['limites'],
  };
}

export function formParaEntrada(form: PlanoFormDados): PlanoEntrada {
  const descricao = form.descricao.trim();

  return {
    nome: form.nome.trim(),
    descricao: descricao === '' ? null : descricao,
    preco_mensal_centavos: reaisParaCentavos(form.preco_mensal),
    preco_anual_centavos: form.preco_anual === '' ? null : reaisParaCentavos(form.preco_anual),
    dias_teste: form.dias_teste,
    politica_excedente: form.politica_excedente,
    preco_documento_excedente_centavos:
      form.politica_excedente === 'COBRAR' && form.preco_excedente !== '' ? reaisParaCentavos(form.preco_excedente) : null,
    ativo: form.ativo,
    visivel: form.visivel,
    ordem: form.ordem,
    modulos: form.modulos,
    limites: Object.fromEntries(
      RECURSOS.map((r) => [r, form.limites[r].ilimitado ? ILIMITADO : form.limites[r].valor]),
    ) as Record<Recurso, number>,
  };
}

const CAMPOS_DA_API: Record<string, keyof PlanoFormDados> = {
  nome: 'nome',
  descricao: 'descricao',
  preco_mensal_centavos: 'preco_mensal',
  preco_anual_centavos: 'preco_anual',
  dias_teste: 'dias_teste',
  preco_documento_excedente_centavos: 'preco_excedente',
  ordem: 'ordem',
};

/** Traduz o nome do campo no erro 422 da API para o campo do formulário. */
export function campoDoFormulario(campoDaApi: string): keyof PlanoFormDados | null {
  return CAMPOS_DA_API[campoDaApi] ?? null;
}
