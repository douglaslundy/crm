'use client';

import { QuadroDeNegocios } from '@/features/crm/components/QuadroDeNegocios';

export default function NegociosPage() {
  return (
    <div className="space-y-4">
      <h1 className="font-display text-[28px] leading-tight font-extrabold">CRM</h1>
      <QuadroDeNegocios />
    </div>
  );
}
