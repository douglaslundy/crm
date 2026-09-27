'use client';

import { useRouter } from 'next/navigation';
import { useEffect, type ReactNode } from 'react';
import { useUsuario } from '@/features/auth/hooks/useUsuario';

type Area = 'empresa' | 'plataforma';

/** Fica dentro do AuthGuard: o usuário já está carregado. Área errada vai para a certa. */
export function ExigirArea({ area, children }: { area: Area; children: ReactNode }) {
  const router = useRouter();
  const { data: usuario } = useUsuario();
  const areaDoUsuario: Area | null = usuario ? (usuario.tenant ? 'empresa' : 'plataforma') : null;
  const errada = areaDoUsuario !== null && areaDoUsuario !== area;

  useEffect(() => {
    if (errada) router.replace(areaDoUsuario === 'plataforma' ? '/admin' : '/dashboard');
  }, [errada, areaDoUsuario, router]);

  if (!usuario || errada) {
    return <div className="flex min-h-dvh items-center justify-center text-muted-foreground">Carregando...</div>;
  }
  return <>{children}</>;
}
