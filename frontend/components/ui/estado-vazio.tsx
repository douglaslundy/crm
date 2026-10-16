import type { ReactNode } from 'react';

export function EstadoVazio({ titulo, children }: { titulo: string; children?: ReactNode }) {
  return (
    <div role="status" className="rounded-lg border border-dashed px-4 py-10 text-center">
      <p className="font-heading text-base font-semibold">{titulo}</p>
      {children ? <p className="mt-1 text-sm text-muted-foreground">{children}</p> : null}
    </div>
  );
}
