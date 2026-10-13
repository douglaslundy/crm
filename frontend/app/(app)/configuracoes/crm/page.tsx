'use client';

import { ConfiguracoesDoCrm } from '@/features/crm/components/ConfiguracoesDoCrm';

export default function ConfiguracoesDoCrmPage() {
  return (
    <div className="space-y-4">
      <h1 className="font-display text-[28px] leading-tight font-extrabold">Configurações do CRM</h1>
      <ConfiguracoesDoCrm />
    </div>
  );
}
