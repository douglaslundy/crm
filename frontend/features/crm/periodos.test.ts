import { describe, expect, it } from 'vitest';
import { mesAnterior, mesAtual, mesDoPeriodo, ultimosDias } from './periodos';

const hoje = new Date(2026, 9, 20); // 20/10/2026 (mês 9 = outubro)

describe('periodos', () => {
  it('mês atual vai do dia 1 ao último dia', () => {
    expect(mesAtual(hoje)).toEqual({ de: '2026-10-01', ate: '2026-10-31' });
  });

  it('mês anterior cruza o ano em janeiro', () => {
    expect(mesAnterior(new Date(2026, 0, 15))).toEqual({ de: '2025-12-01', ate: '2025-12-31' });
    expect(mesAnterior(hoje)).toEqual({ de: '2026-09-01', ate: '2026-09-30' });
  });

  it('últimos N dias inclui hoje', () => {
    expect(ultimosDias(90, hoje)).toEqual({ de: '2026-07-23', ate: '2026-10-20' });
  });

  it('mês do período só existe quando cobre um mês inteiro', () => {
    expect(mesDoPeriodo({ de: '2026-10-01', ate: '2026-10-31' })).toBe('2026-10');
    expect(mesDoPeriodo({ de: '2026-10-02', ate: '2026-10-31' })).toBeNull();
  });
});
