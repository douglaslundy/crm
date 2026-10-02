import { cn } from '@/lib/utils';
import { ROTULO_STATUS, type StatusNota } from '../types';

const COR: Record<StatusNota, string> = {
  RASCUNHO: 'bg-muted text-muted-foreground',
  PROCESSANDO: 'bg-warning/15 text-warning',
  AUTORIZADA: 'bg-success/15 text-success',
  REJEITADA: 'bg-danger/15 text-danger',
  ERRO: 'bg-danger/15 text-danger',
  CANCELADA: 'bg-muted text-muted-foreground',
  CANCELANDO: 'bg-warning/15 text-warning',
};

/** Texto sempre presente: a cor sozinha não basta (acessibilidade). O ambiente fica visível ao lado. */
export function StatusDaNota({ status, ambiente }: { status: StatusNota; ambiente?: 'HOMOLOGACAO' | 'PRODUCAO' | null }) {
  return (
    <span className="inline-flex flex-wrap items-center gap-1.5 text-xs font-medium">
      <span className={cn('rounded-full px-2 py-0.5', COR[status])}>{ROTULO_STATUS[status]}</span>
      {ambiente ? (
        <span className={cn('rounded-full px-2 py-0.5', ambiente === 'PRODUCAO' ? 'bg-danger/15 text-danger' : 'bg-info/15 text-info')}>
          {ambiente === 'PRODUCAO' ? 'Produção' : 'Homologação'}
        </span>
      ) : null}
    </span>
  );
}
