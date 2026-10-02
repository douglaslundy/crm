'use client';

import { ImportarCsvForm } from '@/components/form/ImportarCsvForm';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { QUERY_KEY_PRODUTOS } from '@/features/produtos/api';
import { CategoriaFiscalForm } from '@/features/produtos/components/CategoriaFiscalForm';
import { ListaDeProdutos } from '@/features/produtos/components/ListaDeProdutos';
import { ProdutoForm } from '@/features/produtos/components/ProdutoForm';

export default function ProdutosPage() {
  return (
    <div className="space-y-4">
      <h1 className="text-xl font-semibold">Produtos</h1>
      <div className="grid gap-4 lg:grid-cols-[1fr_22rem]">
        <Card>
          <CardHeader><CardTitle>Catálogo</CardTitle></CardHeader>
          <CardContent><ListaDeProdutos /></CardContent>
        </Card>
        <div className="space-y-4">
          <Card>
            <CardHeader><CardTitle>Novo produto</CardTitle></CardHeader>
            <CardContent><ProdutoForm /></CardContent>
          </Card>
          <Card>
            <CardHeader><CardTitle>Importar CSV</CardTitle></CardHeader>
            <CardContent><ImportarCsvForm endpoint="/api/app/produtos/importar" queryKey={QUERY_KEY_PRODUTOS} /></CardContent>
          </Card>
          <Card>
            <CardHeader><CardTitle>Categorias fiscais padrão</CardTitle></CardHeader>
            <CardContent><CategoriaFiscalForm /></CardContent>
          </Card>
        </div>
      </div>
    </div>
  );
}
