'use client';

import { ImportarCsvForm } from '@/components/form/ImportarCsvForm';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { QUERY_KEY_SERVICOS } from '@/features/servicos/api';
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
        <div className="space-y-4">
          <Card>
            <CardHeader><CardTitle>Novo serviço</CardTitle></CardHeader>
            <CardContent><ServicoForm /></CardContent>
          </Card>
          <Card>
            <CardHeader><CardTitle>Importar CSV</CardTitle></CardHeader>
            <CardContent><ImportarCsvForm endpoint="/api/app/servicos/importar" queryKey={QUERY_KEY_SERVICOS} /></CardContent>
          </Card>
        </div>
      </div>
    </div>
  );
}
