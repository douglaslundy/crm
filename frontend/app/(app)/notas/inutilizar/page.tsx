'use client';

import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { useUsuario } from '@/features/auth/hooks/useUsuario';
import { FormInutilizacao } from '@/features/notas/components/FormInutilizacao';
import { ListaDeInutilizacoes } from '@/features/notas/components/ListaDeInutilizacoes';

const PAPEIS_QUE_INUTILIZAM = ['PROPRIETARIO', 'ADMIN', 'FISCAL'];

export default function InutilizarPage() {
  const { data: usuario } = useUsuario();
  const pode = Boolean(usuario && PAPEIS_QUE_INUTILIZAM.includes(usuario.papel));

  return (
    <div className="space-y-4">
      <h1 className="font-display text-[28px] leading-tight font-extrabold">Inutilizar numeração</h1>
      {pode ? (
        <div className="grid gap-4 lg:grid-cols-[22rem_1fr]">
          <Card>
            <CardHeader><CardTitle>Nova inutilização</CardTitle></CardHeader>
            <CardContent><FormInutilizacao /></CardContent>
          </Card>
          <Card>
            <CardHeader><CardTitle>Histórico</CardTitle></CardHeader>
            <CardContent><ListaDeInutilizacoes /></CardContent>
          </Card>
        </div>
      ) : (
        <p className="text-muted-foreground">Você não tem permissão para inutilizar numeração.</p>
      )}
    </div>
  );
}
