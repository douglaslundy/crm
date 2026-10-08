import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

vi.mock('redoc', () => ({
  RedocStandalone: ({ specUrl }: { specUrl: string }) => <div data-testid="redoc" data-spec={specUrl} />,
}));

import { DocumentacaoDaApi } from './DocumentacaoDaApi';

describe('DocumentacaoDaApi', () => {
  it('mostra o título, os links e o visualizador apontando para o YAML', async () => {
    render(<DocumentacaoDaApi />);

    expect(screen.getByRole('heading', { name: /api da plataforma fiscal/i })).toBeInTheDocument();
    expect(screen.getByRole('link', { name: /baixar openapi/i })).toHaveAttribute('href', '/openapi.yaml');
    const visualizador = await screen.findByTestId('redoc');
    expect(visualizador).toHaveAttribute('data-spec', '/openapi.yaml');
  });
});
