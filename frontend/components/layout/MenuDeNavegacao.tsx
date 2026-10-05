'use client';

import { Receipt } from 'lucide-react';
import Link from 'next/link';
import { usePathname } from 'next/navigation';
import { useUsuario } from '@/features/auth/hooks/useUsuario';
import { cn } from '@/lib/utils';
import { estaAtivo, itensVisiveis, type NavItem } from './nav-items';

interface Props {
  itens: NavItem[];
  rotulo: string;
  /** Chamado ao escolher um item (a gaveta do celular fecha aqui). */
  aoNavegar?: () => void;
}

/** Marca + itens + rodapé do usuário: o mesmo conteúdo no menu fixo e na gaveta. */
export function MenuDeNavegacao({ itens, rotulo, aoNavegar }: Props) {
  const pathname = usePathname();
  const { data: usuario } = useUsuario();
  const visiveis = itensVisiveis(itens, usuario?.papel);

  return (
    <div className="flex h-full flex-col">
      <div className="flex h-[60px] shrink-0 items-center gap-2.5 border-b border-border px-4">
        <span className="flex size-[30px] items-center justify-center rounded-[7px] bg-primary text-primary-foreground">
          <Receipt className="size-4" aria-hidden />
        </span>
        <span className="font-display text-base font-extrabold tracking-[0.03em]">Plataforma Fiscal</span>
      </div>
      <nav aria-label={rotulo} className="flex flex-1 flex-col gap-0.5 overflow-y-auto py-3">
        {visiveis.map((item) => {
          const { href, label, icon: Icone } = item;
          const ativo = estaAtivo(item, pathname);
          return (
            <Link
              key={href}
              href={href}
              onClick={aoNavegar}
              aria-current={ativo ? 'page' : undefined}
              className={cn(
                'mx-1.5 flex items-center gap-2.5 rounded-[7px] border-l-2 border-transparent px-3.5 py-2 text-sm font-medium text-muted-foreground transition-colors hover:bg-hover hover:text-foreground focus-visible:ring-[3px] focus-visible:ring-primary/25 focus-visible:outline-none',
                ativo && 'border-primary bg-primary/8 font-semibold text-foreground',
              )}
            >
              <Icone className="size-4 shrink-0" aria-hidden />
              {label}
            </Link>
          );
        })}
      </nav>
      {usuario ? (
        <div className="flex shrink-0 items-center gap-2.5 border-t border-border px-3.5 py-2.5">
          <span aria-hidden className="flex size-7 shrink-0 items-center justify-center rounded-full border border-border bg-card text-xs font-semibold text-primary-text">
            {usuario.nome.trim().charAt(0).toUpperCase()}
          </span>
          <div className="min-w-0">
            <p className="truncate text-xs font-semibold">{usuario.nome}</p>
            <p className="truncate text-[10px] text-muted-foreground">{usuario.email}</p>
          </div>
        </div>
      ) : null}
    </div>
  );
}
