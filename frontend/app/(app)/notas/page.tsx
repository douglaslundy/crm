'use client';

import Link from 'next/link';
import { buttonVariants } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { useUsuario } from '@/features/auth/hooks/useUsuario';
import { ListaDeNotas } from '@/features/notas/components/ListaDeNotas';

const PAPEIS_QUE_RASCUNHAM = ['PROPRIETARIO', 'ADMIN', 'FISCAL', 'VENDEDOR'];
const PAPEIS_QUE_EMITEM = ['PROPRIETARIO', 'ADMIN', 'FISCAL'];

export default function NotasPage() {
  const { data: usuario } = useUsuario();
  const podeCriar = Boolean(usuario && PAPEIS_QUE_RASCUNHAM.includes(usuario.papel));
  const podeInutilizar = Boolean(usuario && PAPEIS_QUE_EMITEM.includes(usuario.papel));

  return (
    <div className="space-y-4">
      <div className="flex items-center justify-between gap-2">
        <h1 className="font-display text-[28px] leading-tight font-extrabold">Notas fiscais</h1>
        <div className="flex gap-2">
          {podeInutilizar ? <Link href="/notas/inutilizar" className={buttonVariants({ variant: 'outline' })}>Inutilizar numeração</Link> : null}
          {podeCriar ? <Link href="/notas/nova" className={buttonVariants()}>Nova NF-e</Link> : null}
        </div>
      </div>
      <Card>
        <CardHeader><CardTitle>NF-e</CardTitle></CardHeader>
        <CardContent><ListaDeNotas /></CardContent>
      </Card>
    </div>
  );
}
