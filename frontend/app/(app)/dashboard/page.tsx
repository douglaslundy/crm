'use client';

import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { ConsumoDoPlano } from '@/features/assinatura/components/ConsumoDoPlano';
import { useAssinatura } from '@/features/assinatura/hooks/useAssinatura';
import { useUsuario } from '@/features/auth/hooks/useUsuario';

export default function DashboardPage() {
  const { data: usuario } = useUsuario();
  const { data: assinatura, isError } = useAssinatura();

  return (
    <div className="grid gap-4 lg:grid-cols-2">
      <Card>
        <CardHeader>
          <CardTitle>Olá, {usuario?.nome.split(' ')[0]}</CardTitle>
        </CardHeader>
        <CardContent className="text-muted-foreground">
          Os módulos da sua assinatura vão aparecer aqui conforme forem liberados.
        </CardContent>
      </Card>
      <Card>
        <CardHeader>
          <CardTitle>Seu plano{assinatura?.plano ? `: ${assinatura.plano.nome}` : ''}</CardTitle>
        </CardHeader>
        <CardContent>
          {assinatura ? (
            <ConsumoDoPlano itens={assinatura.consumo} />
          ) : (
            <p className="text-muted-foreground">{isError ? 'Não foi possível carregar o consumo.' : 'Carregando...'}</p>
          )}
        </CardContent>
      </Card>
    </div>
  );
}
