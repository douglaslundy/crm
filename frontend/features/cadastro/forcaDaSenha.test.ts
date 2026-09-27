import { describe, expect, it } from 'vitest';
import { forcaDaSenha } from './forcaDaSenha';

describe('forcaDaSenha', () => {
  it('classifica', () => {
    expect(forcaDaSenha('').rotulo).toBe('');
    expect(forcaDaSenha('abc').rotulo).toBe('Fraca');
    expect(forcaDaSenha('Senha123').rotulo).toBe('Média');
    expect(forcaDaSenha('Senha123!').rotulo).toBe('Forte');
  });
});
