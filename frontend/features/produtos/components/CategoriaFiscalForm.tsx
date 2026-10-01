'use client';

import { zodResolver } from '@hookform/resolvers/zod';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useForm } from 'react-hook-form';
import { toast } from 'sonner';
import { Campo } from '@/components/form/Campo';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useEnvioUnico } from '@/lib/useEnvioUnico';
import { categoriasFiscaisApi, QUERY_KEY_CATEGORIAS_FISCAIS } from '../api';
import { categoriaFiscalSchema, type CategoriaFiscalFormDados } from '../schemas';

const VAZIO: CategoriaFiscalFormDados = { categoria: '', ncm: '', origem: '', tributacao_icms: '' };

export function CategoriaFiscalForm() {
  const queryClient = useQueryClient();
  const { data: categorias } = useQuery({ queryKey: QUERY_KEY_CATEGORIAS_FISCAIS, queryFn: async () => (await categoriasFiscaisApi.listar()).data });
  const form = useForm<CategoriaFiscalFormDados>({ resolver: zodResolver(categoriaFiscalSchema), defaultValues: VAZIO });
  const criar = useMutation({
    mutationFn: (d: CategoriaFiscalFormDados) => categoriasFiscaisApi.criar(d),
    onSuccess: () => {
      toast.success('Categoria fiscal criada.');
      form.reset(VAZIO);
      void queryClient.invalidateQueries({ queryKey: QUERY_KEY_CATEGORIAS_FISCAIS });
    },
  });
  const envioUnico = useEnvioUnico();

  return (
    <div className="space-y-3">
      <ul className="divide-y text-sm">
        {categorias?.map((c) => <li key={c.id} className="py-2">{c.categoria}</li>)}
      </ul>
      <form noValidate className="space-y-3" onSubmit={envioUnico(form.handleSubmit(async (d) => { await criar.mutateAsync(d).catch(() => undefined); }))}>
        <Campo id="categoria_nome" label="Nova categoria" erro={form.formState.errors.categoria?.message}>
          {(a11y) => <Input {...a11y} {...form.register('categoria')} />}
        </Campo>
        <Campo id="categoria_ncm" label="NCM padrão" erro={form.formState.errors.ncm?.message}>
          {(a11y) => <Input {...a11y} {...form.register('ncm')} />}
        </Campo>
        <Campo id="categoria_origem" label="Origem padrão" erro={form.formState.errors.origem?.message}>
          {(a11y) => <Input {...a11y} {...form.register('origem')} />}
        </Campo>
        <Button type="submit" size="sm" disabled={form.formState.isSubmitting}>Adicionar categoria</Button>
      </form>
    </div>
  );
}
