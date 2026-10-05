'use client';

import { useQueryClient } from '@tanstack/react-query';
import { LogOut } from 'lucide-react';
import { useRouter } from 'next/navigation';
import { ThemeToggle } from '@/components/theme/ThemeToggle';
import { Button } from '@/components/ui/button';
import { authApi } from '@/features/auth/api';
import { useUsuario } from '@/features/auth/hooks/useUsuario';
import { MenuMobile } from './MenuMobile';
import type { NavItem } from './nav-items';

export function Topbar({ itens }: { itens: NavItem[] }) {
  const router = useRouter();
  const queryClient = useQueryClient();
  const { data: usuario } = useUsuario();

  async function sair() {
    await authApi.logout().catch(() => undefined);
    queryClient.clear();
    router.replace('/login');
  }

  return (
    <header className="sticky top-0 z-50 flex h-[60px] items-center justify-between gap-2 border-b border-border bg-surface px-4 md:px-6">
      <div className="flex min-w-0 items-center gap-2">
        <MenuMobile itens={itens} />
        <div className="min-w-0">
          <p className="truncate text-sm font-semibold">
            {usuario?.tenant ? (usuario.tenant.nome_fantasia ?? usuario.tenant.razao_social) : 'Administração da plataforma'}
          </p>
          <p className="truncate text-xs text-muted-foreground">{usuario?.nome}</p>
        </div>
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
