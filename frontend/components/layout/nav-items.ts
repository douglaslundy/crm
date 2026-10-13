import { AlertTriangle, BarChart3, Briefcase, CalendarDays, Building2, Contact, CreditCard, FileText, Gauge, KeyRound, Landmark, LayoutDashboard, Package, Settings2, Users, Webhook, Wrench, type LucideIcon } from 'lucide-react';
import type { Modulo } from '@/features/assinatura/types';
import type { Papel } from '@/features/auth/types';

export interface NavItem {
  href: string;
  label: string;
  icon: LucideIcon;
  /** Ativo só na própria rota (ex.: /admin não fica ativo em /admin/planos). */
  exato?: boolean;
  /** Sem papeis = visível para todos da área. */
  papeis?: Papel[];
  /** Só aparece se o plano da empresa tiver o módulo. */
  modulo?: Modulo;
  /** Contador exibido ao lado do rótulo. */
  emblema?: 'crm-atrasadas';
}

/** Área da empresa. Cada fase acrescenta aqui os itens do seu módulo. */
export const NAV_ITEMS: NavItem[] = [
  { href: '/dashboard', label: 'Início', icon: LayoutDashboard },
  { href: '/produtos', label: 'Produtos', icon: Package, papeis: ['PROPRIETARIO', 'ADMIN', 'FISCAL', 'VENDEDOR', 'LEITURA'] },
  { href: '/servicos', label: 'Serviços', icon: Wrench, papeis: ['PROPRIETARIO', 'ADMIN', 'FISCAL', 'VENDEDOR', 'LEITURA'] },
  { href: '/clientes', label: 'Clientes', icon: Contact, papeis: ['PROPRIETARIO', 'ADMIN', 'FISCAL', 'VENDEDOR', 'LEITURA'] },
  { href: '/crm/negocios', label: 'CRM', icon: Briefcase, papeis: ['PROPRIETARIO', 'ADMIN', 'VENDEDOR', 'LEITURA'], modulo: 'CRM' },
  { href: '/crm/agenda', label: 'Agenda', icon: CalendarDays, papeis: ['PROPRIETARIO', 'ADMIN', 'VENDEDOR', 'LEITURA'], modulo: 'CRM', emblema: 'crm-atrasadas' },
  { href: '/crm/relatorios', label: 'Relatórios', icon: BarChart3, papeis: ['PROPRIETARIO', 'ADMIN', 'VENDEDOR', 'LEITURA'], modulo: 'CRM' },
  { href: '/configuracoes/crm', label: 'Config. do CRM', icon: Settings2, papeis: ['PROPRIETARIO', 'ADMIN'], modulo: 'CRM' },
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

export function itensVisiveis(itens: NavItem[], papel: Papel | undefined, modulos?: Modulo[]): NavItem[] {
  return itens.filter((item) => {
    if (item.papeis && (papel === undefined || !item.papeis.includes(papel))) return false;
    if (item.modulo && !(modulos ?? []).includes(item.modulo)) return false;
    return true;
  });
}
