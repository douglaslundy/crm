'use client';

import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import type { Usuario } from '@/features/auth/types';
import { ApiError } from '@/lib/api';
import { QUERY_KEY_USUARIOS, usuariosApi } from '../api';
import { ROTULO_PAPEL, type UsuarioDaEmpresa } from '../types';
import { EditarUsuarioForm } from './EditarUsuarioForm';

export function ListaDeUsuarios({ autor }: { autor: Usuario }) {
  const queryClient = useQueryClient();
  const [editando, setEditando] = useState<string | null>(null);
  const { data, isPending, isError } = useQuery({
    queryKey: QUERY_KEY_USUARIOS,
    queryFn: async () => (await usuariosApi.listar()).data,
  });

  const alternar = useMutation({
    mutationFn: (u: UsuarioDaEmpresa) => (u.ativo ? usuariosApi.desativar(u.id) : usuariosApi.reativar(u.id)),
    onSuccess: ({ data: u }) => {
      toast.success(u.ativo ? `${u.nome} foi reativado.` : `${u.nome} foi desativado.`);
      void queryClient.invalidateQueries({ queryKey: QUERY_KEY_USUARIOS });
    },
    onError: (erro) => toast.error(erro instanceof ApiError ? erro.message : 'Tente novamente.'),
  });

  if (isPending) return <p className="text-muted-foreground">Carregando...</p>;
  if (isError) return <p role="alert">Não foi possível carregar os usuários.</p>;

  return (
    <ul className="divide-y">
      {data.map((u) => {
        const ehProprietario = u.papel === 'PROPRIETARIO';
        const ehVoce = u.id === autor.id;
        return (
          <li key={u.id} className="py-3">
            <div className="flex flex-wrap items-center justify-between gap-2">
              <div className="min-w-0">
                <p className="font-medium">{u.nome}{ehVoce ? ' (você)' : ''}</p>
                <p className="truncate text-sm text-muted-foreground">
                  {u.email} · {ROTULO_PAPEL[u.papel]}{u.ativo ? '' : ' · inativo'}
                </p>
              </div>
              {ehProprietario ? (
                <span className="text-xs text-muted-foreground">Proprietário da conta</span>
              ) : (
                <div className="flex gap-2">
                  <Button variant="outline" size="sm" aria-label={`Editar ${u.nome}`} onClick={() => setEditando(u.id)}>Editar</Button>
                  {ehVoce ? null : (
                    <Button
                      variant="outline"
                      size="sm"
                      aria-label={`${u.ativo ? 'Desativar' : 'Reativar'} ${u.nome}`}
                      disabled={alternar.isPending}
                      onClick={() => alternar.mutate(u)}
                    >
                      {u.ativo ? 'Desativar' : 'Reativar'}
                    </Button>
                  )}
                </div>
              )}
            </div>
            {editando === u.id ? <EditarUsuarioForm usuario={u} autor={autor.papel} onFechar={() => setEditando(null)} /> : null}
          </li>
        );
      })}
    </ul>
  );
}
