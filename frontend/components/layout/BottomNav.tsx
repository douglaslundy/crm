'use client';

import Link from 'next/link';
import { usePathname } from 'next/navigation';
import { useUsuario } from '@/features/auth/hooks/useUsuario';
import { cn } from '@/lib/utils';
import { estaAtivo, itensVisiveis, type NavItem } from './nav-items';

export function BottomNav({ itens }: { itens: NavItem[] }) {
  const pathname = usePathname();
  const { data: usuario } = useUsuario();
  const visiveis = itensVisiveis(itens, usuario?.papel);

  return (
    <nav
      aria-label="Navegação inferior"
      className="fixed inset-x-0 bottom-0 z-20 flex border-t bg-card pb-[env(safe-area-inset-bottom)] md:hidden"
    >
      {visiveis.map((item) => {
        const { href, label, icon: Icone } = item;
        const ativo = estaAtivo(item, pathname);
        return (
          <Link
            key={href}
            href={href}
            aria-current={ativo ? 'page' : undefined}
            className={cn('flex flex-1 flex-col items-center gap-1 py-2 text-xs', ativo ? 'text-primary' : 'text-muted-foreground')}
          >
            <Icone className="size-5" aria-hidden />
            {label}
          </Link>
        );
      })}
    </nav>
  );
}
