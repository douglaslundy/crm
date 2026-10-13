'use client';

import { useDroppable } from '@dnd-kit/core';
import { SortableContext, verticalListSortingStrategy } from '@dnd-kit/sortable';
import { formatarCentavos } from '@/lib/formatos';
import { PREFIXO_COLUNA } from '../movimento';
import type { Etapa, Negocio } from '../types';
import { CartaoDoNegocio } from './CartaoDoNegocio';

interface Props {
  etapa: Etapa;
  negocios: Negocio[];
  etapas: Etapa[];
  podeEscrever: boolean;
  aoMover: (negocioId: string, etapaId: string) => void;
}

export function ColunaDaEtapa({ etapa, negocios, etapas, podeEscrever, aoMover }: Props) {
  const { setNodeRef, isOver } = useDroppable({ id: `${PREFIXO_COLUNA}${etapa.id}` });
  const ordenados = [...negocios].sort((a, b) => a.posicao_na_etapa - b.posicao_na_etapa);
  const total = negocios.reduce((soma, n) => soma + n.valor_centavos, 0);

  return (
    <section
      aria-label={etapa.nome}
      className={`min-w-[16rem] max-w-[18rem] shrink-0 snap-start rounded-lg border bg-surface p-2 ${isOver ? 'border-primary' : 'border-border'}`}
    >
      <header className="mb-2 px-1">
        <h3 className="flex items-center justify-between gap-2 text-sm font-bold">
          <span className="truncate">{etapa.nome}</span>
          <span className="text-xs font-normal text-muted-foreground">{etapa.probabilidade}%</span>
        </h3>
        <p className="text-xs text-muted-foreground">
          {negocios.length} {negocios.length === 1 ? 'negócio' : 'negócios'} · {formatarCentavos(total)}
        </p>
      </header>
      <SortableContext items={ordenados.map((n) => n.id)} strategy={verticalListSortingStrategy}>
        <ul ref={setNodeRef} className="min-h-16 space-y-2">
          {ordenados.map((n) => (
            <CartaoDoNegocio
              key={n.id}
              negocio={n}
              etapas={etapas}
              podeEscrever={podeEscrever}
              aoMover={(destino) => aoMover(n.id, destino)}
            />
          ))}
        </ul>
      </SortableContext>
    </section>
  );
}
