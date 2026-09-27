export type Papel = 'SUPERADMIN' | 'SUPORTE' | 'PROPRIETARIO' | 'ADMIN' | 'FISCAL' | 'VENDEDOR' | 'LEITURA';

export type SituacaoAssinatura = 'PENDENTE' | 'TESTE' | 'ATIVA' | 'INADIMPLENTE' | 'SUSPENSA' | 'CANCELADA';

export interface TenantResumo {
  id: string;
  razao_social: string;
  nome_fantasia: string | null;
  cnpj: string;
  situacao: SituacaoAssinatura;
  teste_termina_em: string | null;
}

export interface Usuario {
  id: string;
  nome: string;
  email: string;
  papel: Papel;
  tenant: TenantResumo | null;
}
