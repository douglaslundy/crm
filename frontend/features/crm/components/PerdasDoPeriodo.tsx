'use client';

import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { formatarCentavos } from '@/lib/formatos';
import type { MotivoDoRelatorio } from '../types';

export function PerdasDoPeriodo({ motivos }: { motivos: MotivoDoRelatorio[] }) {
  return (
    <Card>
      <CardHeader><CardTitle>Motivos de perda</CardTitle></CardHeader>
      <CardContent>
        {motivos.length === 0 ? (
          <p className="text-sm text-muted-foreground">Nenhum negócio perdido no período.</p>
        ) : (
          <ul className="divide-y divide-border text-sm">
            {motivos.map((m) => (
              <li key={m.motivo_id ?? 'sem-motivo'} className="flex flex-wrap items-baseline justify-between gap-2 py-2">
                <span className="font-medium">{m.nome}</span>
                <span className="text-muted-foreground">{m.quantidade} negócios · {formatarCentavos(m.valor_centavos)}</span>
              </li>
            ))}
          </ul>
        )}
      </CardContent>
    </Card>
  );
}
