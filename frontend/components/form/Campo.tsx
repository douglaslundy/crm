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
  obrigatorio?: boolean;
  children: (a11y: PropsAcessiveis) => ReactNode;
}

/** Label + controle + dica + erro, com o erro anunciado pelo leitor de tela. */
export function Campo({ id, label, erro, dica, obrigatorio, children }: Props) {
  const idDica = `${id}-dica`;
  const idErro = `${id}-erro`;
  const descritores = [dica ? idDica : null, erro ? idErro : null].filter(Boolean).join(' ');

  return (
    <div className="space-y-1">
      <Label htmlFor={id}>
        {label}
        {obrigatorio ? (
          <>
            <span aria-hidden className="ml-0.5 text-danger">*</span>
            <span className="sr-only"> (obrigatório)</span>
          </>
        ) : null}
      </Label>
      {children({ id, 'aria-invalid': Boolean(erro), 'aria-describedby': descritores || undefined })}
      {dica ? <p id={idDica} className="text-[11px] text-muted-foreground">{dica}</p> : null}
      {erro ? <p id={idErro} className="text-xs text-danger">{erro}</p> : null}
    </div>
  );
}
