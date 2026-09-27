'use client';

import { zodResolver } from '@hookform/resolvers/zod';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import Link from 'next/link';
import { useRouter } from 'next/navigation';
import { useForm } from 'react-hook-form';
import { Campo } from '@/components/form/Campo';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { ApiError } from '@/lib/api';
import { useEnvioUnico } from '@/lib/useEnvioUnico';
import { authApi } from '../api';
import { QUERY_KEY_USUARIO } from '../hooks/useUsuario';
import { loginSchema, type LoginDados } from '../schemas';

export function LoginForm() {
  const router = useRouter();
  const queryClient = useQueryClient();
  const form = useForm<LoginDados>({ resolver: zodResolver(loginSchema), defaultValues: { email: '', password: '' } });

  const login = useMutation({
    mutationFn: (dados: LoginDados) => authApi.login(dados),
    onSuccess: ({ data }) => {
      queryClient.setQueryData(QUERY_KEY_USUARIO, data);
      router.push(data.tenant ? '/dashboard' : '/admin');
    },
    onError: (erro) => {
      const mensagem = erro instanceof ApiError ? erro.primeiraMensagem() : 'Não foi possível entrar. Tente novamente.';
      form.setError('root', { message: mensagem });
    },
  });

  // A trava de useEnvioUnico barra o 2º clique no mesmo tick; mutateAsync mantém
  // formState.isSubmitting = true (botão desabilitado) até a resposta chegar.
  const envioUnico = useEnvioUnico();
  const enviar = envioUnico(
    form.handleSubmit(async (dados) => {
      await login.mutateAsync(dados).catch(() => undefined);
    }),
  );
  const enviando = form.formState.isSubmitting;
  const erros = form.formState.errors;

  return (
    <form onSubmit={enviar} noValidate className="space-y-4">
      <Campo id="email" label="E-mail" erro={erros.email?.message}>
        {(a11y) => <Input {...a11y} type="email" autoComplete="email" {...form.register('email')} />}
      </Campo>
      <Campo id="password" label="Senha" erro={erros.password?.message}>
        {(a11y) => <Input {...a11y} type="password" autoComplete="current-password" {...form.register('password')} />}
      </Campo>
      {erros.root ? (
        <p role="alert" className="rounded-md bg-danger/10 p-2 text-sm text-danger">{erros.root.message}</p>
      ) : null}
      <Button type="submit" className="w-full" disabled={enviando}>
        {enviando ? 'Entrando...' : 'Entrar'}
      </Button>
      <Link href="/esqueci-senha" className="block text-center text-sm text-muted-foreground hover:text-foreground">
        Esqueci minha senha
      </Link>
    </form>
  );
}
