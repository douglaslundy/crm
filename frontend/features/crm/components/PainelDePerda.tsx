'use client';

import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { ApiError } from '@/lib/api';
import { crmApi, QUERY_KEY_CRM } from '../api';
import { useMotivosDePerda } from '../hooks/useMotivosDePerda';

export function PainelDePerda({ negocioId, onConcluido }: { negocioId: string; onConcluido: () => void }) {
  const queryClient = useQueryClient();
  const motivos = useMotivosDePerda();
  const [motivoId, setMotivoId] = useState('');
  const [observacao, setObservacao] = useState('');
  const [erro, setErro] = useState<string | null>(null);

  const perder = useMutation({
    mutationFn: () => crmApi.perder(negocioId, motivoId, observacao.trim() || undefined),
    onSuccess: () => {
      toast.success('Negócio marcado como perdido.');
      void queryClient.invalidateQueries({ queryKey: QUERY_KEY_CRM });
      onConcluido();
    },
    onError: (e) => toast.error(e instanceof ApiError ? e.message : 'Tente novamente.'),
  });

  function confirmar() {
    if (motivoId === '') {
      setErro('Escolha o motivo da perda.');
      return;
    }
    setErro(null);
    perder.mutate();
  }

  return (
    <div className="space-y-3 rounded-lg border border-border bg-card p-4">
      <div className="space-y-1">
        <Label htmlFor="motivo-da-perda">Motivo da perda</Label>
        <select
          id="motivo-da-perda"
          className="w-full rounded-md border border-border bg-background px-2 py-2 text-sm"
          value={motivoId}
          onChange={(e) => setMotivoId(e.target.value)}
        >
          <option value="">Escolha...</option>
          {(motivos.data ?? []).filter((m) => m.ativo).map((m) => (
            <option key={m.id} value={m.id}>{m.nome}</option>
          ))}
        </select>
        {erro ? <p className="text-xs text-danger">{erro}</p> : null}
      </div>
      <div className="space-y-1">
        <Label htmlFor="observacao-da-perda">Observação</Label>
        <textarea
          id="observacao-da-perda"
          rows={2}
          maxLength={1000}
          className="w-full rounded-md border border-border bg-background px-2 py-2 text-sm"
          value={observacao}
          onChange={(e) => setObservacao(e.target.value)}
        />
      </div>
      <Button type="button" variant="outline" disabled={perder.isPending} onClick={confirmar}>
        Confirmar perda
      </Button>
    </div>
  );
}
