'use client';

import { useMutation, useQueryClient } from '@tanstack/react-query';
import Link from 'next/link';
import { useState } from 'react';
import { Banner } from '@/components/ui/banner';
import { Button, buttonVariants } from '@/components/ui/button';
import { QUERY_KEY_ASSINATURA } from '@/features/assinatura/hooks/useAssinatura';
import type { Plano } from '@/features/assinatura/types';
import { ApiError } from '@/lib/api';
import { formatarCentavos, formatarData } from '@/lib/formatos';
import { useEnvioUnico } from '@/lib/useEnvioUnico';
import { cobrancaApi, QUERY_KEY_FATURAS } from '../api';
import { ROTULO_CICLO, ROTULO_METODO, type CheckoutResultado, type CicloDeCobranca, type MetodoDePagamento } from '../types';
import { PagamentoComCartao } from './PagamentoComCartao';

function precoDoCiclo(plano: Plano, ciclo: CicloDeCobranca): number | null {
  return ciclo === 'ANUAL' ? plano.preco_anual_centavos : plano.preco_mensal_centavos;
}

/** Escolha de ciclo e método e pagamento de um plano. Monte com `key={plano.id}` para reiniciar ao trocar de plano. */
export function Checkout({ plano, regularizar }: { plano: Plano; regularizar?: { ciclo: CicloDeCobranca } }) {
  const queryClient = useQueryClient();
  // Regularização: assinatura ativa com pagamento em atraso. Só boleto, no ciclo que o cliente já tem.
  const [ciclo, setCiclo] = useState<CicloDeCobranca>(regularizar?.ciclo ?? 'MENSAL');
  const [metodo, setMetodo] = useState<MetodoDePagamento>(regularizar ? 'BOLETO' : 'CARTAO');
  const [resultado, setResultado] = useState<CheckoutResultado | null>(null);
  const [erro, setErro] = useState<string | null>(null);
  const envioUnico = useEnvioUnico();
  const preco = precoDoCiclo(plano, ciclo);

  const assinar = useMutation({
    mutationFn: (token: string | null) => cobrancaApi.checkout({ plano_id: plano.id, ciclo, metodo, token_do_cartao: token }),
    onSuccess: ({ data }) => {
      setErro(null);
      setResultado(data);
      void queryClient.invalidateQueries({ queryKey: QUERY_KEY_ASSINATURA });
      void queryClient.invalidateQueries({ queryKey: QUERY_KEY_FATURAS });
    },
    onError: (e) => setErro(e instanceof ApiError ? e.primeiraMensagem() : 'Não foi possível concluir a assinatura. Tente novamente.'),
  });

  if (resultado !== null) {
    const { cobranca } = resultado;
    return (
      <div className="space-y-3">
        {cobranca.metodo === 'BOLETO' ? (
          <>
            <Banner tom="sucesso">Boleto gerado. Vence em {formatarData(cobranca.vence_em)}; o plano é ativado quando o pagamento for confirmado.</Banner>
            {cobranca.boleto_url ? <a href={cobranca.boleto_url} target="_blank" rel="noopener noreferrer" className={buttonVariants()}>Abrir boleto</a> : null}
            {cobranca.boleto_linha_digitavel ? (
              <p className="text-sm">Linha digitável: <span className="font-mono">{cobranca.boleto_linha_digitavel}</span></p>
            ) : null}
          </>
        ) : (
          <Banner tom="sucesso">Assinatura enviada, aguardando confirmação do pagamento.</Banner>
        )}
        <Link href="/assinatura/faturas" className="text-sm underline">Ver minhas faturas</Link>
      </div>
    );
  }

  return (
    <div className="space-y-4">
      {regularizar ? null : (
      <>
      <fieldset className="space-y-1">
        <legend className="font-medium">Ciclo de cobrança</legend>
        <div className="flex gap-4">
          {(['MENSAL', 'ANUAL'] as const).map((c) => (
            <label key={c} className="flex items-center gap-2">
              <input type="radio" name="ciclo" checked={ciclo === c} disabled={precoDoCiclo(plano, c) === null} onChange={() => setCiclo(c)} />
              {ROTULO_CICLO[c]}
            </label>
          ))}
        </div>
      </fieldset>

      <fieldset className="space-y-1">
        <legend className="font-medium">Forma de pagamento</legend>
        <div className="flex gap-4">
          {(['CARTAO', 'BOLETO'] as const).map((m) => (
            <label key={m} className="flex items-center gap-2">
              <input type="radio" name="metodo" checked={metodo === m} onChange={() => setMetodo(m)} />
              {ROTULO_METODO[m]}
            </label>
          ))}
        </div>
      </fieldset>
      </>
      )}

      {preco === null ? null : <p className="font-medium">Total: {formatarCentavos(preco)} ({ROTULO_CICLO[ciclo].toLowerCase()})</p>}
      {erro ? <Banner tom="perigo">{erro}</Banner> : null}

      {preco === null ? null : metodo === 'CARTAO' ? (
        <PagamentoComCartao
          key={ciclo}
          valorCentavos={preco}
          aoEnviar={async (token) => {
            setErro(null);
            await assinar.mutateAsync(token);
          }}
        />
      ) : (
        <form onSubmit={envioUnico(async () => { setErro(null); await assinar.mutateAsync(null).catch(() => undefined); })}>
          <Button type="submit" disabled={assinar.isPending}>{regularizar ? 'Gerar boleto de regularização' : 'Gerar boleto'}</Button>
        </form>
      )}
    </div>
  );
}
