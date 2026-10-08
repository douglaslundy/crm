import { AlertTriangle, Building2, Contact, CreditCard, FileText, Gauge, KeyRound, Landmark, LayoutDashboard, Package, Users, Webhook, Wrench, type LucideIcon } from 'lucide-react';
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
export const NAV_ITEMS: NavItem[] = [
  { href: '/dashboard', label: 'Início', icon: LayoutDashboard },
  { href: '/produtos', label: 'Produtos', icon: Package, papeis: ['PROPRIETARIO', 'ADMIN', 'FISCAL', 'VENDEDOR', 'LEITURA'] },
  { href: '/servicos', label: 'Serviços', icon: Wrench, papeis: ['PROPRIETARIO', 'ADMIN', 'FISCAL', 'VENDEDOR', 'LEITURA'] },
  { href: '/clientes', label: 'Clientes', icon: Contact, papeis: ['PROPRIETARIO', 'ADMIN', 'FISCAL', 'VENDEDOR', 'LEITURA'] },
  { href: '/notas', label: 'Notas', icon: FileText, papeis: ['PROPRIETARIO', 'ADMIN', 'FISCAL', 'VENDEDOR', 'LEITURA'] },
  { href: '/pendencias-fiscais', label: 'Pendências fiscais', icon: AlertTriangle, papeis: ['PROPRIETARIO', 'ADMIN', 'FISCAL', 'VENDEDOR', 'LEITURA'] },
  { href: '/configuracoes/fiscal', label: 'Fiscal', icon: Landmark, papeis: ['PROPRIETARIO', 'ADMIN', 'FISCAL'] },
  { href: '/assinatura/faturas', label: 'Assinatura', icon: CreditCard, papeis: ['PROPRIETARIO', 'ADMIN'] },
  { href: '/configuracoes/usuarios', label: 'Usuários', icon: Users, papeis: ['PROPRIETARIO', 'ADMIN'] },
  { href: '/configuracoes/api', label: 'Chaves de API', icon: KeyRound, papeis: ['PROPRIETARIO', 'ADMIN'] },
  { href: '/configuracoes/webhooks', label: 'Webhooks', icon: Webhook, papeis: ['PROPRIETARIO', 'ADMIN'] },
];

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
