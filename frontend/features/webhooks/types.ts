export type AmbienteDoWebhook = 'HOMOLOGACAO' | 'PRODUCAO';

export const ROTULO_AMBIENTE: Record<AmbienteDoWebhook, string> = { HOMOLOGACAO: 'Homologação', PRODUCAO: 'Produção' };

/** Eventos de nota que um webhook pode assinar. Nenhum marcado = todos. */
export const EVENTOS_DE_NOTA = [
  { valor: 'nota.autorizada', rotulo: 'Nota autorizada' },
  { valor: 'nota.rejeitada', rotulo: 'Nota rejeitada' },
  { valor: 'nota.cancelada', rotulo: 'Nota cancelada' },
  { valor: 'nota.erro', rotulo: 'Erro na nota' },
  { valor: 'nota.contingencia', rotulo: 'Nota em contingência' },
] as const;

export type NomeDoEvento = (typeof EVENTOS_DE_NOTA)[number]['valor'];

export interface Webhook {
  id: string;
  url: string;
  descricao: string | null;
  eventos: string[];
  ambiente: AmbienteDoWebhook;
  ativo: boolean;
  desativado_em: string | null;
  motivo_desativacao: string | null;
  segredo_final: string;
  criado_em: string;
}

/** Só a resposta da criação traz o segredo inteiro. */
export interface WebhookCriado extends Webhook {
  segredo: string;
}

export type EstadoDaEntrega = 'PENDENTE' | 'ENTREGUE' | 'FALHOU';

export const ROTULO_ESTADO: Record<EstadoDaEntrega, string> = { PENDENTE: 'Pendente', ENTREGUE: 'Entregue', FALHOU: 'Falhou' };

export interface TentativaDeEntrega {
  numero: number;
  status_http: number | null;
  duracao_ms: number;
  erro: string | null;
  resposta: string | null;
  em: string;
}

export interface Entrega {
  id: string;
  webhook_id: string;
  nota_id: string | null;
  evento: string;
  evento_id: string;
  estado: EstadoDaEntrega;
  tentativas: number;
  proxima_tentativa_em: string | null;
  entregue_em: string | null;
  criada_em: string;
  tentativas_log: TentativaDeEntrega[];
}
