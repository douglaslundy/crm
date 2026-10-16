import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { describe, expect, it, vi } from 'vitest';
import { emitenteApi } from '../api';
import { CertificadoForm } from './CertificadoForm';
import { ConfirmarProducao } from './ConfirmarProducao';
import { CscForm } from './CscForm';
import { DadosDaEmpresaForm } from './DadosDaEmpresaForm';
import { SeriesForm } from './SeriesForm';

vi.mock('../api', () => ({
  QUERY_KEY_EMITENTE: ['emitente'],
  emitenteApi: {
    buscar: vi.fn(), consultarCep: vi.fn(), atualizarEmpresa: vi.fn(), atualizarFiscal: vi.fn(),
    uploadCertificado: vi.fn(), atualizarCsc: vi.fn(), atualizarSerie: vi.fn(), confirmarProducao: vi.fn(),
  },
}));

function renderizar(ui: ReactNode) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
  render(<QueryClientProvider client={client}>{ui}</QueryClientProvider>);
}

const EMITENTE_VAZIO = {
  logradouro: null, numero: null, bairro: null, cidade: null, uf: null, cep: null, codigo_ibge: null,
  regime_tributario: null, inscricao_estadual: null, inscricao_municipal: null, cnae: null,
  ambiente_fiscal: 'HOMOLOGACAO' as const, certificado_status: 'PENDENTE' as const, certificado_validade: null, certificado_titular: null,
  csc_homologacao_configurado: false, csc_producao_configurado: false, exige_csc: false, emissao_de_teste_autorizada: false, series: [],
};

describe('emitente', () => {
  it('salva os dados da empresa', async () => {
    vi.mocked(emitenteApi.atualizarEmpresa).mockResolvedValue({ data: EMITENTE_VAZIO });
    renderizar(<DadosDaEmpresaForm emitente={EMITENTE_VAZIO} />);

    await userEvent.type(screen.getByLabelText('Logradouro'), 'Praça da Sé');
    await userEvent.click(screen.getByRole('button', { name: 'Salvar endereço' }));

    await waitFor(() => expect(emitenteApi.atualizarEmpresa).toHaveBeenCalledWith(expect.objectContaining({ logradouro: 'Praça da Sé' })));
  });

  it('envia o certificado como FormData com arquivo e senha', async () => {
    vi.mocked(emitenteApi.uploadCertificado).mockResolvedValue({ data: { ...EMITENTE_VAZIO, certificado_status: 'VALIDO' } });
    renderizar(<CertificadoForm emitente={EMITENTE_VAZIO} />);

    const arquivo = new File(['conteudo'], 'certificado.pfx');
    await userEvent.upload(screen.getByLabelText('Arquivo do certificado (.pfx)'), arquivo);
    await userEvent.type(screen.getByLabelText('Senha do certificado'), 'minha-senha');
    await userEvent.click(screen.getByRole('button', { name: 'Enviar certificado' }));

    await waitFor(() => expect(emitenteApi.uploadCertificado).toHaveBeenCalledWith(arquivo, 'minha-senha'));
  });

  it('CSC não aparece como obrigatório quando o plano não tem NFC-e', () => {
    renderizar(<CscForm emitente={EMITENTE_VAZIO} />);

    expect(screen.queryByText(/obrigatório/i)).not.toBeInTheDocument();
  });

  it('CSC aparece como recomendado quando o plano tem NFC-e', () => {
    renderizar(<CscForm emitente={{ ...EMITENTE_VAZIO, exige_csc: true }} />);

    expect(screen.getByText(/seu plano inclui NFC-e/i)).toBeInTheDocument();
  });

  it('salva a série de NF-e', async () => {
    vi.mocked(emitenteApi.atualizarSerie).mockResolvedValue({ data: EMITENTE_VAZIO });
    renderizar(<SeriesForm emitente={EMITENTE_VAZIO} modelo="NFE" rotulo="NF-e" />);

    await userEvent.type(screen.getByLabelText('Série (NF-e)'), '1');
    await userEvent.clear(screen.getByLabelText('Próximo número (NF-e)'));
    await userEvent.type(screen.getByLabelText('Próximo número (NF-e)'), '1');
    await userEvent.click(screen.getByRole('button', { name: 'Salvar série (NF-e)' }));

    await waitFor(() => expect(emitenteApi.atualizarSerie).toHaveBeenCalledWith('NFE', '1', 1));
  });
});

describe('ConfirmarProducao', () => {
  it('bloqueia a ida para produção sem emissão de teste autorizada', () => {
    renderizar(<ConfirmarProducao emitente={{ ...EMITENTE_VAZIO, emissao_de_teste_autorizada: false }} />);
    expect(screen.getByText(/Emita ao menos uma NF-e de teste/)).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Ir para produção' })).not.toBeInTheDocument();
  });

  it('exige a caixa de entendimento e então confirma', async () => {
    vi.mocked(emitenteApi.confirmarProducao).mockResolvedValue({ data: { ...EMITENTE_VAZIO, ambiente_fiscal: 'PRODUCAO', emissao_de_teste_autorizada: true } });
    renderizar(<ConfirmarProducao emitente={{ ...EMITENTE_VAZIO, emissao_de_teste_autorizada: true }} />);

    const botao = screen.getByRole('button', { name: 'Ir para produção' });
    expect(botao).toBeDisabled();
    await userEvent.click(screen.getByLabelText(/valor fiscal/));
    expect(botao).toBeEnabled();
    await userEvent.click(botao);

    await waitFor(() => expect(emitenteApi.confirmarProducao).toHaveBeenCalled());
  });

  it('envia o primeiro número de produção de cada série (padrão 1)', async () => {
    vi.mocked(emitenteApi.confirmarProducao).mockClear();
    vi.mocked(emitenteApi.confirmarProducao).mockResolvedValue({ data: { ...EMITENTE_VAZIO, ambiente_fiscal: 'PRODUCAO', emissao_de_teste_autorizada: true } });
    renderizar(
      <ConfirmarProducao
        emitente={{ ...EMITENTE_VAZIO, emissao_de_teste_autorizada: true, series: [{ modelo: 'NFE', serie: '1', proximo_numero: 12 }] }}
      />,
    );

    const campo = screen.getByLabelText('Primeiro número em produção (NF-e)');
    expect(campo).toHaveValue(1);
    await userEvent.clear(campo);
    await userEvent.type(campo, '500');
    await userEvent.click(screen.getByLabelText(/valor fiscal/));
    await userEvent.click(screen.getByRole('button', { name: 'Ir para produção' }));

    await waitFor(() => expect(emitenteApi.confirmarProducao).toHaveBeenCalledWith({ NFE: 500 }));
  });

  it('não envia número inicial inválido', async () => {
    vi.mocked(emitenteApi.confirmarProducao).mockClear();
    renderizar(
      <ConfirmarProducao
        emitente={{ ...EMITENTE_VAZIO, emissao_de_teste_autorizada: true, series: [{ modelo: 'NFE', serie: '1', proximo_numero: 12 }] }}
      />,
    );

    const campo = screen.getByLabelText('Primeiro número em produção (NF-e)');
    await userEvent.clear(campo);
    await userEvent.type(campo, '0');
    await userEvent.click(screen.getByLabelText(/valor fiscal/));
    await userEvent.click(screen.getByRole('button', { name: 'Ir para produção' }));

    expect(await screen.findByText(/pelo menos 1/)).toBeInTheDocument();
    expect(emitenteApi.confirmarProducao).not.toHaveBeenCalled();
  });

  it('em produção mostra só a faixa de ambiente', () => {
    renderizar(<ConfirmarProducao emitente={{ ...EMITENTE_VAZIO, ambiente_fiscal: 'PRODUCAO', emissao_de_teste_autorizada: true }} />);
    expect(screen.getByText(/PRODUÇÃO/)).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Ir para produção' })).not.toBeInTheDocument();
  });
});
