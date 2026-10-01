'use client';

import { zodResolver } from '@hookform/resolvers/zod';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useForm } from 'react-hook-form';
import { toast } from 'sonner';
import { Campo } from '@/components/form/Campo';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { ApiError } from '@/lib/api';
import { useEnvioUnico } from '@/lib/useEnvioUnico';
import { emitenteApi, QUERY_KEY_EMITENTE } from '../api';
import { cscSchema, type CscFormDados } from '../schemas';
import type { Emitente } from '../types';

export function CscForm({ emitente, onSalvar }: { emitente: Emitente; onSalvar?: () => void }) {
  const queryClient = useQueryClient();
  const form = useForm<CscFormDados>({
    resolver: zodResolver(cscSchema),
    defaultValues: { csc_id_homologacao: '', csc_token_homologacao: '', csc_id_producao: '', csc_token_producao: '' },
  });
  const salvar = useMutation({
    mutationFn: (d: CscFormDados) => emitenteApi.atualizarCsc(d),
    onSuccess: () => {
      toast.success('CSC salvo.');
      void queryClient.invalidateQueries({ queryKey: QUERY_KEY_EMITENTE });
      onSalvar?.();
    },
    onError: (erro) => form.setError('root', { message: erro instanceof ApiError ? erro.message : 'Não foi possível salvar.' }),
  });
  const envioUnico = useEnvioUnico();
  const erros = form.formState.errors;

  return (
    <form noValidate className="space-y-3" onSubmit={envioUnico(form.handleSubmit(async (d) => { await salvar.mutateAsync(d).catch(() => undefined); }))}>
      {emitente.exige_csc ? (
        <p className="text-sm text-amber-600">Seu plano inclui NFC-e: o CSC é necessário antes de emitir.</p>
      ) : (
        <p className="text-sm text-muted-foreground">Seu plano ainda não inclui NFC-e. Pode preencher mais tarde.</p>
      )}
      <Campo id="csc_id_homolog" label="ID do CSC (homologação)" erro={erros.csc_id_homologacao?.message}>
        {(a11y) => <Input {...a11y} {...form.register('csc_id_homologacao')} />}
      </Campo>
      <Campo id="csc_token_homolog" label="Token do CSC (homologação)" erro={erros.csc_token_homologacao?.message}>
        {(a11y) => <Input {...a11y} type="password" {...form.register('csc_token_homologacao')} />}
      </Campo>
      <Campo id="csc_id_prod" label="ID do CSC (produção)" erro={erros.csc_id_producao?.message}>
        {(a11y) => <Input {...a11y} {...form.register('csc_id_producao')} />}
      </Campo>
      <Campo id="csc_token_prod" label="Token do CSC (produção)" erro={erros.csc_token_producao?.message}>
        {(a11y) => <Input {...a11y} type="password" {...form.register('csc_token_producao')} />}
      </Campo>
      <p className="text-xs text-muted-foreground">
        {emitente.csc_homologacao_configurado ? 'Homologação já configurada. ' : ''}
        {emitente.csc_producao_configurado ? 'Produção já configurada.' : ''}
      </p>
      {erros.root ? <p role="alert" className="rounded-md bg-danger/10 p-2 text-sm text-danger">{erros.root.message}</p> : null}
      <Button type="submit" className="w-full" disabled={form.formState.isSubmitting}>Salvar CSC</Button>
    </form>
  );
}
