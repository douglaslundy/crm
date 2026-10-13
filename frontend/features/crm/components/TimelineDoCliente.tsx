'use client';

import { useState } from 'react';
import { Banner } from '@/components/ui/banner';
import { Button } from '@/components/ui/button';
import { TextareaNativo } from '@/components/ui/campos-nativos';
import { Label } from '@/components/ui/label';
import { useCriarNota, useTimelineDoCliente } from '../hooks/useTimeline';
import type { TipoDeRegistro } from '../types';

const ROTULO: Record<TipoDeRegistro, string> = {
  NOTA: 'Nota',
  ATIVIDADE_CONCLUIDA: 'Atividade',
  NEGOCIO_CRIADO: 'Negócio',
  NEGOCIO_MOVIDO: 'Negócio',
  NEGOCIO_GANHO: 'Negócio',
  NEGOCIO_PERDIDO: 'Negócio',
};

function quando(iso: string): string {
  return new Date(iso).toLocaleString('pt-BR', { dateStyle: 'short', timeStyle: 'short' });
}

export function TimelineDoCliente({ clienteId, podeEscrever }: { clienteId: string; podeEscrever: boolean }) {
  const timeline = useTimelineDoCliente(clienteId);
  const criarNota = useCriarNota(clienteId);
  const [texto, setTexto] = useState('');

  const registros = timeline.data?.pages.flatMap((p) => p.data) ?? [];

  return (
    <div className="space-y-4">
      {podeEscrever ? (
        <form
          className="space-y-2"
          onSubmit={(e) => {
            e.preventDefault();
            if (texto.trim() === '' || criarNota.isPending) return;
            criarNota.mutate(texto.trim(), { onSuccess: () => setTexto('') });
          }}
        >
          <Label htmlFor="nova-nota">Nova nota</Label>
          <TextareaNativo id="nova-nota" maxLength={2000} value={texto} onChange={(e) => setTexto(e.target.value)} />
          <Button type="submit" size="sm" disabled={texto.trim() === '' || criarNota.isPending}>Registrar nota</Button>
        </form>
      ) : null}

      {timeline.isError ? <Banner tom="perigo">Não foi possível carregar a timeline.</Banner> : null}
      {timeline.isPending ? <p className="text-sm text-muted-foreground">Carregando...</p> : null}
      {timeline.isSuccess && registros.length === 0 ? (
        <p className="text-sm text-muted-foreground">Nada registrado para este cliente ainda.</p>
      ) : null}

      <ol className="space-y-2">
        {registros.map((r) => (
          <li key={r.id} className="rounded-lg border border-border bg-card p-3 text-sm">
            <p className="text-xs text-muted-foreground">
              {ROTULO[r.tipo]} · {quando(r.ocorreu_em)} · {r.autor?.nome ?? 'Sistema'}
            </p>
            <p className="mt-0.5">{r.texto}</p>
          </li>
        ))}
      </ol>

      {timeline.hasNextPage ? (
        <Button variant="outline" size="sm" disabled={timeline.isFetchingNextPage} onClick={() => void timeline.fetchNextPage()}>
          Carregar mais
        </Button>
      ) : null}
    </div>
  );
}
