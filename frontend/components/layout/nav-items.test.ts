import { LayoutDashboard } from 'lucide-react';
import { describe, expect, it } from 'vitest';
import { estaAtivo, itensVisiveis, type NavItem } from './nav-items';

const painel: NavItem = { href: '/admin', label: 'Painel', icon: LayoutDashboard, exato: true };
const planos: NavItem = { href: '/admin/planos', label: 'Planos', icon: LayoutDashboard };
const restrito: NavItem = { href: '/x', label: 'X', icon: LayoutDashboard, papeis: ['ADMIN'] };

describe('nav-items', () => {
  it('item exato só fica ativo na própria rota', () => {
    expect(estaAtivo(painel, '/admin')).toBe(true);
    expect(estaAtivo(painel, '/admin/planos')).toBe(false);
    expect(estaAtivo(planos, '/admin/planos/123')).toBe(true);
  });

  it('item com papéis só aparece para esses papéis', () => {
    expect(itensVisiveis([planos, restrito], 'ADMIN')).toEqual([planos, restrito]);
    expect(itensVisiveis([planos, restrito], 'VENDEDOR')).toEqual([planos]);
    expect(itensVisiveis([planos, restrito], undefined)).toEqual([planos]);
  });

  describe('item de módulo', () => {
    const crm: NavItem = { href: '/crm/negocios', label: 'CRM', icon: LayoutDashboard, papeis: ['VENDEDOR'], modulo: 'CRM' };

    it('esconde item de módulo ausente do plano', () => {
      expect(itensVisiveis([crm], 'VENDEDOR', ['FISCAL_NFE'])).toEqual([]);
    });

    it('mostra com o módulo no plano e papel permitido', () => {
      expect(itensVisiveis([crm], 'VENDEDOR', ['CRM'])).toEqual([crm]);
    });

    it('esconde enquanto a lista de módulos não carregou', () => {
      expect(itensVisiveis([crm], 'VENDEDOR', undefined)).toEqual([]);
    });
  });
});
