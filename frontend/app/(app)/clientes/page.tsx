'use client';

import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { useUsuario } from '@/features/auth/hooks/useUsuario';
import { ClienteForm } from '@/features/clientes/components/ClienteForm';
import { ListaDeClientes } from '@/features/clientes/components/ListaDeClientes';

const PAPEIS_QUE_ESCREVEM = ['PROPRIETARIO', 'ADMIN', 'FISCAL', 'VENDEDOR'];

export default function ClientesPage() {
  const { data: usuario } = useUsuario();
  const podeEscrever = Boolean(usuario && PAPEIS_QUE_ESCREVEM.includes(usuario.papel));

  return (
    <div className="space-y-4">
      <h1 className="text-xl font-semibold">Clientes</h1>
      <div className="grid gap-4 lg:grid-cols-[1fr_22rem]">
        <Card>
          <CardHeader><CardTitle>Clientes e leads</CardTitle></CardHeader>
          <CardContent><ListaDeClientes podeEscrever={podeEscrever} /></CardContent>
        </Card>
        {podeEscrever ? (
          <Card>
            <CardHeader><CardTitle>Novo cliente</CardTitle></CardHeader>
            <CardContent><ClienteForm /></CardContent>
          </Card>
        ) : null}
      </div>
    </div>
  );
}
