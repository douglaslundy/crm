import { Building2, Gauge, LayoutDashboard, Package, type LucideIcon } from 'lucide-react';
import type { Papel } from '@/features/auth/types';

export interface NavItem {
  href: string;
  label: string;
  icon: LucideIcon;
  /** Ativo só na própria rota (ex.: /admin não fica ativo em /admin/planos). */
  exato?: boolean;
  /** Sem papeis = visível para todos da área. */
  papeis?: Papel[];
}

/** Área da empresa. Cada fase acrescenta aqui os itens do seu módulo. */
export const NAV_ITEMS: NavItem[] = [{ href: '/dashboard', label: 'Início', icon: LayoutDashboard }];

export const NAV_ITEMS_ADMIN: NavItem[] = [
  { href: '/admin', label: 'Painel', icon: Gauge, exato: true },
  { href: '/admin/planos', label: 'Planos', icon: Package },
  { href: '/admin/empresas', label: 'Empresas', icon: Building2 },
];

export function estaAtivo(item: NavItem, pathname: string): boolean {
  return item.exato ? pathname === item.href : pathname.startsWith(item.href);
}

export function itensVisiveis(itens: NavItem[], papel: Papel | undefined): NavItem[] {
  return itens.filter((item) => !item.papeis || (papel !== undefined && item.papeis.includes(papel)));
}
