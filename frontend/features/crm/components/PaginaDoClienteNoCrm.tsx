'use client';

import Link from 'next/link';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { useUsuario } from '@/features/auth/hooks/useUsuario';
import { useAtividadesDoCliente } from '../hooks/useAtividades';
import { BotoesDeContato } from './BotoesDeContato';
import { FormularioDeAtividade } from './FormularioDeAtividade';
import { ItemDeAtividade } from './ItemDeAtividade';
import { TimelineDoCliente } from './TimelineDoCliente';

const PAPEIS_QUE_ESCREVEM = ['PROPRIETARIO', 'ADMIN', 'VENDEDOR'];

/** Visão do cliente no CRM: atividades e timeline. O nome vem das atividades (o Crm não lê o cadastro direto). */
export function PaginaDoClienteNoCrm({ id }: { id: string }) {
  const { data: usuario } = useUsuario();
  const podeEscrever = Boolean(usuario && PAPEIS_QUE_ESCREVEM.includes(usuario.papel));
  const atividades = useAtividadesDoCliente(id);
  const [criando, setCriando] = useState(false);
  const lista = atividades.data ?? [];
  const nome = lista.find((a) => a.cliente !== null)?.cliente?.nome ?? 'Cliente';

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h1 className="font-display text-[28px] leading-tight font-extrabold">{nome}</h1>
        <Link href="/crm/negocios" className="text-sm underline">Voltar ao quadro</Link>
      </div>

      <BotoesDeContato clienteId={id} podeEscrever={podeEscrever} />

      <Card>
        <CardHeader><CardTitle>Atividades</CardTitle></CardHeader>
        <CardContent className="space-y-3">
          {podeEscrever ? <Button size="sm" onClick={() => setCriando((v) => !v)}>Nova atividade</Button> : null}
          {criando ? <FormularioDeAtividade clienteId={id} aoCriar={() => setCriando(false)} /> : null}
          {atividades.isSuccess && lista.length === 0 ? (
            <p className="text-sm text-muted-foreground">Nenhuma atividade para este cliente.</p>
          ) : null}
          <ul className="space-y-2">
            {lista.map((a) => <ItemDeAtividade key={a.id} atividade={a} podeEscrever={podeEscrever} />)}
          </ul>
        </CardContent>
      </Card>

      <Card>
        <CardHeader><CardTitle>Timeline</CardTitle></CardHeader>
        <CardContent><TimelineDoCliente clienteId={id} podeEscrever={podeEscrever} /></CardContent>
      </Card>
    </div>
  );
}
