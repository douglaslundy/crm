import { api } from '@/lib/api';
import type { Usuario } from './types';

interface Envelope<T> { data: T }
interface Mensagem { message: string }

export const authApi = {
  login: (dados: { email: string; password: string }) =>
    api<Envelope<Usuario>>('/api/app/auth/login', { method: 'POST', body: JSON.stringify(dados) }),
  logout: () => api<void>('/api/app/auth/logout', { method: 'POST' }),
  me: () => api<Envelope<Usuario>>('/api/app/auth/me'),
  esqueciSenha: (dados: { email: string }) =>
    api<Mensagem>('/api/app/auth/esqueci-senha', { method: 'POST', body: JSON.stringify(dados) }),
  redefinirSenha: (dados: { token: string; email: string; password: string; password_confirmation: string }) =>
    api<Mensagem>('/api/app/auth/redefinir-senha', { method: 'POST', body: JSON.stringify(dados) }),
};
