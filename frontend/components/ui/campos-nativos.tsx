import type { ComponentProps } from 'react';
import { cn } from '@/lib/utils';

const base =
  'w-full rounded-lg border border-input bg-transparent px-2.5 text-base outline-none focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50 aria-invalid:border-destructive md:text-sm dark:bg-input/30';

/** <select> nativo: acessível, leve e testável; o visual acompanha o Input. */
export function SelectNativo({ className, ...props }: ComponentProps<'select'>) {
  return <select className={cn(base, 'h-8', className)} {...props} />;
}

export function TextareaNativo({ className, ...props }: ComponentProps<'textarea'>) {
  return <textarea className={cn(base, 'py-1.5', className)} {...props} />;
}
