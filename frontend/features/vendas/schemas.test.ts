import { describe, expect, it } from 'vitest';
import { paraPayloadDeVenda, vendaSchema, type VendaFormDados } from './schemas';

const BASE: VendaFormDados = {
  cliente_id: '11111111-1111-4111-8111-111111111111',
  desconto: '16,00',
  informacoes_complementares: '',
  itens_produtos: [{ produto_id: '22222222-2222-4222-8222-222222222222', quantidade: '2,5', valor_unitario: '' }],
  itens_servicos: [{ servico_id: '33333333-3333-4333-8333-333333333333', quantidade: '1', valor_unitario: '150,00' }],
  pagamentos: [{ tpag: '01', xpag: '', valor: '9,00' }],
};

describe('vendaSchema', () => {
  it('aceita uma venda válida', () => {
    expect(vendaSchema.safeParse(BASE).success).toBe(true);
  });

  it('exige cliente, ao menos um produto, um serviço e um pagamento', () => {
    expect(vendaSchema.safeParse({ ...BASE, cliente_id: '' }).success).toBe(false);
    expect(vendaSchema.safeParse({ ...BASE, itens_produtos: [] }).success).toBe(false);
    expect(vendaSchema.safeParse({ ...BASE, itens_servicos: [] }).success).toBe(false);
    expect(vendaSchema.safeParse({ ...BASE, pagamentos: [] }).success).toBe(false);
  });

  it('recusa quantidade com mais de 3 casas e informações com mais de 255 caracteres', () => {
    expect(vendaSchema.safeParse({ ...BASE, itens_produtos: [{ ...BASE.itens_produtos[0], quantidade: '1,2345' }] }).success).toBe(false);
    expect(vendaSchema.safeParse({ ...BASE, informacoes_complementares: 'x'.repeat(256) }).success).toBe(false);
  });
});

describe('paraPayloadDeVenda', () => {
  it('converte vírgula em ponto, reais em centavos e vazio em null', () => {
    const p = paraPayloadDeVenda(BASE);

    expect(p.desconto_centavos).toBe(1600);
    expect(p.informacoes_complementares).toBeNull();
    expect(p.itens_produtos[0]).toEqual({ produto_id: BASE.itens_produtos[0].produto_id, quantidade: '2.5', valor_unitario_centavos: null });
    expect(p.itens_servicos[0]).toEqual({ servico_id: BASE.itens_servicos[0].servico_id, quantidade: '1', valor_unitario_centavos: 15000 });
    expect(p.pagamentos[0]).toEqual({ tpag: '01', xpag: null, valor_centavos: 900 });
  });
});
