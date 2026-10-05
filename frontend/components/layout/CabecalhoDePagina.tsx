import type { ReactNode } from 'react';

/** Título da página (h1), subtítulo e ações à direita; empilha no celular. */
export function CabecalhoDePagina({ titulo, subtitulo, acoes }: { titulo: string; subtitulo?: string; acoes?: ReactNode }) {
  return (
    <div className="mb-6 flex flex-wrap items-start justify-between gap-3">
      <div className="min-w-0">
        <h1 className="font-display text-[28px] leading-tight font-extrabold">{titulo}</h1>
        {subtitulo ? <p className="mt-1 text-sm text-muted-foreground">{subtitulo}</p> : null}
      </div>
      {acoes ? <div className="flex flex-wrap items-center gap-2">{acoes}</div> : null}
    </div>
  );
}
