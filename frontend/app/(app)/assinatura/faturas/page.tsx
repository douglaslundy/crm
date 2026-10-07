'use client';

import { ListaDeFaturas } from '@/features/cobranca/components/ListaDeFaturas';

export default function FaturasPage() {
  return (
    <div className="space-y-4">
      <h1 className="font-display text-[28px] leading-tight font-extrabold">Assinatura</h1>
      <ListaDeFaturas />
    </div>
  );
}
