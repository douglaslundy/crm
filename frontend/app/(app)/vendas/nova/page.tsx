'use client';

import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { VendaForm } from '@/features/vendas/components/VendaForm';

export default function NovaVendaPage() {
  return (
    <div className="space-y-4">
      <h1 className="font-display text-[28px] leading-tight font-extrabold">Nova venda (produtos e serviços)</h1>
      <p className="text-muted-foreground">Gera uma NF-e para os produtos e uma NFS-e para os serviços, independentes entre si.</p>
      <Card>
        <CardHeader><CardTitle>Dados da venda</CardTitle></CardHeader>
        <CardContent><VendaForm /></CardContent>
      </Card>
    </div>
  );
}
