import { ROTULO_SITUACAO } from '@/features/assinatura/types';
import type { SituacaoAssinatura } from '@/features/auth/types';
import { cn } from '@/lib/utils';

const TOM: Record<SituacaoAssinatura, string> = {
  PENDENTE: 'bg-muted text-foreground',
  TESTE: 'bg-info/10 text-info',
  ATIVA: 'bg-success/10 text-success',
  INADIMPLENTE: 'bg-warning/10 text-warning',
  SUSPENSA: 'bg-danger/10 text-danger',
  CANCELADA: 'bg-danger/10 text-danger',
};

export function BadgeSituacao({ situacao }: { situacao: SituacaoAssinatura }) {
  return <span className={cn('rounded-full px-2 py-0.5 text-xs font-medium', TOM[situacao])}>{ROTULO_SITUACAO[situacao]}</span>;
}
