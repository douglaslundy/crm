'use client';

import { closestCorners, DndContext, KeyboardSensor, PointerSensor, useSensor, useSensors, type DragEndEvent } from '@dnd-kit/core';
import { destinoDoArraste, PREFIXO_COLUNA } from '../movimento';
import { useMoverNegocio } from '../hooks/useNegocios';
import type { Funil, Negocio } from '../types';
import { ColunaDaEtapa } from './ColunaDaEtapa';

interface Props {
  funil: Funil;
  negocios: Negocio[];
  podeEscrever: boolean;
}

export function KanbanDeNegocios({ funil, negocios, podeEscrever }: Props) {
  const mover = useMoverNegocio(funil.id);
  const sensores = useSensors(
    useSensor(PointerSensor, { activationConstraint: { distance: 6 } }),
    useSensor(KeyboardSensor),
  );
  const etapas = [...funil.etapas].filter((e) => e.ativa).sort((a, b) => a.posicao - b.posicao);

  function aoSoltar({ active, over }: DragEndEvent) {
    if (!over) return;
    const alvo = destinoDoArraste(String(over.id), negocios, String(active.id));
    if (alvo) mover.mutate({ id: String(active.id), ...alvo });
  }

  function aoMoverPeloMenu(negocioId: string, etapaId: string) {
    const alvo = destinoDoArraste(`${PREFIXO_COLUNA}${etapaId}`, negocios, negocioId);
    if (!alvo) return;
    // O cartão é remontado na nova coluna: devolve o foco ao controle dele para quem usa teclado.
    mover.mutate(
      { id: negocioId, ...alvo },
      { onSettled: () => setTimeout(() => document.getElementById(`mover-${negocioId}`)?.focus(), 0) },
    );
  }

  return (
    <DndContext sensors={sensores} collisionDetection={closestCorners} onDragEnd={aoSoltar}>
      <div className="flex snap-x snap-mandatory gap-3 overflow-x-auto pb-2">
        {etapas.map((etapa) => (
          <ColunaDaEtapa
            key={etapa.id}
            etapa={etapa}
            etapas={etapas}
            negocios={negocios.filter((n) => n.etapa_id === etapa.id)}
            podeEscrever={podeEscrever}
            aoMover={aoMoverPeloMenu}
          />
        ))}
      </div>
    </DndContext>
  );
}
