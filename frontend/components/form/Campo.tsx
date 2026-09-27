import type { ReactNode } from 'react';
import { Label } from '@/components/ui/label';

export interface PropsAcessiveis {
  id: string;
  'aria-invalid': boolean;
  'aria-describedby'?: string;
}

interface Props {
  id: string;
  label: string;
  erro?: string;
  dica?: string;
  children: (a11y: PropsAcessiveis) => ReactNode;
}

/** Label + controle + dica + erro, com o erro anunciado pelo leitor de tela. */
export function Campo({ id, label, erro, dica, children }: Props) {
  const idDica = `${id}-dica`;
  const idErro = `${id}-erro`;
  const descritores = [dica ? idDica : null, erro ? idErro : null].filter(Boolean).join(' ');

  return (
    <div className="space-y-2">
      <Label htmlFor={id}>{label}</Label>
      {children({ id, 'aria-invalid': Boolean(erro), 'aria-describedby': descritores || undefined })}
      {dica ? <p id={idDica} className="text-xs text-muted-foreground">{dica}</p> : null}
      {erro ? <p id={idErro} className="text-sm text-danger">{erro}</p> : null}
    </div>
  );
}
