'use client';

import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { toast } from 'sonner';
import { Campo } from '@/components/form/Campo';
import { Button } from '@/components/ui/button';
import { SelectNativo } from '@/components/ui/campos-nativos';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { ROTULO_RECURSO } from '@/features/assinatura/types';
import { ApiError } from '@/lib/api';
import { formatarCentavos } from '@/lib/formatos';
import { adminApi } from '../api';
import type { Empresa, Excesso } from '../types';

export function TrocarPlanoForm({ empresa }: { empresa: Empresa }) {
  const queryClient = useQueryClient();
  const [planoId, setPlanoId] = useState('');
  const [falha, setFalha] = useState<{ mensagem: string; excessos: Excesso[] } | null>(null);
  const planos = useQuery({ queryKey: ['admin', 'planos'], queryFn: async () => (await adminApi.planos()).data });
  const opcoes = planos.data?.filter((p) => p.ativo && p.id !== empresa.plano?.id) ?? [];

  const trocar = useMutation({
    mutationFn: () => adminApi.trocarPlano(empresa.id, { plano_id: planoId }),
    onSuccess: () => {
      setFalha(null);
      setPlanoId('');
      toast.success('Plano trocado.');
      void queryClient.invalidateQueries({ queryKey: ['admin', 'empresas'] });
      void queryClient.invalidateQueries({ queryKey: ['admin', 'metricas'] });
    },
    onError: (erro) => {
      if (!(erro instanceof ApiError)) {
        setFalha({ mensagem: 'Tente novamente.', excessos: [] });
        return;
      }
      const excessos = erro.codigo === 'PLANO_EXCEDIDO' && Array.isArray(erro.corpo.excessos) ? (erro.corpo.excessos as Excesso[]) : [];
      setFalha({ mensagem: erro.primeiraMensagem(), excessos });
    },
  });

  return (
    <Card>
      <CardHeader><CardTitle>Trocar plano</CardTitle></CardHeader>
      <CardContent className="space-y-3">
        <Campo id="novo_plano" label="Novo plano">
          {(a11y) =>
            planos.isPending ? (
              <p className="text-sm text-muted-foreground">Carregando planos...</p>
            ) : (
              <SelectNativo {...a11y} value={planoId} onChange={(e) => setPlanoId(e.target.value)}>
                <option value="">Escolha...</option>
                {opcoes.map((p) => <option key={p.id} value={p.id}>{p.nome} ({formatarCentavos(p.preco_mensal_centavos)}/mês)</option>)}
              </SelectNativo>
            )
          }
        </Campo>
        {falha ? (
          <div role="alert" className="space-y-1 rounded-md bg-danger/10 p-2 text-sm text-danger">
            <p>{falha.mensagem}</p>
            {falha.excessos.length > 0 ? (
              <ul className="list-inside list-disc">
                {falha.excessos.map((x) => <li key={x.recurso}>{ROTULO_RECURSO[x.recurso]}: uso {x.uso}, limite {x.limite}</li>)}
              </ul>
            ) : null}
          </div>
        ) : null}
        <Button onClick={() => trocar.mutate()} disabled={!planoId || trocar.isPending}>Trocar plano</Button>
      </CardContent>
    </Card>
  );
}
