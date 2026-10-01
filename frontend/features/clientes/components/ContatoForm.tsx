'use client';

import { zodResolver } from '@hookform/resolvers/zod';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useForm } from 'react-hook-form';
import { toast } from 'sonner';
import { Campo } from '@/components/form/Campo';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useEnvioUnico } from '@/lib/useEnvioUnico';
import { contatosApi, QUERY_KEY_CLIENTES } from '../api';
import { contatoSchema, type ContatoFormDados } from '../schemas';

const VAZIO: ContatoFormDados = { nome: '', cargo: '', email: '', telefone: '' };

export function ContatoForm({ clienteId }: { clienteId: string }) {
  const queryClient = useQueryClient();
  const form = useForm<ContatoFormDados>({ resolver: zodResolver(contatoSchema), defaultValues: VAZIO });
  const criar = useMutation({
    mutationFn: (d: ContatoFormDados) => contatosApi.criar(clienteId, d),
    onSuccess: () => {
      toast.success('Contato adicionado.');
      form.reset(VAZIO);
      void queryClient.invalidateQueries({ queryKey: QUERY_KEY_CLIENTES });
    },
    onError: () => toast.error('Não foi possível adicionar o contato.'),
  });
  const envioUnico = useEnvioUnico();

  return (
    <form noValidate className="space-y-2" onSubmit={envioUnico(form.handleSubmit(async (d) => { await criar.mutateAsync(d).catch(() => undefined); }))}>
      <Campo id="contato_nome" label="Nome do contato" erro={form.formState.errors.nome?.message}>
        {(a11y) => <Input {...a11y} {...form.register('nome')} />}
      </Campo>
      <Campo id="contato_cargo" label="Cargo" erro={form.formState.errors.cargo?.message}>
        {(a11y) => <Input {...a11y} {...form.register('cargo')} />}
      </Campo>
      <Button type="submit" size="sm" disabled={form.formState.isSubmitting}>Adicionar contato</Button>
    </form>
  );
}
