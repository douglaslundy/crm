'use client';

import { Menu } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { Button } from '@/components/ui/button';
import { MenuDeNavegacao } from './MenuDeNavegacao';
import type { NavItem } from './nav-items';

/** Gaveta lateral do celular: botão, painel com véu, Esc e fechar ao navegar. */
export function MenuMobile({ itens }: { itens: NavItem[] }) {
  const [aberto, setAberto] = useState(false);
  const botao = useRef<HTMLButtonElement>(null);
  const painel = useRef<HTMLDivElement>(null);

  function fechar() {
    setAberto(false);
    botao.current?.focus();
  }

  useEffect(() => {
    if (!aberto) return;
    painel.current?.querySelector<HTMLElement>('a')?.focus();
    function aoTeclar(e: KeyboardEvent) {
      if (e.key === 'Escape') {
        setAberto(false);
        botao.current?.focus();
      }
    }
    document.addEventListener('keydown', aoTeclar);
    return () => document.removeEventListener('keydown', aoTeclar);
  }, [aberto]);

  return (
    <div className="md:hidden">
      <Button ref={botao} variant="ghost" size="icon" aria-label="Abrir menu" aria-expanded={aberto} onClick={() => setAberto(true)}>
        <Menu className="size-5" />
      </Button>
      {aberto ? (
        <>
          <div data-testid="veu-do-menu" className="fixed inset-0 z-[99] bg-black/50" onClick={fechar} />
          <div ref={painel} role="dialog" aria-modal="true" aria-label="Menu" className="fixed inset-y-0 left-0 z-[100] w-[230px] border-r border-border bg-surface">
            <MenuDeNavegacao itens={itens} rotulo="Navegação do menu" aoNavegar={() => setAberto(false)} />
          </div>
        </>
      ) : null}
    </div>
  );
}
