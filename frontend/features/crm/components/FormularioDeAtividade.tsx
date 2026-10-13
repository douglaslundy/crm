'use client';

import { zodResolver } from '@hookform/resolvers/zod';
import { useForm } from 'react-hook-form';
import { z } from 'zod';
import { Campo } from '@/components/form/Campo';
import { Banner } from '@/components/ui/banner';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { SelectNativo, TextareaNativo } from '@/components/ui/campos-nativos';
import { ApiError } from '@/lib/api';
import { useEnvioUnico } from '@/lib/useEnvioUnico';
import { useCriarAtividade } from '../hooks/useAtividades';
import type { TipoDeAtividade } from '../types';
import { ROTULO_DO_TIPO } from './ItemDeAtividade';

const TIPOS = Object.keys(ROTULO_DO_TIPO) as TipoDeAtividade[];

const schema = z.object({
  tipo: z.enum(['LIGACAO', 'REUNIAO', 'EMAIL', 'WHATSAPP', 'TAREFA']),
  titulo: z.string().trim().min(1, 'Informe o título.').max(150, 'Use até 150 caracteres.'),
  data: z.string().min(1, 'Informe a data.'),
  hora: z.string().min(1, 'Informe a hora.'),
  descricao: z.string().trim().max(2000, 'Use até 2000 caracteres.'),
});

type Dados = z.infer<typeof schema>;

const VALORES_INICIAIS: Dados = { tipo: 'LIGACAO', titulo: '', data: '', hora: '09:00', descricao: '' };

interface Props {
  clienteId?: string;
  negocioId?: string;
  aoCriar: () => void;
}

export function FormularioDeAtividade({ clienteId, negocioId, aoCriar }: Props) {
  const form = useForm<Dados>({ resolver: zodResolver(schema), defaultValues: VALORES_INICIAIS });
  const criar = useCriarAtividade();
  const envioUnico = useEnvioUnico();

  const enviar = envioUnico(
    form.handleSubmit(async (d) => {
      try {
        await criar.mutateAsync({
          tipo: d.tipo,
          titulo: d.titulo,
          descricao: d.descricao === '' ? undefined : d.descricao,
          // Data e hora digitadas valem no fuso do navegador do usuário.
          agendada_para: new Date(`${d.data}T${d.hora}`).toISOString(),
          cliente_id: negocioId ? undefined : clienteId,
          negocio_id: negocioId,
        });
        form.reset(VALORES_INICIAIS);
        aoCriar();
      } catch (erro) {
        form.setError('root', {
          message: erro instanceof ApiError ? erro.primeiraMensagem() : 'Não foi possível criar a atividade.',
        });
      }
    }),
  );
  const erros = form.formState.errors;

  return (
    <form onSubmit={enviar} noValidate className="space-y-3">
      <div className="grid gap-3 sm:grid-cols-2">
        <Campo id="tipo" label="Tipo" erro={erros.tipo?.message}>
          {(a11y) => (
            <SelectNativo {...a11y} {...form.register('tipo')}>
              {TIPOS.map((t) => (
                <option key={t} value={t}>{ROTULO_DO_TIPO[t]}</option>
              ))}
            </SelectNativo>
          )}
        </Campo>
        <Campo id="titulo" label="Título" erro={erros.titulo?.message} obrigatorio>
          {(a11y) => <Input {...a11y} autoComplete="off" {...form.register('titulo')} />}
        </Campo>
        <Campo id="data" label="Data" erro={erros.data?.message} obrigatorio>
          {(a11y) => <Input {...a11y} type="date" {...form.register('data')} />}
        </Campo>
        <Campo id="hora" label="Hora" erro={erros.hora?.message} obrigatorio>
          {(a11y) => <Input {...a11y} type="time" {...form.register('hora')} />}
        </Campo>
      </div>
      <Campo id="descricao" label="Descrição" erro={erros.descricao?.message}>
        {(a11y) => <TextareaNativo {...a11y} {...form.register('descricao')} />}
      </Campo>
      {erros.root ? <Banner tom="perigo">{erros.root.message}</Banner> : null}
      <Button type="submit" disabled={form.formState.isSubmitting}>Criar atividade</Button>
    </form>
  );
}
