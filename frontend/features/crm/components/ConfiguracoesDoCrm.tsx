'use client';

import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { useUsuario } from '@/features/auth/hooks/useUsuario';
import { EditorDeFunis } from './EditorDeFunis';
import { EditorDeMotivos } from './EditorDeMotivos';
import { InterruptorDeVisibilidade } from './InterruptorDeVisibilidade';

const PAPEIS_QUE_CONFIGURAM = ['PROPRIETARIO', 'ADMIN'];

export function ConfiguracoesDoCrm() {
  const { data: usuario } = useUsuario();
  const podeEditar = Boolean(usuario && PAPEIS_QUE_CONFIGURAM.includes(usuario.papel));

  return (
    <div className="space-y-4">
      <Card>
        <CardHeader><CardTitle>Visibilidade</CardTitle></CardHeader>
        <CardContent><InterruptorDeVisibilidade podeEditar={podeEditar} /></CardContent>
      </Card>
      <Card>
        <CardHeader><CardTitle>Funis e etapas</CardTitle></CardHeader>
        <CardContent><EditorDeFunis podeEditar={podeEditar} /></CardContent>
      </Card>
      <Card>
        <CardHeader><CardTitle>Motivos de perda</CardTitle></CardHeader>
        <CardContent><EditorDeMotivos podeEditar={podeEditar} /></CardContent>
      </Card>
    </div>
  );
}
