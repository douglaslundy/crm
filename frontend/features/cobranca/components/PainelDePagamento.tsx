'use client';

import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { Banner } from '@/components/ui/banner';
import { Button } from '@/components/ui/button';
import { QUERY_KEY_ASSINATURA } from '@/features/assinatura/hooks/useAssinatura';
import { ApiError } from '@/lib/api';
import { formatarCentavos } from '@/lib/formatos';
import { useEnvioUnico } from '@/lib/useEnvioUnico';
import { cobrancaApi, QUERY_KEY_FATURAS } from '../api';
import { ROTULO_METODO, type Cobranca } from '../types';
import { PagamentoComCartao } from './PagamentoComCartao';

type FormaDePagar = 'PIX' | 'CARTAO';

/**
 * Paga uma cobrança avulsa por PIX (QR code e copia e cola) ou cartão. O valor é o da cobrança: o servidor ignora
 * qualquer outro. `cobranca` deve vir da lista de faturas, que se atualiza sozinha enquanto o PIX espera o pagamento.
 */
export function PainelDePagamento({ cobranca }: { cobranca: Cobranca }) {
  const queryClient = useQueryClient();
  const [forma, setForma] = useState<FormaDePagar>('PIX');
  const [resultado, setResultado] = useState<Cobranca | null>(null);
  const [erro, setErro] = useState<string | null>(null);
  const [copiado, setCopiado] = useState(false);
  const [agora] = useState(() => Date.now());
  const envioUnico = useEnvioUnico();

  const pagar = useMutation({
    mutationFn: (token: string | null) => cobrancaApi.pagar(cobranca.id, { metodo: forma, token_do_cartao: token }),
    onSuccess: ({ data }) => {
      setErro(null);
      setResultado(data);
      void queryClient.invalidateQueries({ queryKey: QUERY_KEY_FATURAS });
      if (data.status === 'PAGA') void queryClient.invalidateQueries({ queryKey: QUERY_KEY_ASSINATURA });
    },
    onError: (e) => setErro(e instanceof ApiError ? e.primeiraMensagem() : 'Não foi possível concluir o pagamento. Tente novamente.'),
  });

  if (cobranca.status === 'PAGA' || resultado?.status === 'PAGA') return <Banner tom="sucesso">Pagamento confirmado. Obrigado!</Banner>;

  const atual = resultado ?? cobranca;
  const pixValido =
    atual.metodo === 'PIX' && atual.pix_qr_code !== null && (atual.pix_expira_em === null || new Date(atual.pix_expira_em).getTime() > agora);
  const copiar = async (codigo: string) => {
    await navigator.clipboard.writeText(codigo);
    setCopiado(true);
  };

  return (
    <div className="space-y-3 rounded-lg border p-3">
      <p className="font-medium">Total a pagar: {formatarCentavos(cobranca.valor_centavos)}</p>
      <fieldset className="space-y-1">
        <legend className="sr-only">Forma de pagamento</legend>
        <div className="flex gap-4">
          {(['PIX', 'CARTAO'] as const).map((f) => (
            <label key={f} className="flex items-center gap-2">
              <input type="radio" name={`forma-${cobranca.id}`} checked={forma === f} onChange={() => setForma(f)} />
              {ROTULO_METODO[f]}
            </label>
          ))}
        </div>
      </fieldset>

      {erro ? <Banner tom="perigo">{erro}</Banner> : null}

      {forma === 'CARTAO' ? (
        <PagamentoComCartao
          valorCentavos={cobranca.valor_centavos}
          aoEnviar={async (token) => {
            setErro(null);
            await pagar.mutateAsync(token);
          }}
        />
      ) : pixValido && atual.pix_qr_code !== null ? (
        <div className="space-y-2">
          {atual.pix_qr_code_base64 ? (
            // eslint-disable-next-line @next/next/no-img-element -- imagem em data URI gerada pelo gateway
            <img alt="QR code do PIX" src={`data:image/png;base64,${atual.pix_qr_code_base64}`} className="size-48" />
          ) : null}
          <p className="text-sm">Ou copie o código:</p>
          <p className="font-mono text-xs break-all">{atual.pix_qr_code}</p>
          <Button type="button" variant="outline" onClick={() => void copiar(atual.pix_qr_code ?? '')}>Copiar código</Button>
          {copiado ? <span className="ml-2 text-sm text-success">Código copiado.</span> : null}
          {atual.pix_expira_em ? <p className="text-sm text-muted-foreground">Válido até {new Date(atual.pix_expira_em).toLocaleString('pt-BR', { dateStyle: 'short', timeStyle: 'short' })}. Esta tela atualiza sozinha quando o pagamento for confirmado.</p> : null}
        </div>
      ) : (
        <form onSubmit={envioUnico(async () => { setErro(null); await pagar.mutateAsync(null).catch(() => undefined); })}>
          <Button type="submit" disabled={pagar.isPending}>Gerar PIX</Button>
        </form>
      )}
    </div>
  );
}
