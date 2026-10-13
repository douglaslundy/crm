'use client';

import { useEffect, useState } from 'react';
import { Banner } from '@/components/ui/banner';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { TextareaNativo } from '@/components/ui/campos-nativos';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { ApiError } from '@/lib/api';
import { formatarCentavos, formatarData } from '@/lib/formatos';
import { propostaPublicaApi } from '../publico';
import type { PropostaPublica } from '../types';

type Estado = { fase: 'carregando' } | { fase: 'indisponivel' } | { fase: 'pronta'; proposta: PropostaPublica };

/** Página do cliente, sem login: o token do link é a única credencial. Sem TanStack Query (fica fora do app autenticado). */
export function PaginaPublicaDaProposta({ token }: { token: string }) {
  const [estado, setEstado] = useState<Estado>({ fase: 'carregando' });
  const [nome, setNome] = useState('');
  const [recusando, setRecusando] = useState(false);
  const [motivo, setMotivo] = useState('');
  const [enviando, setEnviando] = useState(false);
  const [erro, setErro] = useState<string | null>(null);

  useEffect(() => {
    let ativo = true;
    propostaPublicaApi
      .ler(token)
      .then((r) => ativo && setEstado({ fase: 'pronta', proposta: r.data }))
      .catch(() => ativo && setEstado({ fase: 'indisponivel' }));
    return () => {
      ativo = false;
    };
  }, [token]);

  async function responder(acao: () => Promise<{ data: PropostaPublica }>) {
    setEnviando(true);
    setErro(null);
    try {
      const r = await acao();
      setEstado({ fase: 'pronta', proposta: r.data });
      setRecusando(false);
    } catch (e) {
      setErro(e instanceof ApiError ? e.primeiraMensagem() : 'Não foi possível enviar sua resposta. Tente novamente.');
    } finally {
      setEnviando(false);
    }
  }

  if (estado.fase === 'carregando') return <p className="p-4 text-muted-foreground">Carregando...</p>;
  if (estado.fase === 'indisponivel') {
    return <Banner tom="perigo">Proposta não encontrada ou indisponível. Confira o link com quem enviou.</Banner>;
  }

  const p = estado.proposta;
  const aberta = p.status === 'ENVIADA';

  return (
    <main className="mx-auto max-w-3xl space-y-4 p-4 md:p-8">
      <header>
        <p className="text-sm text-muted-foreground">{p.empresa.nome}{p.empresa.cnpj ? ` · CNPJ ${p.empresa.cnpj}` : ''}</p>
        <h1 className="font-display text-[28px] leading-tight font-extrabold">Proposta nº {p.numero}</h1>
        {p.cliente.nome ? <p className="text-sm">Para: {p.cliente.nome}</p> : null}
        {p.validade_em ? <p className="text-sm text-muted-foreground">Válida até {formatarData(p.validade_em)}</p> : null}
      </header>

      <Card>
        <CardHeader><CardTitle>Itens</CardTitle></CardHeader>
        <CardContent className="space-y-3 text-sm">
          <ul className="divide-y divide-border">
            {p.itens.map((i, idx) => (
              <li key={idx} className="flex flex-wrap items-baseline justify-between gap-2 py-2">
                <span>{i.descricao}</span>
                <span className="text-muted-foreground">
                  {i.quantidade} × {formatarCentavos(i.valor_unitario_centavos)} = <strong className="text-foreground">{formatarCentavos(i.total_centavos)}</strong>
                </span>
              </li>
            ))}
          </ul>
          <div className="space-y-1 text-right">
            <p>Subtotal: {formatarCentavos(p.subtotal_centavos)}</p>
            {p.desconto_centavos > 0 ? <p>Desconto: − {formatarCentavos(p.desconto_centavos)}</p> : null}
            <p className="text-base font-bold">Total: {formatarCentavos(p.total_centavos)}</p>
          </div>
          {p.observacoes ? <p><span className="text-muted-foreground">Observações: </span>{p.observacoes}</p> : null}
        </CardContent>
      </Card>

      <a href={propostaPublicaApi.urlDoPdf(token)} target="_blank" rel="noopener noreferrer" className="inline-block text-sm underline">
        Baixar PDF
      </a>

      {p.status === 'ACEITA' ? (
        <Banner tom="sucesso">Proposta aceita por {p.aceite_nome ?? 'o cliente'}. A empresa vai entrar em contato.</Banner>
      ) : null}
      {p.status === 'RECUSADA' ? <Banner tom="info">Proposta recusada.</Banner> : null}
      {p.status === 'EXPIRADA' ? (
        <Banner tom="alerta">Esta proposta expirou. Fale com {p.empresa.nome ?? 'a empresa'} para receber uma nova.</Banner>
      ) : null}
      {p.status === 'CANCELADA' ? <Banner tom="alerta">Esta proposta foi cancelada.</Banner> : null}
      {erro ? <Banner tom="perigo">{erro}</Banner> : null}

      {aberta ? (
        <Card>
          <CardHeader><CardTitle>Sua resposta</CardTitle></CardHeader>
          <CardContent className="space-y-3">
            <div className="space-y-1">
              <Label htmlFor="aceite-nome">Seu nome</Label>
              <Input id="aceite-nome" maxLength={120} value={nome} onChange={(e) => setNome(e.target.value)} />
            </div>
            <div className="flex flex-wrap gap-2">
              <Button
                disabled={nome.trim().length < 2 || enviando}
                onClick={() => void responder(() => propostaPublicaApi.aceitar(token, nome.trim()))}
              >
                Aceitar proposta
              </Button>
              {!recusando ? <Button variant="outline" disabled={enviando} onClick={() => setRecusando(true)}>Recusar</Button> : null}
            </div>
            {recusando ? (
              <div className="space-y-2">
                <Label htmlFor="recusa-motivo">Motivo (opcional)</Label>
                <TextareaNativo id="recusa-motivo" maxLength={500} value={motivo} onChange={(e) => setMotivo(e.target.value)} />
                <Button
                  variant="outline"
                  disabled={enviando}
                  onClick={() => void responder(() => propostaPublicaApi.recusar(token, motivo.trim()))}
                >
                  Confirmar recusa
                </Button>
              </div>
            ) : null}
          </CardContent>
        </Card>
      ) : null}
    </main>
  );
}
