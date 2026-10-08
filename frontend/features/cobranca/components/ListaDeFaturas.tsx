'use client';

import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import Link from 'next/link';
import { useEffect, useRef, useState } from 'react';
import { toast } from 'sonner';
import { Banner } from '@/components/ui/banner';
import { Button, buttonVariants } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Pilula, type TomDaPilula } from '@/components/ui/pilula';
import { QUERY_KEY_ASSINATURA, useAssinatura } from '@/features/assinatura/hooks/useAssinatura';
import { useUsuario } from '@/features/auth/hooks/useUsuario';
import { ApiError } from '@/lib/api';
import { formatarCentavos, formatarData } from '@/lib/formatos';
import { cobrancaApi, QUERY_KEY_FATURAS } from '../api';
import { ROTULO_METODO, ROTULO_STATUS_COBRANCA, type Cobranca, type StatusDaCobranca } from '../types';
import { PainelDePagamento } from './PainelDePagamento';

const PAPEIS_QUE_VEEM = ['PROPRIETARIO', 'ADMIN'];
const TOM: Record<StatusDaCobranca, TomDaPilula> = { PENDENTE: 'alerta', PAGA: 'sucesso', VENCIDA: 'perigo', CANCELADA: 'neutro' };
const INTERVALO_DO_PIX_MS = 3000;

const emAberto = (f: Cobranca) => f.status === 'PENDENTE' || f.status === 'VENCIDA';
const aguardandoPix = (f: Cobranca) => f.metodo === 'PIX' && emAberto(f) && f.pix_expira_em !== null && new Date(f.pix_expira_em).getTime() > Date.now();

const cartaoEmAnalise = (f: Cobranca) => f.metodo === 'CARTAO' && emAberto(f);

/** 'AAAA-MM-DD' → 'outubro de 2026'. */
function mesPorExtenso(iso: string): string {
  return new Date(`${iso.slice(0, 10)}T12:00:00`).toLocaleDateString('pt-BR', { month: 'long', year: 'numeric' });
}

function Descricao({ fatura: f }: { fatura: Cobranca }) {
  if (f.tipo === 'EXCEDENTE') {
    const conta =
      f.mes_referencia !== null && f.documentos_excedentes !== null && f.valor_unitario_centavos !== null
        ? `Documentos acima do limite em ${mesPorExtenso(f.mes_referencia)}: ${f.documentos_excedentes} × ${formatarCentavos(f.valor_unitario_centavos)}`
        : 'Documentos acima do limite do plano';
    return (
      <span>
        {conta} · vence em {formatarData(f.vence_em)} · {formatarCentavos(f.valor_centavos)}
        {f.pago_em ? ` · paga em ${formatarData(f.pago_em)}` : ''}
      </span>
    );
  }
  return (
    <span>
      Vence em {formatarData(f.vence_em)} · {f.metodo ? ROTULO_METODO[f.metodo] : '—'} · {formatarCentavos(f.valor_centavos)}
      {f.pago_em ? ` · paga em ${formatarData(f.pago_em)}` : ''}
    </span>
  );
}

export function ListaDeFaturas() {
  const queryClient = useQueryClient();
  const { data: usuario } = useUsuario();
  const assinatura = useAssinatura();
  const [confirmando, setConfirmando] = useState(false);
  const [pagando, setPagando] = useState<string | null>(null);
  const abertasAntes = useRef<Set<string>>(new Set());
  const permitido = Boolean(usuario && PAPEIS_QUE_VEEM.includes(usuario.papel));

  const faturas = useQuery({
    queryKey: QUERY_KEY_FATURAS,
    queryFn: async () => (await cobrancaApi.faturas()).data,
    enabled: permitido,
    // Com PIX gerado a página acompanha o pagamento sozinha, sem o cliente recarregar.
    refetchInterval: (query) => (query.state.data?.some((f) => aguardandoPix(f) || (f.id === pagando && cartaoEmAnalise(f))) ? INTERVALO_DO_PIX_MS : false),
  });

  // Uma cobrança que estava em aberto e agora está paga mudou a situação da conta (por exemplo, saiu da suspensão).
  useEffect(() => {
    if (!faturas.data) return;
    const abertas = new Set(faturas.data.filter(emAberto).map((f) => f.id));
    const quitou = faturas.data.some((f) => f.status === 'PAGA' && abertasAntes.current.has(f.id));
    abertasAntes.current = abertas;
    if (quitou) void queryClient.invalidateQueries({ queryKey: QUERY_KEY_ASSINATURA });
  }, [faturas.data, queryClient]);

  const cancelar = useMutation({
    mutationFn: () => cobrancaApi.cancelar(),
    onSuccess: () => {
      toast.success('Assinatura cancelada. O acesso continua até o fim do período pago.');
      setConfirmando(false);
      void queryClient.invalidateQueries({ queryKey: QUERY_KEY_ASSINATURA });
      void queryClient.invalidateQueries({ queryKey: QUERY_KEY_FATURAS });
    },
    onError: (e) => {
      toast.error(e instanceof ApiError ? e.message : 'Não foi possível cancelar. Tente novamente.');
      setConfirmando(false);
    },
  });

  if (usuario && !permitido) return <Banner tom="info">Só o proprietário ou um administrador da empresa vê as faturas.</Banner>;
  if (faturas.isError) return <Banner tom="perigo">Não foi possível carregar as faturas. Recarregue a página.</Banner>;
  if (faturas.isPending) return <p className="text-muted-foreground">Carregando...</p>;

  const atual = assinatura.data?.assinatura ?? null;
  const podeCancelar = atual !== null && atual.situacao !== 'CANCELADA';

  return (
    <div className="space-y-4">
      <Card>
        <CardHeader><CardTitle>Faturas</CardTitle></CardHeader>
        <CardContent>
          {faturas.data.length === 0 ? (
            <p className="text-muted-foreground">Nenhuma fatura ainda.</p>
          ) : (
            <ul className="divide-y">
              {faturas.data.map((f) => (
                <li key={f.id} className="space-y-2 py-3 text-sm">
                  <div className="flex flex-wrap items-center justify-between gap-2">
                    <Descricao fatura={f} />
                    <span className="flex items-center gap-2">
                      <Pilula tom={TOM[f.status]}>{ROTULO_STATUS_COBRANCA[f.status]}</Pilula>
                      {f.boleto_url && emAberto(f) ? (
                        <a href={f.boleto_url} target="_blank" rel="noopener noreferrer" className={buttonVariants({ variant: 'outline' })}>Abrir boleto</a>
                      ) : null}
                      {f.tipo === 'EXCEDENTE' && emAberto(f) ? (
                        <Button type="button" variant="outline" onClick={() => setPagando(pagando === f.id ? null : f.id)}>Pagar</Button>
                      ) : null}
                    </span>
                  </div>
                  {pagando === f.id ? <PainelDePagamento cobranca={f} /> : null}
                </li>
              ))}
            </ul>
          )}
        </CardContent>
      </Card>

      <div className="flex flex-wrap items-center gap-2">
        <Link href="/assinatura/planos" className={buttonVariants({ variant: 'outline' })}>Ver planos</Link>
        {podeCancelar ? (
          confirmando ? (
            <Button type="button" variant="destructive" disabled={cancelar.isPending} onClick={() => cancelar.mutate()}>Confirmar cancelamento</Button>
          ) : (
            <Button type="button" variant="outline" onClick={() => setConfirmando(true)}>Cancelar assinatura</Button>
          )
        ) : null}
      </div>
    </div>
  );
}
