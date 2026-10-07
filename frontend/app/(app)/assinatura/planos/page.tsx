'use client';

import { Assinar } from '@/features/cobranca/components/Assinar';

export default function PlanosPage() {
  return (
    <div className="space-y-4">
      <h1 className="font-display text-[28px] leading-tight font-extrabold">Planos e pagamento</h1>
      <Assinar />
    </div>
  );
}
