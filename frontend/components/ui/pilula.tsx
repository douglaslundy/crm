import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

export type TomDaPilula = 'sucesso' | 'perigo' | 'info' | 'alerta' | 'neutro';

const SOLIDA: Record<TomDaPilula, string> = {
  sucesso: 'bg-success-strong text-white',
  perigo: 'bg-danger-strong text-white',
  info: 'bg-info-strong text-white',
  alerta: 'bg-primary text-primary-foreground',
  neutro: 'bg-muted-strong text-white',
};

const CONTORNO: Record<TomDaPilula, string> = {
  sucesso: 'border-success text-success',
  perigo: 'border-danger text-danger',
  info: 'border-info text-info',
  alerta: 'border-primary text-primary-text',
  neutro: 'border-muted-strong text-muted-foreground',
};

/** O rótulo é sempre texto: a cor nunca é a única informação. */
export function Pilula({ tom, contorno = false, children }: { tom: TomDaPilula; contorno?: boolean; children: ReactNode }) {
  return (
    <span
      data-tom={tom}
      data-contorno={contorno ? 'true' : undefined}
      className={cn(
        'inline-flex items-center rounded-full px-[11px] py-[3px] font-display text-[11px] font-bold tracking-[0.04em] uppercase',
        contorno ? cn('border-[1.5px] bg-transparent', CONTORNO[tom]) : SOLIDA[tom],
      )}
    >
      {children}
    </span>
  );
}
