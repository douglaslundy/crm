'use client';

import { useEffect, useState } from 'react';
import { Banner } from '@/components/ui/banner';
import { chavePublicaDoMercadoPago, montarCartao } from '@/lib/mercadopago';

const ID_DO_CONTAINER = 'brick-cartao';

/** Formulário de cartão do Mercado Pago (Card Payment Brick). O servidor só recebe o token, nunca o número do cartão. */
export function PagamentoComCartao({ valorCentavos, aoEnviar }: { valorCentavos: number; aoEnviar: (token: string) => Promise<void> }) {
  const chave = chavePublicaDoMercadoPago();
  const [erro, setErro] = useState<string | null>(null);

  useEffect(() => {
    if (chave === null) return;
    let desmontar: (() => void) | undefined;
    let cancelado = false;
    montarCartao({ chavePublica: chave, valorReais: valorCentavos / 100, idDoContainer: ID_DO_CONTAINER, aoEnviar, aoErro: setErro })
      .then((fn) => {
        if (cancelado) fn();
        else desmontar = fn;
      })
      .catch(() => setErro('Não foi possível carregar o pagamento por cartão. Tente o boleto ou recarregue a página.'));
    return () => {
      cancelado = true;
      desmontar?.();
    };
    // `aoEnviar` muda a cada render; o brick é montado uma vez por chave e valor.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [chave, valorCentavos]);

  if (chave === null) return <Banner tom="info">Pagamento por cartão indisponível nesta instalação.</Banner>;

  return (
    <div className="space-y-2">
      <div id={ID_DO_CONTAINER} />
      {erro ? <Banner tom="perigo">{erro}</Banner> : null}
    </div>
  );
}
