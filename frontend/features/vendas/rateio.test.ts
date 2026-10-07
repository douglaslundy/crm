import { describe, expect, it } from 'vitest';
import { ratearDesconto } from './rateio';

describe('ratearDesconto', () => {
  it('reparte em proporção aos subtotais e a soma fecha', () => {
    expect(ratearDesconto(1600, [1000, 15000])).toEqual([100, 1500]);
  });

  it('o resíduo de um centavo vai para o último que comporta', () => {
    expect(ratearDesconto(1, [1000, 15000])).toEqual([0, 1]);
  });

  it('zero é válido', () => {
    expect(ratearDesconto(0, [1000, 15000])).toEqual([0, 0]);
  });

  it('desconto maior que o subtotal lança', () => {
    expect(() => ratearDesconto(16001, [1000, 15000])).toThrow();
  });

  it('desconto negativo lança', () => {
    expect(() => ratearDesconto(-1, [1000, 15000])).toThrow();
  });
});
