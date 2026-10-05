import { describe, expect, it } from 'vitest';
import { faixaDoCertificado, mesDeMaiorValor, rotuloDoMes } from './helpers';

describe('faixaDoCertificado', () => {
  it.each([
    [null, 'sem-validade'],
    [-1, 'vencido'],
    [-30, 'vencido'],
    [0, 'alerta'],
    [30, 'alerta'],
    [31, 'ok'],
    [365, 'ok'],
  ] as const)('%s dias => %s', (dias, faixa) => {
    expect(faixaDoCertificado(dias)).toBe(faixa);
  });
});

describe('rotuloDoMes', () => {
  it('abrevia o mês em português com o ano', () => {
    expect(rotuloDoMes('2026-10')).toBe('out/2026');
    expect(rotuloDoMes('2026-01')).toBe('jan/2026');
    expect(rotuloDoMes('2025-12')).toBe('dez/2025');
  });
});

describe('mesDeMaiorValor', () => {
  it('devolve o mês do maior valor', () => {
    expect(mesDeMaiorValor([
      { mes: '2026-08', valor_centavos: 100, quantidade: 1 },
      { mes: '2026-09', valor_centavos: 900, quantidade: 2 },
      { mes: '2026-10', valor_centavos: 300, quantidade: 1 },
    ])).toBe('2026-09');
  });

  it('é nulo quando todos os meses são zero', () => {
    expect(mesDeMaiorValor([{ mes: '2026-10', valor_centavos: 0, quantidade: 0 }])).toBeNull();
    expect(mesDeMaiorValor([])).toBeNull();
  });
});
