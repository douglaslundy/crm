'use client';

import { useRouter } from 'next/navigation';
import { useEffect, type ReactNode } from 'react';
import { Button } from '@/components/ui/button';
import { useUsuario } from '@/features/auth/hooks/useUsuario';
import { ApiError } from '@/lib/api';

export function AuthGuard({ children }: { children: ReactNode }) {
  const router = useRouter();
  const { data, isPending, error, refetch } = useUsuario();
  const semSessao = error instanceof ApiError && error.status === 401;

  useEffect(() => {
    if (semSessao) router.replace('/login');
  }, [semSessao, router]);

  if (isPending || semSessao) {
    return <div className="flex min-h-dvh items-center justify-center text-muted-foreground">Carregando...</div>;
  }

  if (error || !data) {
    return (
      <div className="flex min-h-dvh flex-col items-center justify-center gap-4 p-4 text-center">
        <p>Não foi possível carregar sua sessão.</p>
        <Button onClick={() => void refetch()}>Tentar novamente</Button>
      </div>
    );
  }

  return <>{children}</>;
}
