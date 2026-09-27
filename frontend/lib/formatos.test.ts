import { describe, expect, it } from 'vitest';
import { centavosParaReais, formatarCentavos, formatarData, reaisParaCentavos } from './formatos';

const semNbsp = (s: string) => s.replace(/\s/g, ' ');

describe('formatos', () => {
  it('formata centavos em reais', () => {
    expect(semNbsp(formatarCentavos(9990))).toBe('R$ 99,90');
    expect(semNbsp(formatarCentavos(123456789))).toBe('R$ 1.234.567,89');
  });

  it('formata data ISO sem deslocar o fuso', () => {
    expect(formatarData('2026-10-15')).toBe('15/10/2026');
  });

  it('converte reais em centavos sem float', () => {
    expect(reaisParaCentavos('99,90')).toBe(9990);
    expect(reaisParaCentavos('99,9')).toBe(9990);
    expect(reaisParaCentavos('1234')).toBe(123400);
    expect(reaisParaCentavos('0,05')).toBe(5);
    expect(centavosParaReais(9990)).toBe('99,90');
    expect(centavosParaReais(5)).toBe('0,05');
  });
});
