import { describe, expect, it } from 'vitest';
import type { Plano } from '@/features/assinatura/types';
import { formParaEntrada, planoFormSchema, planoParaForm } from './planos';

const plano: Plano = {
  id: 'p1', nome: 'Essencial', descricao: null, preco_mensal_centavos: 9990, preco_anual_centavos: null, dias_teste: 14,
  politica_excedente: 'BLOQUEAR', preco_documento_excedente_centavos: null, modulos: ['CRM'],
  limites: { USUARIOS: -1, CLIENTES: 500, PRODUTOS: 0, SERVICOS: 0, DOCUMENTOS_MES: 0, API_REQUISICOES_MIN: 0 },
  ativo: true, visivel: true, ordem: 1,
};

describe('planos (conversão do formulário)', () => {
  it('-1 vira "ilimitado" no formulário e volta como -1', () => {
    const form = planoParaForm(plano);
    expect(form.limites.USUARIOS).toEqual({ valor: 0, ilimitado: true });
    expect(form.preco_mensal).toBe('99,90');

    const entrada = formParaEntrada(form);
    expect(entrada.limites.USUARIOS).toBe(-1);
    expect(entrada.limites.CLIENTES).toBe(500);
    expect(entrada.preco_mensal_centavos).toBe(9990);
    expect(entrada.preco_anual_centavos).toBeNull();
    expect(entrada.descricao).toBeNull();
  });

  it('preço de excedente só é enviado com a política COBRAR', () => {
    const form = { ...planoParaForm(plano), preco_excedente: '0,10' };
    expect(formParaEntrada(form).preco_documento_excedente_centavos).toBeNull();
    expect(formParaEntrada({ ...form, politica_excedente: 'COBRAR' }).preco_documento_excedente_centavos).toBe(10);
  });

  it('COBRAR sem preço é inválido', () => {
    const resultado = planoFormSchema.safeParse({ ...planoParaForm(plano), politica_excedente: 'COBRAR', preco_excedente: '' });
    expect(resultado.success).toBe(false);
    expect(resultado.error?.issues[0]?.path).toEqual(['preco_excedente']);
  });
});
