'use client';

import { useResumoDoCrm } from '@/features/crm/hooks/useAtividades';

/** Contador de atividades atrasadas no item "Agenda" do menu. Some quando não há nenhuma. */
export function EmblemaDeAtrasadas() {
  const { data } = useResumoDoCrm();
  const atrasadas = data?.atrasadas ?? 0;
  if (atrasadas === 0) return null;

  return (
    <span
      aria-label={`${atrasadas} ${atrasadas === 1 ? 'atividade atrasada' : 'atividades atrasadas'}`}
      className="ml-auto rounded-full bg-danger-strong px-1.5 py-0.5 text-[10px] font-bold text-white"
    >
      {atrasadas}
    </span>
  );
}
