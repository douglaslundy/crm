export type Papel = 'SUPERADMIN' | 'SUPORTE' | 'PROPRIETARIO' | 'ADMIN' | 'FISCAL' | 'VENDEDOR' | 'LEITURA';

export interface Usuario {
  id: string;
  nome: string;
  email: string;
  papel: Papel;
  tenant: { id: string; nome: string; cnpj: string } | null;
}
