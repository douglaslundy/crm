import Link from 'next/link';
import type { ReactNode } from 'react';
import { ThemeToggle } from '@/components/theme/ThemeToggle';

export default function PublicoLayout({ children }: { children: ReactNode }) {
  return (
    <div className="min-h-dvh">
      <header className="flex h-[60px] items-center justify-between border-b border-border bg-surface px-4">
        <Link href="/planos" className="font-display text-base font-extrabold tracking-[0.03em]">Plataforma Fiscal</Link>
        <div className="flex items-center gap-2">
          <Link href="/login" className="text-sm text-muted-foreground hover:text-foreground">Entrar</Link>
          <ThemeToggle />
        </div>
      </header>
      <main className="mx-auto max-w-5xl p-4 md:p-8">{children}</main>
    </div>
  );
}
