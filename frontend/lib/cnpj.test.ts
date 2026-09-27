import { describe, expect, it } from 'vitest';
import { mascararCnpj, normalizarCnpj, validarCnpj } from './cnpj';

describe('cnpj', () => {
  it('valida numérico e alfanumérico (exemplo oficial da Receita)', () => {
    expect(validarCnpj('11.222.333/0001-81')).toBe(true);
    expect(validarCnpj('12.ABC.345/01DE-35')).toBe(true);
    expect(validarCnpj('12abc34501de35')).toBe(true);
  });

  it('recusa inválidos', () => {
    for (const invalido of ['11222333000182', '00000000000000', '1122233300018', '12ABC34501DE3A', '']) {
      expect(validarCnpj(invalido)).toBe(false);
    }
  });

  it('normaliza e mascara progressivamente', () => {
    expect(normalizarCnpj('12.abc.345/01de-35')).toBe('12ABC34501DE35');
    expect(mascararCnpj('12abc34501de35')).toBe('12.ABC.345/01DE-35');
    expect(mascararCnpj('11222')).toBe('11.222');
    expect(mascararCnpj('112223330001819999')).toBe('11.222.333/0001-81');
  });
});
