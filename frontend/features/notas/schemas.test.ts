import { describe, expect, it } from 'vitest';
import { cancelamentoSchema, inutilizacaoSchema, notaSchema, paraPayload, paraPayloadInutilizacao, type NotaFormDados } from './schemas';

const BASE: NotaFormDados = {
  cliente_id: '11111111-1111-4111-8111-111111111111',
  consumidor_final: '',
  desconto: '0,00',
  informacoes_complementares: '',
  itens: [{ produto_id: '22222222-2222-4222-8222-222222222222', quantidade: '2,5', valor_unitario: '' }],
  pagamentos: [{ tpag: '01', xpag: '', valor: '25,00' }],
};

describe('notaSchema', () => {
  it('aceita uma nota válida', () => {
    expect(notaSchema.safeParse(BASE).success).toBe(true);
  });

  it('exige ao menos um item e um pagamento', () => {
    expect(notaSchema.safeParse({ ...BASE, itens: [] }).success).toBe(false);
    expect(notaSchema.safeParse({ ...BASE, pagamentos: [] }).success).toBe(false);
  });

  it('recusa quantidade com mais de 3 casas ou zero', () => {
    expect(notaSchema.safeParse({ ...BASE, itens: [{ ...BASE.itens[0], quantidade: '1,2345' }] }).success).toBe(false);
    expect(notaSchema.safeParse({ ...BASE, itens: [{ ...BASE.itens[0], quantidade: '0' }] }).success).toBe(false);
  });

  it('exige descrição quando a forma de pagamento é Outros', () => {
    const r = notaSchema.safeParse({ ...BASE, pagamentos: [{ tpag: '99', xpag: '', valor: '25,00' }] });
    expect(r.success).toBe(false);
  });
});

describe('paraPayload', () => {
  it('converte vírgula em ponto, reais em centavos e vazio em null', () => {
    const p = paraPayload(BASE);
    expect(p.itens[0]).toEqual({ produto_id: BASE.itens[0].produto_id, quantidade: '2.5', valor_unitario_centavos: null });
    expect(p.pagamentos[0]).toEqual({ tpag: '01', xpag: null, valor_centavos: 2500 });
    expect(p.desconto_centavos).toBe(0);
    expect(p.consumidor_final).toBeNull();
    expect(p.informacoes_complementares).toBeNull();
  });

  it('mapeia consumidor final sim e não', () => {
    expect(paraPayload({ ...BASE, consumidor_final: 'sim' }).consumidor_final).toBe(true);
    expect(paraPayload({ ...BASE, consumidor_final: 'nao' }).consumidor_final).toBe(false);
  });
});

describe('cancelamentoSchema', () => {
  it('aceita de 15 a 255 caracteres depois de aparar', () => {
    expect(cancelamentoSchema.safeParse({ justificativa: 'a'.repeat(15) }).success).toBe(true);
    expect(cancelamentoSchema.safeParse({ justificativa: 'a'.repeat(255) }).success).toBe(true);
    expect(cancelamentoSchema.safeParse({ justificativa: `  ${'a'.repeat(15)}  ` }).success).toBe(true);
  });

  it('recusa 14 e 256 caracteres e só espaços', () => {
    expect(cancelamentoSchema.safeParse({ justificativa: 'a'.repeat(14) }).success).toBe(false);
    expect(cancelamentoSchema.safeParse({ justificativa: 'a'.repeat(256) }).success).toBe(false);
    expect(cancelamentoSchema.safeParse({ justificativa: ' '.repeat(40) }).success).toBe(false);
  });
});

describe('inutilizacaoSchema', () => {
  const BASE = { serie: '1', numero_inicial: '3', numero_final: '5', justificativa: 'Numeração perdida por queda' };

  it('aceita uma faixa válida e converte para o payload', () => {
    expect(inutilizacaoSchema.safeParse(BASE).success).toBe(true);
    expect(paraPayloadInutilizacao(BASE)).toEqual({ serie: '1', numero_inicial: 3, numero_final: 5, justificativa: 'Numeração perdida por queda' });
  });

  it('recusa inicial maior que o final, zero e série inválida', () => {
    expect(inutilizacaoSchema.safeParse({ ...BASE, numero_inicial: '6' }).success).toBe(false);
    expect(inutilizacaoSchema.safeParse({ ...BASE, numero_inicial: '0' }).success).toBe(false);
    expect(inutilizacaoSchema.safeParse({ ...BASE, serie: 'AB' }).success).toBe(false);
    expect(inutilizacaoSchema.safeParse({ ...BASE, justificativa: 'curta' }).success).toBe(false);
  });
});
