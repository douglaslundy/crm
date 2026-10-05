import { ChevronDown } from 'lucide-react';
import type { ComponentProps } from 'react';
import { cn } from '@/lib/utils';

/** Visual único dos campos de formulário (Input, select e textarea). */
export const CLASSE_CAMPO =
  'w-full min-w-0 rounded-lg border border-input bg-background px-3 py-[9px] text-sm text-foreground outline-none transition-colors placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-primary/25 disabled:cursor-not-allowed disabled:opacity-60 read-only:opacity-80 aria-invalid:border-danger aria-invalid:ring-[3px] aria-invalid:ring-danger/20';

/** <select> nativo: acessível, leve e testável; a seta é um ícone próprio (appearance-none a esconde). */
export function SelectNativo({ className, children, ...props }: ComponentProps<'select'>) {
  return (
    <div className="relative">
      <select className={cn(CLASSE_CAMPO, 'h-[37px] appearance-none py-0 pr-9', className)} {...props}>
        {children}
      </select>
      <ChevronDown
        data-testid="seta-select"
        aria-hidden
        className="pointer-events-none absolute top-1/2 right-3 size-4 -translate-y-1/2 text-muted-foreground"
      />
    </div>
  );
}

export function TextareaNativo({ className, ...props }: ComponentProps<'textarea'>) {
  return <textarea className={cn(CLASSE_CAMPO, 'min-h-[80px]', className)} {...props} />;
}
