'use client';

import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { ListaDeServicos } from '@/features/servicos/components/ListaDeServicos';
import { ServicoForm } from '@/features/servicos/components/ServicoForm';

export default function ServicosPage() {
  return (
    <div className="space-y-4">
      <h1 className="text-xl font-semibold">Serviços</h1>
      <div className="grid gap-4 lg:grid-cols-[1fr_22rem]">
        <Card>
          <CardHeader><CardTitle>Catálogo</CardTitle></CardHeader>
          <CardContent><ListaDeServicos /></CardContent>
        </Card>
        <Card>
          <CardHeader><CardTitle>Novo serviço</CardTitle></CardHeader>
          <CardContent><ServicoForm /></CardContent>
        </Card>
      </div>
    </div>
  );
}
