'use client';

import { zodResolver } from '@hookform/resolvers/zod';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useForm } from 'react-hook-form';
import { toast } from 'sonner';
import { Campo } from '@/components/form/Campo';
import { Button } from '@/components/ui/button';
import { SelectNativo } from '@/components/ui/campos-nativos';
import { Input } from '@/components/ui/input';
import type { Papel } from '@/features/auth/types';
import { ApiError } from '@/lib/api';
import { useEnvioUnico } from '@/lib/useEnvioUnico';
import { QUERY_KEY_USUARIOS, usuariosApi } from '../api';
import { edicaoSchema, type EdicaoDados } from '../schemas';
import { papeisAtribuiveis, ROTULO_PAPEL, type UsuarioDaEmpresa } from '../types';

interface Props {
  usuario: UsuarioDaEmpresa;
  autor: Papel;
  onFechar: () => void;
}

export function EditarUsuarioForm({ usuario, autor, onFechar }: Props) {
  const queryClient = useQueryClient();
  const form = useForm<EdicaoDados>({ resolver: zodResolver(edicaoSchema), defaultValues: { nome: usuario.nome, papel: usuario.papel } });
  const editar = useMutation({
    mutationFn: (dados: EdicaoDados) => usuariosApi.editar(usuario.id, dados),
    onSuccess: () => {
      toast.success('Usuário atualizado.');
      void queryClient.invalidateQueries({ queryKey: QUERY_KEY_USUARIOS });
      onFechar();
    },
    onError: (erro) => form.setError('root', { message: erro instanceof ApiError ? erro.primeiraMensagem() : 'Tente novamente.' }),
  });
  const envioUnico = useEnvioUnico();
  const erros = form.formState.errors;

  return (
    <form
      noValidate
      className="mt-2 grid gap-2 sm:grid-cols-[1fr_12rem_auto_auto] sm:items-end"
      onSubmit={envioUnico(form.handleSubmit(async (d) => { await editar.mutateAsync(d).catch(() => undefined); }))}
    >
      <Campo id={`nome-${usuario.id}`} label="Nome" erro={erros.nome?.message}>
        {(a11y) => <Input {...a11y} {...form.register('nome')} />}
      </Campo>
      <Campo id={`papel-${usuario.id}`} label="Papel" erro={erros.papel?.message}>
        {(a11y) => (
          <SelectNativo {...a11y} {...form.register('papel')}>
            {papeisAtribuiveis(autor).map((p) => <option key={p} value={p}>{ROTULO_PAPEL[p]}</option>)}
          </SelectNativo>
        )}
      </Campo>
      <Button type="submit" disabled={form.formState.isSubmitting}>Salvar</Button>
      <Button type="button" variant="ghost" onClick={onFechar}>Cancelar</Button>
      {erros.root ? <p role="alert" className="text-sm text-danger sm:col-span-4">{erros.root.message}</p> : null}
    </form>
  );
}
