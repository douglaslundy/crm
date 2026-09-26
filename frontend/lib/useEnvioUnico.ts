import { useCallback, useRef, type FormEvent } from 'react';

/**
 * Impede envio duplicado de formulário. A trava é um ref checado de forma
 * síncrona no próprio evento: o estado do React (isSubmitting/isPending) só
 * muda no próximo render, tarde demais para dois cliques no mesmo tick.
 */
export function useEnvioUnico() {
  const emAndamento = useRef(false);

  return useCallback(
    (acao: () => Promise<unknown>) => (evento: FormEvent<HTMLFormElement>) => {
      evento.preventDefault();
      if (emAndamento.current) return;
      emAndamento.current = true;
      void acao().finally(() => {
        emAndamento.current = false;
      });
    },
    [],
  );
}
