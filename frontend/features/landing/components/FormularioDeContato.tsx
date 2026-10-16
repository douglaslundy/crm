'use client';

import { useMutation, useQuery } from '@tanstack/react-query';
import { useState, type FormEvent } from 'react';
import { Campo } from '@/components/form/Campo';
import { Banner } from '@/components/ui/banner';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { TextareaNativo } from '@/components/ui/campos-nativos';
import { ApiError } from '@/lib/api';
import { contatoApi, type ContatoEntrada } from '../api';

const VAZIO = { nome: '', email: '', mensagem: '', resposta: '' };

export function FormularioDeContato() {
  const [campos, setCampos] = useState(VAZIO);
  const [armadilha, setArmadilha] = useState('');
  const [erros, setErros] = useState<Record<string, string>>({});
  const [enviado, setEnviado] = useState(false);

  const desafio = useQuery({
    queryKey: ['contato-desafio'],
    queryFn: async () => (await contatoApi.desafio()).data,
    staleTime: Infinity,
    gcTime: 0,
    refetchOnWindowFocus: false,
  });

  const enviar = useMutation({
    mutationFn: (dados: ContatoEntrada) => contatoApi.enviar(dados),
    onSuccess: () => {
      setEnviado(true);
      setCampos(VAZIO);
      setErros({});
    },
    onError: (erro) => {
      if (erro instanceof ApiError && erro.status === 422) {
        setErros(Object.fromEntries(Object.entries(erro.errors).map(([campo, msgs]) => [campo, msgs[0]])));
      } else if (erro instanceof ApiError && erro.status === 429) {
        setErros({ geral: 'Muitas tentativas. Aguarde alguns minutos ou fale pelo WhatsApp.' });
      } else {
        setErros({ geral: 'Não foi possível enviar agora. Tente de novo ou fale pelo WhatsApp.' });
      }
    },
    // A charada vale uma tentativa por carregamento: sempre pede outra depois de enviar.
    onSettled: () => {
      setCampos((c) => ({ ...c, resposta: '' }));
      void desafio.refetch();
    },
  });

  function aoEnviar(e: FormEvent) {
    e.preventDefault();
    if (!desafio.data) return;
    setEnviado(false);
    setErros({});
    enviar.mutate({
      nome: campos.nome.trim(),
      email: campos.email.trim(),
      mensagem: campos.mensagem.trim(),
      desafio_token: desafio.data.token,
      desafio_resposta: campos.resposta.trim(),
      site: armadilha,
    });
  }

  const mudar = (campo: keyof typeof VAZIO) => (e: { target: { value: string } }) =>
    setCampos((c) => ({ ...c, [campo]: e.target.value }));

  return (
    <form onSubmit={aoEnviar} className="space-y-4" noValidate>
      {enviado ? <Banner tom="sucesso">Mensagem enviada! Em breve entramos em contato pelo e-mail informado.</Banner> : null}
      {erros.geral ? <Banner tom="perigo">{erros.geral}</Banner> : null}

      <Campo id="contato-nome" label="Seu nome" obrigatorio erro={erros.nome}>
        {(a11y) => <Input {...a11y} autoComplete="name" maxLength={120} value={campos.nome} onChange={mudar('nome')} />}
      </Campo>
      <Campo id="contato-email" label="Seu e-mail" obrigatorio erro={erros.email}>
        {(a11y) => <Input {...a11y} type="email" autoComplete="email" maxLength={150} value={campos.email} onChange={mudar('email')} />}
      </Campo>
      <Campo id="contato-mensagem" label="Mensagem" obrigatorio erro={erros.mensagem}>
        {(a11y) => <TextareaNativo {...a11y} rows={4} maxLength={2000} value={campos.mensagem} onChange={mudar('mensagem')} />}
      </Campo>

      {/* Armadilha para robôs: invisível e fora da ordem de tabulação; pessoas nunca preenchem. */}
      <div aria-hidden className="absolute -left-[9999px] h-0 w-0 overflow-hidden">
        <label>
          Site
          <input name="site" tabIndex={-1} autoComplete="off" value={armadilha} onChange={(e) => setArmadilha(e.target.value)} />
        </label>
      </div>

      {desafio.isError ? (
        <Banner tom="alerta">
          Não foi possível carregar a verificação.{' '}
          <button type="button" className="underline" onClick={() => void desafio.refetch()}>Tentar de novo</button>
        </Banner>
      ) : (
        <Campo
          id="contato-resposta"
          label={desafio.data ? `Prove que você é gente: quanto é ${desafio.data.pergunta}?` : 'Carregando verificação...'}
          obrigatorio
          erro={erros.desafio_resposta}
        >
          {(a11y) => (
            <Input {...a11y} inputMode="numeric" maxLength={10} className="max-w-32" value={campos.resposta} onChange={mudar('resposta')} />
          )}
        </Campo>
      )}

      <Button type="submit" size="lg" className="w-full sm:w-auto" disabled={enviar.isPending || !desafio.data}>
        {enviar.isPending ? 'Enviando...' : 'Enviar mensagem'}
      </Button>
    </form>
  );
}
