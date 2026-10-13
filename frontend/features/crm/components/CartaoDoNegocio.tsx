'use client';

import { useSortable } from '@dnd-kit/sortable';
import { CSS } from '@dnd-kit/utilities';
import { GripVertical } from 'lucide-react';
import Link from 'next/link';
import { useState } from 'react';
import { formatarCentavos, formatarData } from '@/lib/formatos';
import { cn } from '@/lib/utils';
import type { Etapa, Negocio } from '../types';

interface Props {
  negocio: Negocio;
  etapas: Etapa[];
  podeEscrever: boolean;
  aoMover: (etapaId: string) => void;
}

function vencida(previsao: string | null): boolean {
  return previsao !== null && previsao < new Date().toISOString().slice(0, 10);
}

export function CartaoDoNegocio({ negocio, etapas, podeEscrever, aoMover }: Props) {
  const { attributes, listeners, setNodeRef, transform, transition, isDragging } = useSortable({
    id: negocio.id,
    disabled: !podeEscrever,
  });
  const [escolhida, setEscolhida] = useState<string | null>(null);
  const destino = escolhida ?? negocio.etapa_id;

  return (
    <li
      ref={setNodeRef}
      style={{ transform: CSS.Transform.toString(transform), transition }}
      className={cn('rounded-lg border border-border bg-card p-3 text-sm shadow-sm', isDragging && 'opacity-60')}
    >
      <div className="flex items-start gap-2">
        {podeEscrever ? (
          <button
            type="button"
            className="mt-0.5 cursor-grab touch-none text-muted-foreground"
            aria-label={`Arrastar ${negocio.titulo}`}
            {...attributes}
            {...listeners}
          >
            <GripVertical className="size-4" aria-hidden />
          </button>
        ) : null}
        <div className="min-w-0 flex-1 space-y-1">
          <Link href={`/crm/negocios/${negocio.id}`} className="block truncate font-semibold hover:underline">
            {negocio.titulo}
          </Link>
          <p className="truncate text-muted-foreground">{negocio.cliente?.nome ?? 'Cliente indisponível'}</p>
          <p className="font-medium">{formatarCentavos(negocio.valor_centavos)}</p>
          <p className="truncate text-xs text-muted-foreground">
            {negocio.responsavel?.nome ?? 'Sem responsável'}
            {negocio.previsao_fechamento ? (
              <span className={cn('ml-2', vencida(negocio.previsao_fechamento) && 'text-danger')}>
                · prev. {formatarData(negocio.previsao_fechamento)}
              </span>
            ) : null}
          </p>
        </div>
      </div>
      {podeEscrever ? (
        <div className="mt-2 flex gap-1">
          {/* Escolher e confirmar são passos separados: setas no select não movem o cartão sem querer. */}
          <select
            id={`mover-${negocio.id}`}
            className="min-w-0 flex-1 rounded-md border border-border bg-background px-2 py-1 text-xs"
            aria-label={`Mover ${negocio.titulo} para`}
            value={destino}
            onChange={(e) => setEscolhida(e.target.value)}
          >
            {etapas.map((e) => (
              <option key={e.id} value={e.id}>{e.nome}</option>
            ))}
          </select>
          <button
            type="button"
            aria-label={`Mover ${negocio.titulo}`}
            disabled={destino === negocio.etapa_id}
            className="rounded-md border border-border px-2 py-1 text-xs font-medium disabled:cursor-not-allowed disabled:opacity-50"
            onClick={() => {
              aoMover(destino);
              setEscolhida(null);
            }}
          >
            Mover
          </button>
        </div>
      ) : null}
    </li>
  );
}
