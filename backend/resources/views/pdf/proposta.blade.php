@php
    $brl = fn (int $centavos): string => 'R$ '.number_format($centavos / 100, 2, ',', '.');
@endphp
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <title>Proposta {{ $proposta->numero }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1a1a1a; }
        h1 { font-size: 18px; margin: 0 0 4px; }
        .muted { color: #666; }
        table { width: 100%; border-collapse: collapse; margin-top: 14px; }
        th, td { border-bottom: 1px solid #ddd; padding: 6px 4px; text-align: left; }
        th.num, td.num { text-align: right; }
        .totais { margin-top: 12px; text-align: right; }
        .total { font-size: 14px; font-weight: bold; }
    </style>
</head>
<body>
    <h1>{{ $tenant->nome_fantasia ?? $tenant->razao_social }}</h1>
    <div class="muted">{{ $tenant->razao_social }} @if($tenant->cnpj) · CNPJ {{ $tenant->cnpj }} @endif</div>

    <h1 style="margin-top: 18px;">Proposta nº {{ $proposta->numero }}</h1>
    <div>Cliente: <strong>{{ $cliente?->nome ?? '—' }}</strong></div>
    @if($proposta->validade_em)
        <div>Válida até {{ $proposta->validade_em->format('d/m/Y') }}</div>
    @endif

    <table>
        <thead>
            <tr><th>Descrição</th><th class="num">Qtd</th><th class="num">Unitário</th><th class="num">Total</th></tr>
        </thead>
        <tbody>
            @foreach($proposta->itens as $item)
                <tr>
                    <td>{{ $item->descricao }}</td>
                    <td class="num">{{ $item->quantidade }}</td>
                    <td class="num">{{ $brl($item->valor_unitario_centavos) }}</td>
                    <td class="num">{{ $brl($item->totalCentavos()) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <div class="totais">
        <div>Subtotal: {{ $brl($proposta->subtotalCentavos()) }}</div>
        @if($proposta->desconto_centavos > 0)
            <div>Desconto: − {{ $brl($proposta->desconto_centavos) }}</div>
        @endif
        <div class="total">Total: {{ $brl($proposta->totalCentavos()) }}</div>
    </div>

    @if($proposta->observacoes)
        <p style="margin-top: 18px;"><strong>Observações</strong><br>{{ $proposta->observacoes }}</p>
    @endif
</body>
</html>
