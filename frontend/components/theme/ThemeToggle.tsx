'use client';

import { Moon, Sun } from 'lucide-react';
import { useTheme } from 'next-themes';
import { useSyncExternalStore } from 'react';
import { Button } from '@/components/ui/button';

export function ThemeToggle() {
  const { resolvedTheme, setTheme } = useTheme();
  // No servidor o tema é desconhecido: renderizar o ícone só depois de hidratar evita divergência de HTML.
  const hidratado = useSyncExternalStore(() => () => {}, () => true, () => false);
  const escuro = hidratado && resolvedTheme === 'dark';
  const rotulo = escuro ? 'Ativar tema claro' : 'Ativar tema escuro';

  return (
    <Button
      variant="ghost"
      size="icon"
      aria-label={rotulo}
      title={rotulo}
      onClick={() => setTheme(escuro ? 'light' : 'dark')}
    >
      {escuro ? <Sun className="size-5" /> : <Moon className="size-5" />}
    </Button>
  );
}
