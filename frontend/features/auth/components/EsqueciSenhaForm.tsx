'use client';

import { zodResolver } from '@hookform/resolvers/zod';
import { useMutation } from '@tanstack/react-query';
import Link from 'next/link';
import { useForm } from 'react-hook-form';
import { Campo } from '@/components/form/Campo';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { ApiError } from '@/lib/api';
import { useEnvioUnico } from '@/lib/useEnvioUnico';
import { authApi } from '../api';
import { esqueciSenhaSchema, type EsqueciSenhaDados } from '../schemas';

export function EsqueciSenhaForm() {
  const form = useForm<EsqueciSenhaDados>({ resolver: zodResolver(esqueciSenhaSchema), defaultValues: { email: '' } });
  const pedido = useMutation({
    mutationFn: (dados: EsqueciSenhaDados) => authApi.esqueciSenha(dados),
    onError: (erro) =>
      form.setError('root', { message: erro instanceof ApiError ? erro.primeiraMensagem() : 'Tente novamente.' }),
  });

  const envioUnico = useEnvioUnico();

  if (pedido.isSuccess) {
    return (
      <div className="space-y-4 text-center">
        <p role="status">{pedido.data.message}</p>
        <Link href="/login" className="text-sm text-muted-foreground hover:text-foreground">Voltar ao login</Link>
      </div>
    );
  }

  const enviando = form.formState.isSubmitting;
  const erros = form.formState.errors;
  return (
    <form
      onSubmit={envioUnico(form.handleSubmit(async (d) => { await pedido.mutateAsync(d).catch(() => undefined); }))}
      noValidate
      className="space-y-4"
    >
      <Campo id="email" label="E-mail cadastrado" erro={erros.email?.message}>
        {(a11y) => <Input {...a11y} type="email" autoComplete="email" {...form.register('email')} />}
      </Campo>
      {erros.root ? <p role="alert" className="text-sm text-danger">{erros.root.message}</p> : null}
      <Button type="submit" className="w-full" disabled={enviando}>
        {enviando ? 'Enviando...' : 'Enviar link'}
      </Button>
      <Link href="/login" className="block text-center text-sm text-muted-foreground hover:text-foreground">
        Voltar ao login
      </Link>
    </form>
  );
}
