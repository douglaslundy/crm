import type { Papel } from '@/features/auth/types';

export type PapelDaEmpresa = Extract<Papel, 'PROPRIETARIO' | 'ADMIN' | 'FISCAL' | 'VENDEDOR' | 'LEITURA'>;

export const ROTULO_PAPEL: Record<PapelDaEmpresa, string> = {
  PROPRIETARIO: 'Proprietário', ADMIN: 'Administrador', FISCAL: 'Fiscal', VENDEDOR: 'Vendedor', LEITURA: 'Somente leitura',
};

export interface UsuarioDaEmpresa {
  id: string;
  nome: string;
  email: string;
  papel: PapelDaEmpresa;
  ativo: boolean;
}

/** Espelho da PoliticaDeUsuarios (backend), só para montar as opções. */
export function papeisAtribuiveis(autor: Papel): PapelDaEmpresa[] {
  const comuns: PapelDaEmpresa[] = ['ADMIN', 'FISCAL', 'VENDEDOR', 'LEITURA'];
  if (autor === 'PROPRIETARIO') return ['PROPRIETARIO', ...comuns];
  if (autor === 'ADMIN') return comuns;
  return [];
}

export function podeGerenciar(papel: Papel): boolean {
  return papel === 'PROPRIETARIO' || papel === 'ADMIN';
}
