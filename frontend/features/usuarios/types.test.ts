import { describe, expect, it } from 'vitest';
import { papeisAtribuiveis, podeGerenciar } from './types';

describe('papéis', () => {
  it('só o proprietário concede o papel de proprietário', () => {
    expect(papeisAtribuiveis('PROPRIETARIO')).toEqual(['PROPRIETARIO', 'ADMIN', 'FISCAL', 'VENDEDOR', 'LEITURA']);
    expect(papeisAtribuiveis('ADMIN')).toEqual(['ADMIN', 'FISCAL', 'VENDEDOR', 'LEITURA']);
    expect(papeisAtribuiveis('VENDEDOR')).toEqual([]);
  });

  it('só proprietário e administrador gerenciam', () => {
    expect(podeGerenciar('PROPRIETARIO')).toBe(true);
    expect(podeGerenciar('ADMIN')).toBe(true);
    expect(podeGerenciar('FISCAL')).toBe(false);
  });
});
