export type AmbienteDaChave = 'LIVE' | 'TEST';

export const ROTULO_AMBIENTE: Record<AmbienteDaChave, string> = { LIVE: 'Produção', TEST: 'Teste' };

export interface ChaveDeApi {
  id: string;
  nome: string;
  ambiente: AmbienteDaChave;
  prefixo: string;
  ultimos4: string;
  usuario: { id: string; nome: string };
  ultimo_uso_em: string | null;
  revogada_em: string | null;
  criada_em: string;
}

/** Só a resposta da criação traz a chave inteira. */
export interface ChaveCriada extends ChaveDeApi {
  chave: string;
}
