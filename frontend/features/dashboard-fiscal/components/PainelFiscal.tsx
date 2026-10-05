'use client';

import Link from 'next/link';
import { Banner } from '@/components/ui/banner';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { StatCard, type TomDoStat } from '@/components/ui/stat-card';
import { StatusDaNota } from '@/features/notas/components/StatusDaNota';
import { ROTULO_MODELO } from '@/features/notas/types';
import { formatarCentavos } from '@/lib/formatos';
import { faixaDoCertificado, rotuloDoMes, type FaixaDoCertificado } from '../helpers';
import { useDashboardFiscal } from '../hooks/useDashboardFiscal';
import type { DashboardFiscal } from '../types';
import { GraficoDeFaturamento } from './GraficoDeFaturamento';

const TOM_DO_CERTIFICADO: Record<FaixaDoCertificado, TomDoStat> = { ok: 'sucesso', alerta: 'primario', vencido: 'perigo', 'sem-validade': 'perigo' };

function textoDoCertificado(dias: number | null): string {
  if (dias === null) return 'Sem validade';
  if (dias < 0) return `Vencido há ${Math.abs(dias)} dias`;
  return `${dias} dias`;
}

function Certificado({ certificado }: { certificado: DashboardFiscal['certificado'] }) {
  const faixa = faixaDoCertificado(certificado.dias_restantes);
  return (
    <section aria-label="Certificado digital" data-faixa={faixa}>
      <StatCard
        titulo="Certificado digital"
        valor={textoDoCertificado(certificado.dias_restantes)}
        legenda={certificado.validade ? `Válido até ${certificado.validade.split('-').reverse().join('/')}` : 'Envie um certificado em Configurações > Fiscal'}
        tom={TOM_DO_CERTIFICADO[faixa]}
      />
    </section>
  );
}

function AvisosDeContingencia({ contingencias }: { contingencias: DashboardFiscal['contingencias'] }) {
  const plural = (n: number) => (n === 1 ? 'nota' : 'notas');
  return (
    <>
      {contingencias.vencidas > 0 ? (
        <Banner tom="perigo" rotulo="Prazo das contingências">
          {contingencias.vencidas} {plural(contingencias.vencidas)} com prazo vencido: retransmita agora ou confirme o prazo da sua UF.
        </Banner>
      ) : null}
      {contingencias.em_alerta > 0 ? (
        <Banner tom="alerta" rotulo="Prazo das contingências">
          {contingencias.em_alerta} {plural(contingencias.em_alerta)} perto do prazo de transmissão.
        </Banner>
      ) : null}
    </>
  );
}

export function PainelFiscal() {
  const { data, isPending, isError } = useDashboardFiscal();

  if (isPending) return <p className="text-muted-foreground">Carregando o painel fiscal...</p>;
  if (isError || !data) return <Banner tom="perigo">Não foi possível carregar o painel fiscal.</Banner>;

  const totalDeNotas = data.mes.notas.reduce((soma, n) => soma + n.quantidade, 0);

  return (
    <div className="space-y-4">
      {data.ambiente === 'PRODUCAO' ? (
        <Banner tom="perigo">Ambiente de PRODUÇÃO: as notas emitidas têm valor fiscal.</Banner>
      ) : (
        <Banner tom="info">Ambiente de HOMOLOGAÇÃO: as notas emitidas não têm valor fiscal.</Banner>
      )}

      <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <section aria-label="Notas do mês">
          <StatCard titulo={`Notas em ${rotuloDoMes(data.mes.periodo)}`} valor={totalDeNotas} legenda="Todos os status" />
        </section>
        <section aria-label="Valor emitido no mês">
          <StatCard titulo="Valor emitido no mês" valor={formatarCentavos(data.mes.valor_emitido_centavos)} tom="sucesso" legenda="Autorizadas, sem as canceladas" />
        </section>
        <section aria-label="Rejeições que pedem ação">
          <StatCard titulo="Pedem ação" valor={data.rejeicoes.quantidade} tom={data.rejeicoes.quantidade > 0 ? 'perigo' : 'info'} legenda="Rejeitadas ou com erro" />
        </section>
        <Certificado certificado={data.certificado} />
        <section aria-label="NFC-e em contingência">
          <StatCard titulo="NFC-e em contingência" valor={data.contingencias.quantidade} tom={data.contingencias.vencidas > 0 ? 'perigo' : data.contingencias.quantidade > 0 ? 'primario' : 'info'} legenda="Aguardam transmissão à SEFAZ" />
        </section>
      </div>

      <AvisosDeContingencia contingencias={data.contingencias} />

      <div className="grid gap-4 lg:grid-cols-3">
        <Card className="lg:col-span-2">
          <CardHeader><CardTitle>Faturamento dos últimos 12 meses</CardTitle></CardHeader>
          <CardContent><GraficoDeFaturamento itens={data.faturamento_12_meses} /></CardContent>
        </Card>

        <div className="space-y-4">
          <Card>
            <CardHeader><CardTitle>Notas do mês</CardTitle></CardHeader>
            <CardContent>
              {data.mes.notas.length === 0 ? (
                <p className="text-sm text-muted-foreground">Nenhuma nota neste mês ainda.</p>
              ) : (
                <ul aria-label="Notas do mês por status" className="space-y-2">
                  {data.mes.notas.map((n) => (
                    <li key={`${n.modelo}-${n.status}`} className="flex items-center justify-between gap-2 text-sm">
                      <span className="flex items-center gap-2">
                        <span className="text-muted-foreground">{ROTULO_MODELO[n.modelo]}</span>
                        <StatusDaNota status={n.status} />
                      </span>
                      <span className="font-mono">{n.quantidade}</span>
                    </li>
                  ))}
                </ul>
              )}
            </CardContent>
          </Card>

          <Card>
            <CardHeader><CardTitle>Pedem ação</CardTitle></CardHeader>
            <CardContent>
              {data.rejeicoes.recentes.length === 0 ? (
                <p className="text-sm text-muted-foreground">Nenhuma rejeição pendente.</p>
              ) : (
                <ul className="divide-y divide-border">
                  {data.rejeicoes.recentes.map((r) => (
                    <li key={r.id} className="py-2 text-sm">
                      <Link href={`/notas/${r.id}`} className="font-medium text-primary-text underline">
                        {ROTULO_MODELO[r.modelo]} nº {r.numero ?? 'sem número'}
                      </Link>
                      <p className="text-muted-foreground">{r.cstat ? `cStat ${r.cstat}: ` : ''}{r.motivo ?? r.mensagem_erro ?? 'Sem detalhe'}</p>
                    </li>
                  ))}
                </ul>
              )}
            </CardContent>
          </Card>
        </div>
      </div>
    </div>
  );
}
