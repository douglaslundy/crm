import type { SituacaoAssinatura } from '@/features/auth/types';

/** Espelho de SituacaoAssinatura::podeIrPara (backend). A regra que vale é a do backend; aqui só se montam as opções. */
const TRANSICOES: Record<SituacaoAssinatura, SituacaoAssinatura[]> = {
  PENDENTE: ['ATIVA'],
  TESTE: ['ATIVA', 'SUSPENSA'],
  ATIVA: ['SUSPENSA'],
  INADIMPLENTE: ['SUSPENSA'],
  SUSPENSA: ['ATIVA'],
  CANCELADA: [],
};

export function destinosPermitidos(situacao: SituacaoAssinatura): SituacaoAssinatura[] {
  return situacao === 'CANCELADA' ? [] : [...TRANSICOES[situacao], 'CANCELADA'];
}
