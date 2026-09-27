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
import { conviteSchema, type ConviteDados } from '../schemas';
import { papeisAtribuiveis, ROTULO_PAPEL } from '../types';

const VAZIO: ConviteDados = { nome: '', email: '', papel: 'VENDEDOR' };

export function ConviteForm({ autor }: { autor: Papel }) {
  const queryClient = useQueryClient();
  const form = useForm<ConviteDados>({ resolver: zodResolver(conviteSchema), defaultValues: VAZIO });
  const convidar = useMutation({
    mutationFn: (dados: ConviteDados) => usuariosApi.convidar(dados),
    onSuccess: (_resposta, dados) => {
      toast.success(`Convite enviado para ${dados.email}.`);
      form.reset(VAZIO);
      void queryClient.invalidateQueries({ queryKey: QUERY_KEY_USUARIOS });
    },
    onError: (erro) => {
      if (!(erro instanceof ApiError)) {
        form.setError('root', { message: 'Não foi possível enviar o convite. Tente novamente.' });
        return;
      }
      const campos = Object.entries(erro.errors);
      if (campos.length === 0) form.setError('root', { message: erro.message });
      for (const [campo, mensagens] of campos) {
        const alvo = campo === 'nome' || campo === 'email' || campo === 'papel' ? campo : 'root';
        form.setError(alvo, { message: mensagens[0] });
      }
    },
  });
  const envioUnico = useEnvioUnico();
  const erros = form.formState.errors;

  return (
    <form
      noValidate
      className="space-y-3"
      onSubmit={envioUnico(form.handleSubmit(async (d) => { await convidar.mutateAsync(d).catch(() => undefined); }))}
    >
      <Campo id="convite_nome" label="Nome" erro={erros.nome?.message}>
        {(a11y) => <Input {...a11y} autoComplete="off" {...form.register('nome')} />}
      </Campo>
      <Campo id="convite_email" label="E-mail" erro={erros.email?.message}>
        {(a11y) => <Input {...a11y} type="email" autoComplete="off" {...form.register('email')} />}
      </Campo>
      <Campo id="convite_papel" label="Papel" erro={erros.papel?.message}>
        {(a11y) => (
          <SelectNativo {...a11y} {...form.register('papel')}>
            {papeisAtribuiveis(autor).map((p) => <option key={p} value={p}>{ROTULO_PAPEL[p]}</option>)}
          </SelectNativo>
        )}
      </Campo>
      {erros.root ? <p role="alert" className="rounded-md bg-danger/10 p-2 text-sm text-danger">{erros.root.message}</p> : null}
      <Button type="submit" className="w-full" disabled={form.formState.isSubmitting}>
        {form.formState.isSubmitting ? 'Enviando...' : 'Enviar convite'}
      </Button>
      <p className="text-xs text-muted-foreground">O convidado recebe um link, válido por 72 horas, para definir a senha.</p>
    </form>
  );
}
