'use client';

import type { ReactNode } from 'react';
import { BottomNav } from './BottomNav';
import { NAV_ITEMS, type NavItem } from './nav-items';
import { Sidebar } from './Sidebar';
import { Topbar } from './Topbar';

export function AppShell({ itens = NAV_ITEMS, children }: { itens?: NavItem[]; children: ReactNode }) {
  return (
    <div className="flex min-h-dvh">
      <Sidebar itens={itens} />
      <div className="flex min-w-0 flex-1 flex-col">
        <Topbar />
        <main className="flex-1 p-4 pb-24 md:p-6 md:pb-6">{children}</main>
      </div>
      <BottomNav itens={itens} />
    </div>
  );
}
