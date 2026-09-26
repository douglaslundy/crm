import { describe, expect, it } from 'vitest';
import { loginSchema, redefinirSenhaSchema } from './schemas';

describe('schemas de autenticação', () => {
  it('login remove espaços do e-mail e exige senha', () => {
    expect(loginSchema.parse({ email: '  ana@x.com ', password: 'a' }).email).toBe('ana@x.com');
    expect(loginSchema.safeParse({ email: 'ana@x.com', password: '' }).success).toBe(false);
  });

  it('nova senha exige 8 caracteres, maiúscula, minúscula, número e confirmação igual', () => {
    const base = { token: 't', email: 'ana@x.com' };
    expect(redefinirSenhaSchema.safeParse({ ...base, password: 'Senha123', password_confirmation: 'Senha123' }).success).toBe(true);
    expect(redefinirSenhaSchema.safeParse({ ...base, password: 'senha123', password_confirmation: 'senha123' }).success).toBe(false);
    expect(redefinirSenhaSchema.safeParse({ ...base, password: 'SenhaABC', password_confirmation: 'SenhaABC' }).success).toBe(false);
    expect(redefinirSenhaSchema.safeParse({ ...base, password: 'Senha123', password_confirmation: 'Senha124' }).success).toBe(false);
  });
});
