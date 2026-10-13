'use client';

import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { toast } from 'sonner';
import { Banner } from '@/components/ui/banner';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { ApiError } from '@/lib/api';
import { crmApi } from '../api';
import { QUERY_KEY_MOTIVOS, useMotivosDePerda } from '../hooks/useMotivosDePerda';

export function EditorDeMotivos({ podeEditar }: { podeEditar: boolean }) {
  const queryClient = useQueryClient();
  const [nome, setNome] = useState('');
  const { data, isPending, isError } = useMotivosDePerda();
  const invalida = () => queryClient.invalidateQueries({ queryKey: QUERY_KEY_MOTIVOS });
  const aoErro = (erro: unknown) => toast.error(erro instanceof ApiError ? erro.message : 'Tente novamente.');

  const criar = useMutation({
    mutationFn: (valor: string) => crmApi.criarMotivo(valor),
    onSuccess: () => {
      setNome('');
      void invalida();
    },
    onError: aoErro,
  });
  const alternar = useMutation({
    mutationFn: ({ id, ativo }: { id: string; ativo: boolean }) => crmApi.editarMotivo(id, { ativo }),
    onSuccess: () => void invalida(),
    onError: aoErro,
  });

  if (isPending) return <p className="text-muted-foreground">Carregando...</p>;
  if (isError) return <Banner tom="perigo">Não foi possível carregar os motivos.</Banner>;

  return (
    <div className="space-y-3">
      <ul className="divide-y">
        {data.map((m) => (
          <li key={m.id} className="flex items-center justify-between gap-2 py-2">
            <span className={m.ativo ? '' : 'text-muted-foreground line-through'}>{m.nome}</span>
            {podeEditar ? (
              <Button
                variant="outline"
                size="sm"
                disabled={alternar.isPending}
                aria-label={`${m.ativo ? 'Desativar' : 'Ativar'} ${m.nome}`}
                onClick={() => alternar.mutate({ id: m.id, ativo: !m.ativo })}
              >
                {m.ativo ? 'Desativar' : 'Ativar'}
              </Button>
            ) : null}
          </li>
        ))}
      </ul>
      {podeEditar ? (
        <div className="flex items-end gap-2">
          <div className="flex-1 space-y-1">
            <Label htmlFor="novo-motivo">Novo motivo</Label>
            <Input id="novo-motivo" value={nome} maxLength={100} onChange={(e) => setNome(e.target.value)} />
          </div>
          <Button
            type="button"
            disabled={criar.isPending || nome.trim() === ''}
            aria-label="Adicionar motivo"
            onClick={() => criar.mutate(nome.trim())}
          >
            Adicionar
          </Button>
        </div>
      ) : null}
    </div>
  );
}
