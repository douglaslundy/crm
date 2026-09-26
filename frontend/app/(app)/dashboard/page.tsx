'use client';

import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { useUsuario } from '@/features/auth/hooks/useUsuario';

export default function DashboardPage() {
  const { data: usuario } = useUsuario();

  return (
    <Card>
      <CardHeader>
        <CardTitle>Olá, {usuario?.nome.split(' ')[0]}</CardTitle>
      </CardHeader>
      <CardContent className="text-muted-foreground">
        Os módulos da sua assinatura vão aparecer aqui conforme forem liberados.
      </CardContent>
    </Card>
  );
}
