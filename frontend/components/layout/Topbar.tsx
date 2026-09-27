'use client';

import { useQueryClient } from '@tanstack/react-query';
import { LogOut } from 'lucide-react';
import { useRouter } from 'next/navigation';
import { ThemeToggle } from '@/components/theme/ThemeToggle';
import { Button } from '@/components/ui/button';
import { authApi } from '@/features/auth/api';
import { useUsuario } from '@/features/auth/hooks/useUsuario';

export function Topbar() {
  const router = useRouter();
  const queryClient = useQueryClient();
  const { data: usuario } = useUsuario();

  async function sair() {
    await authApi.logout().catch(() => undefined);
    queryClient.clear();
    router.replace('/login');
  }

  return (
    <header className="sticky top-0 z-10 flex h-14 items-center justify-between border-b bg-background/95 px-4 backdrop-blur">
      <div className="min-w-0">
        <p className="truncate text-sm font-medium">
          {usuario?.tenant ? (usuario.tenant.nome_fantasia ?? usuario.tenant.razao_social) : 'Administração da plataforma'}
        </p>
        <p className="truncate text-xs text-muted-foreground">{usuario?.nome}</p>
      </div>
      <div className="flex items-center gap-1">
        <ThemeToggle />
        <Button variant="ghost" size="icon" aria-label="Sair" title="Sair" onClick={() => void sair()}>
          <LogOut className="size-5" />
        </Button>
      </div>
    </header>
  );
}
