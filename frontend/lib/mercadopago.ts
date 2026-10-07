/** Fronteira com o SDK do Mercado Pago (Card Payment Brick). Nunca foi exercitada contra o Mercado Pago real. */

interface BrickDoCartao {
  unmount(): void;
}

interface OpcoesDoBrick {
  initialization: { amount: number };
  callbacks: {
    onReady?: () => void;
    onSubmit: (dados: { token: string }) => Promise<void>;
    onError?: (erro: unknown) => void;
  };
}

interface SdkDoMercadoPago {
  bricks(): { create(tipo: 'cardPayment', idDoContainer: string, opcoes: OpcoesDoBrick): Promise<BrickDoCartao> };
}

declare global {
  interface Window {
    MercadoPago?: new (chavePublica: string, opcoes: { locale: string }) => SdkDoMercadoPago;
  }
}

const URL_DO_SDK = 'https://sdk.mercadopago.com/js/v2';

/** Chave pública de teste ou de produção; sem ela o cartão fica indisponível (o boleto segue). */
export function chavePublicaDoMercadoPago(): string | null {
  const chave = process.env.NEXT_PUBLIC_MERCADOPAGO_PUBLIC_KEY;
  return chave !== undefined && chave !== '' ? chave : null;
}

function carregarSdk(): Promise<void> {
  if (window.MercadoPago !== undefined) return Promise.resolve();
  return new Promise((resolve, reject) => {
    const existente = document.querySelector<HTMLScriptElement>(`script[src="${URL_DO_SDK}"]`);
    const script = existente ?? document.createElement('script');
    script.addEventListener('load', () => resolve(), { once: true });
    script.addEventListener('error', () => reject(new Error('Não foi possível carregar o Mercado Pago.')), { once: true });
    if (existente === null) {
      script.src = URL_DO_SDK;
      script.async = true;
      document.head.appendChild(script);
    }
  });
}

interface OpcoesDoCartao {
  chavePublica: string;
  valorReais: number;
  idDoContainer: string;
  aoEnviar: (token: string) => Promise<void>;
  aoErro: (mensagem: string) => void;
}

/** Monta o formulário de cartão no container e devolve a função que o desmonta. */
export async function montarCartao(opcoes: OpcoesDoCartao): Promise<() => void> {
  await carregarSdk();
  const Sdk = window.MercadoPago;
  if (Sdk === undefined) throw new Error('O SDK do Mercado Pago não está disponível.');

  const brick = await new Sdk(opcoes.chavePublica, { locale: 'pt-BR' }).bricks().create('cardPayment', opcoes.idDoContainer, {
    initialization: { amount: opcoes.valorReais },
    callbacks: {
      onSubmit: async (dados) => opcoes.aoEnviar(dados.token),
      onError: () => opcoes.aoErro('Não foi possível ler os dados do cartão. Confira e tente de novo.'),
    },
  });

  return () => brick.unmount();
}
