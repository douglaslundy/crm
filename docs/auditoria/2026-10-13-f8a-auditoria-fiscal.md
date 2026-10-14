# F8a: auditoria fiscal pré-go-live

Data: 2026-10-13. Spec: `docs/superpowers/specs/2026-10-13-f8a-auditoria-fiscal-design.md`. Plano: `docs/superpowers/plans/2026-10-13-f8a-auditoria-fiscal.md`. Roteiro do que depende da SEFAZ/ADN: `docs/auditoria/roteiro-homologacao-real.md`.

## Grade (motor × documento)

| Motor | NF-e | NFC-e | NFS-e |
|---|---|---|---|
| NFePHP (`Modules/Fiscal/NfePhp`) | sim | sim | |
| NFS-e Nacional (`Modules/Fiscal/Nfse`, lib `nfse-php`) | | | sim |

## Achados

| ID | Checagem | arquivo:linha | Gravidade | Efeito para o usuário | Decisão |
|---|---|---|---|---|---|

## Checagem 11: nada privado em repositório público

- Repositório `douglaslundy/crm` é **PUBLIC** (`gh repo view --json visibility`).
- CNPJ: só `11.222.333/0001-81`, `11.444.777/0001-61`, `11.222.333/0001-82`, `00.000.000/0000-00`, `12.345.678/0001-95` e `52.998.224/0001-72`; todos números de teste conhecidos ou sintéticos (os dois últimos aparecem só em `backend/tests/Feature/Billing`). Chaves de 44 dígitos embutem `11444777000161` (sintético) e terminam em sequência óbvia.
- CPF: `111.444.777-35` e `529.982.247-25` (números de teste de validação), `111.111.111-11` e `000.000.000-00` (casos inválidos).
- E-mails: só domínios fictícios (`x.com`, `exemplo.com.br`, `empresa.local`, `plataforma.local`) e `taylor@laravel.com` (padrão do framework).
- Nenhum `.pfx`, `.p12`, `.pem`, `.key` ou `.cer` versionado; nenhum `.env` versionado.
- `docs/design-system/01-analise-mecanicapro.md` descreve o estilo do Mecânica Pro sem dados de cliente (placas, telefones, nomes) nem credenciais.
- **Sem achado.**
