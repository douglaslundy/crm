'use client';

import { zodResolver } from '@hookform/resolvers/zod';
import { useMutation } from '@tanstack/react-query';
import Link from 'next/link';
import { useForm } from 'react-hook-form';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { ApiError } from '@/lib/api';
import { authApi } from '../api';
import { esqueciSenhaSchema, type EsqueciSenhaDados } from '../schemas';

export function EsqueciSenhaForm() {
  const form = useForm<EsqueciSenhaDados>({ resolver: zodResolver(esqueciSenhaSchema), defaultValues: { email: '' } });
  const pedido = useMutation({
    mutationFn: (dados: EsqueciSenhaDados) => authApi.esqueciSenha(dados),
    onError: (erro) =>
      form.setError('root', { message: erro instanceof ApiError ? erro.primeiraMensagem() : 'Tente novamente.' }),
  });

  if (pedido.isSuccess) {
    return (
      <div className="space-y-4 text-center">
        <p role="status">{pedido.data.message}</p>
        <Link href="/login" className="text-sm text-muted-foreground hover:text-foreground">Voltar ao login</Link>
      </div>
    );
  }

  const erros = form.formState.errors;
  return (
    <form onSubmit={form.handleSubmit((d) => pedido.mutate(d))} noValidate className="space-y-4">
      <div className="space-y-2">
        <Label htmlFor="email">E-mail cadastrado</Label>
        <Input id="email" type="email" autoComplete="email" {...form.register('email')} />
        {erros.email ? <p className="text-sm text-danger">{erros.email.message}</p> : null}
      </div>
      {erros.root ? <p role="alert" className="text-sm text-danger">{erros.root.message}</p> : null}
      <Button type="submit" className="w-full" disabled={pedido.isPending}>
        {pedido.isPending ? 'Enviando...' : 'Enviar link'}
      </Button>
      <Link href="/login" className="block text-center text-sm text-muted-foreground hover:text-foreground">
        Voltar ao login
      </Link>
    </form>
  );
}
