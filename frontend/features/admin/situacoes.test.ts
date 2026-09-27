import { describe, expect, it } from 'vitest';
import { destinosPermitidos } from './situacoes';

describe('destinosPermitidos (espelho da máquina de estados do backend)', () => {
  it('lista só as transições válidas', () => {
    expect(destinosPermitidos('PENDENTE')).toEqual(['ATIVA', 'CANCELADA']);
    expect(destinosPermitidos('TESTE')).toEqual(['ATIVA', 'SUSPENSA', 'CANCELADA']);
    expect(destinosPermitidos('ATIVA')).toEqual(['SUSPENSA', 'CANCELADA']);
    expect(destinosPermitidos('INADIMPLENTE')).toEqual(['SUSPENSA', 'CANCELADA']);
    expect(destinosPermitidos('SUSPENSA')).toEqual(['ATIVA', 'CANCELADA']);
    expect(destinosPermitidos('CANCELADA')).toEqual([]);
  });
});
