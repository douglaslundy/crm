'use client';

import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { FormularioDeNota } from '@/features/notas/components/FormularioDeNota';

export default function NovaNotaPage() {
  return (
    <div className="space-y-4">
      <h1 className="font-display text-[28px] leading-tight font-extrabold">Nova nota</h1>
      <Card>
        <CardHeader><CardTitle>Dados da nota</CardTitle></CardHeader>
        <CardContent><FormularioDeNota /></CardContent>
      </Card>
    </div>
  );
}
