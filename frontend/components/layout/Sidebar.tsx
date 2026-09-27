'use client';

import Link from 'next/link';
import { usePathname } from 'next/navigation';
import { useUsuario } from '@/features/auth/hooks/useUsuario';
import { cn } from '@/lib/utils';
import { estaAtivo, itensVisiveis, type NavItem } from './nav-items';

export function Sidebar({ itens }: { itens: NavItem[] }) {
  const pathname = usePathname();
  const { data: usuario } = useUsuario();
  const visiveis = itensVisiveis(itens, usuario?.papel);

  return (
    <aside className="hidden w-60 shrink-0 border-r bg-card md:flex md:flex-col">
      <div className="px-5 py-4 text-lg font-semibold">Plataforma</div>
      <nav aria-label="Navegação principal" className="flex flex-col gap-1 px-3">
        {visiveis.map((item) => {
          const { href, label, icon: Icone } = item;
          const ativo = estaAtivo(item, pathname);
          return (
            <Link
              key={href}
              href={href}
              aria-current={ativo ? 'page' : undefined}
              className={cn(
                'flex items-center gap-3 rounded-md px-3 py-2 text-sm hover:bg-accent',
                ativo && 'bg-accent font-medium',
              )}
            >
              <Icone className="size-4" aria-hidden />
              {label}
            </Link>
          );
        })}
      </nav>
    </aside>
  );
}
