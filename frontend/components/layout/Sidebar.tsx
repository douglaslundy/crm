'use client';

import { MenuDeNavegacao } from './MenuDeNavegacao';
import type { NavItem } from './nav-items';

export function Sidebar({ itens }: { itens: NavItem[] }) {
  return (
    <aside className="hidden w-[230px] shrink-0 border-r border-border bg-surface md:block">
      <div className="sticky top-0 h-dvh">
        <MenuDeNavegacao itens={itens} rotulo="Navegação principal" />
      </div>
    </aside>
  );
}
