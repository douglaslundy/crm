'use client';

import { zodResolver } from '@hookform/resolvers/zod';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useForm } from 'react-hook-form';
import { toast } from 'sonner';
import { z } from 'zod';
import { Campo } from '@/components/form/Campo';
import { Button } from '@/components/ui/button';
import { SelectNativo, TextareaNativo } from '@/components/ui/campos-nativos';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { ROTULO_SITUACAO } from '@/features/assinatura/types';
import { ApiError } from '@/lib/api';
import { useEnvioUnico } from '@/lib/useEnvioUnico';
import { adminApi } from '../api';
import { destinosPermitidos } from '../situacoes';
import { SITUACOES, type Empresa } from '../types';

const schema = z.object({
  situacao: z.enum(SITUACOES, { error: 'Escolha a nova situação.' }),
  motivo: z.string().trim().min(3, 'Descreva o motivo (mínimo de 3 caracteres).').max(500, 'Use até 500 caracteres.'),
});
type Dados = z.infer<typeof schema>;

export function MudarSituacaoForm({ empresa }: { empresa: Empresa }) {
  const queryClient = useQueryClient();
  const destinos = destinosPermitidos(empresa.situacao);
  const form = useForm<Dados>({ resolver: zodResolver(schema), defaultValues: { motivo: '' } });
  const mudar = useMutation({
    mutationFn: (dados: Dados) => adminApi.mudarSituacao(empresa.id, dados),
    onSuccess: () => {
      toast.success('Situação atualizada.');
      form.reset({ motivo: '' });
      void queryClient.invalidateQueries({ queryKey: ['admin', 'empresas'] });
      void queryClient.invalidateQueries({ queryKey: ['admin', 'metricas'] });
    },
    onError: (erro) => form.setError('root', { message: erro instanceof ApiError ? erro.primeiraMensagem() : 'Tente novamente.' }),
  });
  const envioUnico = useEnvioUnico();
  const erros = form.formState.errors;

  return (
    <Card>
      <CardHeader><CardTitle>Mudar situação</CardTitle></CardHeader>
      <CardContent>
        {destinos.length === 0 ? (
          <p className="text-sm text-muted-foreground">Conta cancelada: a situação não muda mais.</p>
        ) : (
          <form
            noValidate
            className="space-y-3"
            onSubmit={envioUnico(form.handleSubmit(async (d) => { await mudar.mutateAsync(d).catch(() => undefined); }))}
          >
            <Campo id="situacao" label="Nova situação" erro={erros.situacao?.message}>
              {(a11y) => (
                <SelectNativo {...a11y} defaultValue="" {...form.register('situacao')}>
                  <option value="" disabled>Escolha...</option>
                  {destinos.map((s) => <option key={s} value={s}>{ROTULO_SITUACAO[s]}</option>)}
                </SelectNativo>
              )}
            </Campo>
            <Campo id="motivo" label="Motivo" erro={erros.motivo?.message}>
              {(a11y) => <TextareaNativo {...a11y} rows={3} {...form.register('motivo')} />}
            </Campo>
            {erros.root ? <p role="alert" className="text-sm text-danger">{erros.root.message}</p> : null}
            <Button type="submit" disabled={form.formState.isSubmitting}>Aplicar</Button>
          </form>
        )}
      </CardContent>
    </Card>
  );
}
