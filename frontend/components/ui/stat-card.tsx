import Link from 'next/link';
import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

export type TomDoStat = 'primario' | 'sucesso' | 'perigo' | 'info';

const FILETE: Record<TomDoStat, string> = {
  primario: 'border-t-primary',
  sucesso: 'border-t-success-strong',
  perigo: 'border-t-danger-strong',
  info: 'border-t-info-strong',
};

interface Props {
  titulo: string;
  valor: ReactNode;
  legenda?: string;
  tom?: TomDoStat;
  href?: string;
}

export function StatCard({ titulo, valor, legenda, tom = 'primario', href }: Props) {
  const corpo = (
    <>
      <p className="text-xs font-semibold tracking-[0.05em] text-muted-foreground uppercase">{titulo}</p>
      <p className="mt-1 font-mono text-[26px] leading-tight font-bold">{valor}</p>
      {legenda ? <p className="mt-1 text-xs text-muted-foreground">{legenda}</p> : null}
    </>
  );
  const classe = cn('block rounded-xl border border-t-2 border-border bg-card p-4', FILETE[tom], href && 'transition-colors hover:bg-hover focus-visible:ring-[3px] focus-visible:ring-primary/25 focus-visible:outline-none');
  return href ? <Link href={href} className={classe}>{corpo}</Link> : <div className={classe}>{corpo}</div>;
}
