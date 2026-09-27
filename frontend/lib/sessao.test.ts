import { describe, expect, it, vi } from 'vitest';
import { ApiError } from './api';
import { irParaLoginSeNaoAutenticado } from './sessao';

const local = (pathname: string) => ({ pathname, assign: vi.fn() });

describe('irParaLoginSeNaoAutenticado', () => {
  it('401 leva ao login', () => {
    const l = local('/dashboard');
    irParaLoginSeNaoAutenticado(new ApiError(401, 'Não autenticado.'), l);
    expect(l.assign).toHaveBeenCalledWith('/login');
  });

  it('na própria tela de login não redireciona', () => {
    const l = local('/login');
    irParaLoginSeNaoAutenticado(new ApiError(401, 'x'), l);
    expect(l.assign).not.toHaveBeenCalled();
  });

  it('outros erros não redirecionam', () => {
    const l = local('/dashboard');
    irParaLoginSeNaoAutenticado(new ApiError(500, 'x'), l);
    irParaLoginSeNaoAutenticado(new Error('rede'), l);
    expect(l.assign).not.toHaveBeenCalled();
  });
});
