import {
  AlertTriangle, BellRing, Check, FileText, KanbanSquare, MessageCircle, Plug, ShieldCheck, Target, TrendingDown, UserX,
} from 'lucide-react';
import Link from 'next/link';
import type { ReactNode } from 'react';
import { ThemeToggle } from '@/components/theme/ThemeToggle';
import { buttonVariants } from '@/components/ui/button';
import { ListaDePlanos } from '@/features/cadastro/components/ListaDePlanos';
import { cn } from '@/lib/utils';
import { linkWhatsapp } from '../whatsapp';
import { FormularioDeContato } from './FormularioDeContato';

const CTA_PRIMARIO = buttonVariants({ size: 'lg' });
const CTA_WHATSAPP = buttonVariants({ size: 'lg', variant: 'outline' });
// Sobre a faixa na cor primária o botão precisa de fundo próprio: o `secondary` some nela.
const CTA_SOBRE_DESTAQUE = cn(buttonVariants({ size: 'lg', variant: 'outline' }), 'border-transparent bg-background text-foreground hover:bg-surface');

function BotaoWhatsapp({ texto = 'Falar no WhatsApp', className }: { texto?: string; className?: string }) {
  return (
    <a href={linkWhatsapp()} target="_blank" rel="noopener noreferrer" className={`${CTA_WHATSAPP} ${className ?? ''}`}>
      <MessageCircle aria-hidden /> {texto}
    </a>
  );
}

function Secao({ id, titulo, subtitulo, children }: { id?: string; titulo: string; subtitulo?: string; children: ReactNode }) {
  return (
    <section id={id} className="scroll-mt-20 px-4 py-14 md:py-20">
      <div className="mx-auto max-w-5xl space-y-8">
        <div className="space-y-2 text-center">
          <h2 className="font-display text-[28px] leading-tight font-extrabold md:text-[34px]">{titulo}</h2>
          {subtitulo ? <p className="mx-auto max-w-2xl text-muted-foreground">{subtitulo}</p> : null}
        </div>
        {children}
      </div>
    </section>
  );
}

function Cartao({ icone, titulo, children }: { icone: ReactNode; titulo: string; children: ReactNode }) {
  return (
    <div className="space-y-2 rounded-xl border border-border bg-surface p-5">
      <div className="flex size-10 items-center justify-center rounded-lg bg-primary/12 text-primary-text" aria-hidden>{icone}</div>
      <h3 className="font-display text-lg font-bold">{titulo}</h3>
      <p className="text-sm text-muted-foreground">{children}</p>
    </div>
  );
}

const PERGUNTAS: Array<[string, string]> = [
  ['Preciso entender de imposto para usar?', 'Não. O sistema bloqueia a emissão e explica o que falta quando um dado fiscal está incompleto. Ele nunca chuta valor: a regra tributária fica sob controle da sua empresa e do seu contador.'],
  ['Quais documentos eu consigo emitir?', 'NF-e, NFC-e e NFS-e, com cancelamento, inutilização de numeração e acompanhamento do status de cada nota.'],
  ['Meus dados ficam misturados com os de outras empresas?', 'Não. Cada empresa tem seus dados isolados, com usuários e papéis de acesso próprios.'],
  ['Consigo integrar com o meu sistema?', 'Sim. Há API pública com chave por empresa e webhooks para avisar o seu sistema quando uma nota muda de situação.'],
  ['Como falo com um humano antes de assinar?', 'Pelo botão do WhatsApp ou pelo formulário desta página. Respondemos o mais rápido possível.'],
];

export function Landing() {
  return (
    <div className="min-h-dvh bg-background text-foreground">
      <header className="sticky top-0 z-30 flex h-[60px] items-center justify-between gap-2 border-b border-border bg-surface/95 px-4 backdrop-blur">
        <Link href="/" className="font-display text-base font-extrabold tracking-[0.03em]">Plataforma Fiscal</Link>
        <nav aria-label="Seções" className="hidden items-center gap-5 text-sm text-muted-foreground md:flex">
          <a href="#recursos" className="hover:text-foreground">Recursos</a>
          <a href="#planos" className="hover:text-foreground">Planos</a>
          <a href="#duvidas" className="hover:text-foreground">Dúvidas</a>
          <a href="#contato" className="hover:text-foreground">Contato</a>
        </nav>
        <div className="flex items-center gap-2">
          <Link href="/login" className="text-sm text-muted-foreground hover:text-foreground">Entrar</Link>
          <a href="#planos" className={buttonVariants({ size: 'sm' })}>Começar agora</a>
          <ThemeToggle />
        </div>
      </header>

      <main>
        <section className="px-4 py-16 text-center md:py-24">
          <div className="mx-auto max-w-3xl space-y-6">
            <p className="inline-flex items-center gap-2 rounded-full border border-primary/40 bg-primary/12 px-3 py-1 text-xs font-semibold text-primary-text">
              <AlertTriangle className="size-3.5" aria-hidden /> Nota fiscal errada custa mais caro do que o sistema que a evitaria
            </p>
            <h1 className="font-display text-[36px] leading-[1.05] font-extrabold md:text-[56px]">
              Emita nota fiscal sem susto e <span className="text-primary-text">venda mais</span> com o CRM no mesmo lugar
            </h1>
            <p className="mx-auto max-w-2xl text-lg text-muted-foreground">
              NF-e, NFC-e e NFS-e junto com funil de vendas, propostas e agenda. Chega de planilha, de nota rejeitada de última hora
              e de cliente esquecido esperando retorno.
            </p>
            <div className="flex flex-col items-center justify-center gap-3 sm:flex-row">
              <a href="#planos" className={CTA_PRIMARIO}>Quero começar agora</a>
              <BotaoWhatsapp />
            </div>
            <ul className="flex flex-wrap items-center justify-center gap-x-6 gap-y-2 text-sm text-muted-foreground">
              {['Sem instalar nada', 'Dados isolados por empresa', 'API e webhooks inclusos'].map((t) => (
                <li key={t} className="flex items-center gap-1.5"><Check className="size-4 text-success" aria-hidden /> {t}</li>
              ))}
            </ul>
          </div>
        </section>

        <div className="bg-surface">
          <Secao
            titulo="Cada dia no improviso tem um preço"
            subtitulo="O problema não aparece quando está tudo certo. Aparece na hora em que a nota é rejeitada, o prazo vence ou o cliente fecha com o concorrente."
          >
            <div className="grid gap-4 md:grid-cols-3">
              <Cartao icone={<TrendingDown />} titulo="Venda parada">
                Nota rejeitada na hora de entregar trava o pedido e deixa o cliente esperando. Cada hora parada é dinheiro que não entra.
              </Cartao>
              <Cartao icone={<UserX />} titulo="Cliente esquecido">
                Proposta sem acompanhamento esfria. Sem funil e sem agenda, o lead que ia fechar compra de quem respondeu primeiro.
              </Cartao>
              <Cartao icone={<BellRing />} titulo="Risco fiscal">
                Cadastro de produto incompleto e numeração perdida viram dor de cabeça com o fisco. Quanto mais tarde você organiza, mais cara fica a correção.
              </Cartao>
            </div>
            <div className="text-center">
              <a href="#planos" className={CTA_PRIMARIO}>Resolver isso hoje</a>
            </div>
          </Secao>
        </div>

        <Secao id="recursos" titulo="Tudo o que a sua empresa precisa, em um só lugar" subtitulo="Fiscal e vendas conversam entre si: do primeiro contato à nota emitida.">
          <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
            <Cartao icone={<FileText />} titulo="Emissão de NF-e, NFC-e e NFS-e">
              Emita, acompanhe, cancele e inutilize numeração. Dado fiscal faltando bloqueia a nota com aviso claro do que corrigir.
            </Cartao>
            <Cartao icone={<ShieldCheck />} titulo="Pendências fiscais sob controle">
              Produtos e serviços com cadastro incompleto aparecem em uma lista, para você corrigir antes de precisar emitir.
            </Cartao>
            <Cartao icone={<KanbanSquare />} titulo="Funil de vendas">
              Arraste negócios entre etapas, veja quanto está em jogo e saiba o que está parado.
            </Cartao>
            <Cartao icone={<FileText />} titulo="Propostas com aceite online">
              Envie um link, o cliente abre no celular e aceita. Sem PDF perdido em conversa.
            </Cartao>
            <Cartao icone={<Target />} titulo="Agenda, metas e relatórios">
              Tarefas com data, metas por vendedor e relatórios de ganhos e perdas para decidir com número, não com palpite.
            </Cartao>
            <Cartao icone={<Plug />} titulo="API pública e webhooks">
              Conecte o seu sistema com chave de API por empresa e receba aviso automático quando uma nota mudar de situação.
            </Cartao>
          </div>
        </Secao>

        <div className="bg-surface">
          <Secao titulo="Comece em três passos">
            <ol className="grid gap-4 md:grid-cols-3">
              {[
                ['1', 'Escolha o plano', 'Veja preços e limites abaixo e crie a conta da sua empresa.'],
                ['2', 'Configure o emitente', 'Informe os dados da empresa e o certificado digital. O sistema guia o que falta.'],
                ['3', 'Emita e venda', 'Cadastre clientes, monte propostas, feche negócios e emita as notas.'],
              ].map(([n, t, d]) => (
                <li key={n} className="space-y-2 rounded-xl border border-border bg-background p-5">
                  <span className="flex size-9 items-center justify-center rounded-full bg-primary font-display text-lg font-bold text-primary-foreground" aria-hidden>{n}</span>
                  <h3 className="font-display text-lg font-bold">{t}</h3>
                  <p className="text-sm text-muted-foreground">{d}</p>
                </li>
              ))}
            </ol>
          </Secao>
        </div>

        <Secao id="planos" titulo="Escolha seu plano e comece hoje" subtitulo="Preços e limites abaixo são os atuais.">
          <ListaDePlanos />
        </Secao>

        <section className="bg-primary px-4 py-14 text-center text-primary-foreground">
          <div className="mx-auto max-w-3xl space-y-5">
            <h2 className="font-display text-[28px] leading-tight font-extrabold md:text-[36px]">A próxima nota rejeitada não precisa acontecer</h2>
            <p className="opacity-90">Quanto antes a operação estiver organizada, menos você paga em retrabalho, venda perdida e correção às pressas.</p>
            <div className="flex flex-col items-center justify-center gap-3 sm:flex-row">
              <a href="#planos" className={CTA_SOBRE_DESTAQUE}>
                Começar agora
              </a>
              <a href={linkWhatsapp()} target="_blank" rel="noopener noreferrer" className={CTA_SOBRE_DESTAQUE}>
                <MessageCircle aria-hidden /> Chamar no WhatsApp
              </a>
            </div>
          </div>
        </section>

        <Secao id="duvidas" titulo="Dúvidas frequentes">
          <div className="mx-auto max-w-3xl divide-y rounded-xl border border-border bg-surface">
            {PERGUNTAS.map(([p, r]) => (
              <details key={p} className="group p-4">
                <summary className="cursor-pointer list-none font-medium marker:hidden after:float-right after:text-muted-foreground after:content-['+'] group-open:after:content-['–']">{p}</summary>
                <p className="mt-2 text-sm text-muted-foreground">{r}</p>
              </details>
            ))}
          </div>
        </Secao>

        <div className="bg-surface">
          <Secao id="contato" titulo="Ficou com dúvida? Fale com a gente" subtitulo="Chame no WhatsApp para uma resposta rápida ou deixe sua mensagem no formulário.">
            <div className="mx-auto grid max-w-4xl gap-8 md:grid-cols-[1fr_1.4fr]">
              <div className="space-y-4">
                <p className="text-muted-foreground">Prefere conversar agora? Toque no botão e a conversa abre no seu WhatsApp.</p>
                <BotaoWhatsapp texto="Abrir conversa no WhatsApp" className="w-full sm:w-auto" />
              </div>
              <div className="relative rounded-xl border border-border bg-background p-5">
                <FormularioDeContato />
              </div>
            </div>
          </Secao>
        </div>
      </main>

      <footer className="border-t border-border px-4 py-8 text-center text-sm text-muted-foreground">
        <p>Plataforma Fiscal + CRM · <Link href="/login" className="underline">Entrar</Link> · <Link href="/planos" className="underline">Planos</Link></p>
      </footer>

      <a
        href={linkWhatsapp()}
        target="_blank"
        rel="noopener noreferrer"
        aria-label="Falar no WhatsApp"
        className="fixed right-4 bottom-4 z-40 flex size-14 items-center justify-center rounded-full bg-success-strong text-white shadow-lg transition-transform hover:scale-105 focus-visible:ring-[3px] focus-visible:ring-primary/40 focus-visible:outline-none"
      >
        <MessageCircle className="size-7" aria-hidden />
      </a>
    </div>
  );
}
