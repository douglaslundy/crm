import { Receipt } from 'lucide-react';
import type { ReactNode } from 'react';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';

export function AuthCard({ titulo, descricao, children }: { titulo: string; descricao?: string; children: ReactNode }) {
  return (
    <main className="flex min-h-dvh">
      <aside
        data-testid="painel-de-marca"
        className="hidden flex-1 flex-col justify-center bg-surface bg-[radial-gradient(circle_at_30%_50%,color-mix(in_oklab,var(--primary)_8%,transparent),transparent_60%)] p-14 lg:flex"
      >
        <span className="flex size-[72px] items-center justify-center rounded-[18px] bg-primary text-primary-foreground">
          <Receipt className="size-9" aria-hidden />
        </span>
        <p className="mt-6 font-display text-4xl font-extrabold">Plataforma Fiscal</p>
        <p className="mt-2 text-base text-muted-foreground">Notas fiscais e CRM no mesmo lugar.</p>
      </aside>
      <div className="flex w-full items-center justify-center p-6 lg:w-[480px] lg:p-12">
        <Card className="w-full max-w-sm">
          <CardHeader>
            <CardTitle className="text-xl">{titulo}</CardTitle>
            {descricao ? <CardDescription>{descricao}</CardDescription> : null}
          </CardHeader>
          <CardContent>{children}</CardContent>
        </Card>
      </div>
    </main>
  );
}
