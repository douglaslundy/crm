import { X } from 'lucide-react';
import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

export type TomDoBanner = 'perigo' | 'alerta' | 'info' | 'sucesso';

const COR: Record<TomDoBanner, string> = {
  perigo: 'border-danger/30 bg-danger/10 text-danger',
  alerta: 'border-primary/30 bg-primary/10 text-primary-text',
  info: 'border-info/30 bg-info/10 text-info',
  sucesso: 'border-success/30 bg-success/10 text-success',
};

export function Banner({ tom, onDispensar, rotulo, children }: { tom: TomDoBanner; onDispensar?: () => void; rotulo?: string; children: ReactNode }) {
  return (
    <div
      role={tom === 'perigo' ? 'alert' : 'status'}
      aria-label={rotulo}
      data-tom={tom}
      className={cn('flex items-start gap-3 rounded-lg border px-4 py-2.5 text-[13px] font-semibold', COR[tom])}
    >
      <div className="min-w-0 flex-1">{children}</div>
      {onDispensar ? (
        <button type="button" aria-label="Dispensar" onClick={onDispensar} className="shrink-0 rounded text-muted-foreground hover:text-foreground focus-visible:ring-[3px] focus-visible:ring-primary/25 focus-visible:outline-none">
          <X className="size-4" aria-hidden />
        </button>
      ) : null}
    </div>
  );
}
