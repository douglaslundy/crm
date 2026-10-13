'use client';

import { useState } from 'react';
import { Banner } from '@/components/ui/banner';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { useUsuario } from '@/features/auth/hooks/useUsuario';
import { useAgenda } from '../hooks/useAtividades';
import { FormularioDeAtividade } from './FormularioDeAtividade';
import { ItemDeAtividade } from './ItemDeAtividade';

const PAPEIS_QUE_ESCREVEM = ['PROPRIETARIO', 'ADMIN', 'VENDEDOR'];

function dataLocal(dias: number): string {
  const d = new Date();
  d.setDate(d.getDate() + dias);
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

export function AgendaDoCrm() {
  const { data: usuario } = useUsuario();
  const podeEscrever = Boolean(usuario && PAPEIS_QUE_ESCREVEM.includes(usuario.papel));
  const [semana, setSemana] = useState(false);
  const [criando, setCriando] = useState(false);
  const agenda = useAgenda(dataLocal(0), dataLocal(semana ? 6 : 0));

  if (agenda.isError) return <Banner tom="perigo">Não foi possível carregar a agenda.</Banner>;

  const atividades = agenda.data ?? [];
  const atrasadas = atividades.filter((a) => a.atrasada);
  const doPeriodo = atividades.filter((a) => !a.atrasada);

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h1 className="font-display text-[28px] leading-tight font-extrabold">Agenda</h1>
        <div className="flex gap-2">
          <Button variant="outline" onClick={() => setSemana((v) => !v)}>{semana ? 'Ver só hoje' : 'Ver a semana'}</Button>
          {podeEscrever ? <Button onClick={() => setCriando((v) => !v)}>Nova atividade</Button> : null}
        </div>
      </div>

      {criando ? (
        <Card><CardContent className="pt-4"><FormularioDeAtividade aoCriar={() => setCriando(false)} /></CardContent></Card>
      ) : null}

      {agenda.isPending ? <p className="text-muted-foreground">Carregando...</p> : null}

      {atrasadas.length > 0 ? (
        <Card>
          <CardHeader><CardTitle>Atrasadas ({atrasadas.length})</CardTitle></CardHeader>
          <CardContent>
            <ul className="space-y-2">
              {atrasadas.map((a) => <ItemDeAtividade key={a.id} atividade={a} podeEscrever={podeEscrever} />)}
            </ul>
          </CardContent>
        </Card>
      ) : null}

      <Card>
        <CardHeader><CardTitle>{semana ? 'Próximos 7 dias' : 'Hoje'}</CardTitle></CardHeader>
        <CardContent>
          {doPeriodo.length === 0 && !agenda.isPending ? (
            <p className="text-sm text-muted-foreground">Nenhuma atividade neste período.</p>
          ) : (
            <ul className="space-y-2">
              {doPeriodo.map((a) => <ItemDeAtividade key={a.id} atividade={a} podeEscrever={podeEscrever} />)}
            </ul>
          )}
        </CardContent>
      </Card>
    </div>
  );
}
