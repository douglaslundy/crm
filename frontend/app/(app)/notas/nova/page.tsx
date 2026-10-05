'use client';

import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { NotaForm } from '@/features/notas/components/NotaForm';

export default function NovaNotaPage() {
  return (
    <div className="space-y-4">
      <h1 className="font-display text-[28px] leading-tight font-extrabold">Nova NF-e</h1>
      <Card>
        <CardHeader><CardTitle>Dados da nota</CardTitle></CardHeader>
        <CardContent><NotaForm /></CardContent>
      </Card>
    </div>
  );
}
