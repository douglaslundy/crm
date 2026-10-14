# Roteiro de homologação real (F8a)

Executar com certificado A1 de homologação e CNPJ habilitado, em ambiente de homologação, antes do piloto em produção. O código nunca falou com a SEFAZ nem com a ADN reais; este roteiro cobre o que a suíte automatizada não alcança.

Para cada passo registre: data, número da nota, cStat recebido e o que a tela mostrou.

## Preparação

1. Cadastrar o emitente (Configurações > Fiscal), enviar o `.pfx` e conferir que o status do certificado fica válido.
2. Configurar série e próximo número de NF-e, NFC-e e DPS. Para a NFC-e, informar o CSC de homologação.
3. Deixar o ambiente em HOMOLOGAÇÃO.

## NF-e

4. Emitir uma NF-e de venda para destinatário PJ com IE. Esperado: AUTORIZADA, cStat 100, DANFE baixa e mostra "SEM VALOR FISCAL".
5. Emitir uma NF-e para destinatário PF não contribuinte. Esperado: AUTORIZADA.
6. Provocar uma rejeição (NCM inexistente). Esperado: REJEITADA com cStat e mensagem; a nota volta a ser editável e mantém o número.
7. Cancelar a nota do passo 4 dentro do prazo. Esperado: CANCELADA, cStat 135, XML do evento guardado.
8. Inutilizar uma faixa de números já alocados e sem nota. Esperado: cStat 102.
9. Conferir um XML autorizado contra `nfe_v4.00.xsd` e `tpAmb=2` (Checagem 8).

## NFC-e

10. Emitir uma NFC-e sem identificar o consumidor e outra com CPF. Esperado: AUTORIZADA; o cupom mostra QR Code e URL de consulta de homologação.
11. Derrubar a rede do servidor durante a emissão. Esperado: nota em CONTINGÊNCIA, cupom impresso nas duas vias; ao voltar, retransmitida e AUTORIZADA.

## NFS-e

12. Emitir uma NFS-e com serviço de código nacional válido para um município aderido ao padrão nacional. Esperado: AUTORIZADA, chave de 50 dígitos, DANFSe baixa.
13. Repetir com município não aderido. Esperado: recusa legível (ver pendência da F4a).
14. Cancelar a NFS-e do passo 12. Esperado: CANCELADA com evento 101101.
15. Validar o XML autorizado contra `NFSe_v1.01.xsd` (cNBS, ver pendência da F4a).

## Conciliação e numeração (Checagem 9)

16. Consultar na SEFAZ as chaves das notas dos passos acima e comparar com a numeração local. Esperado: nenhum número local fora do que a SEFAZ conhece.
17. Emitir de novo com um número já usado (contador forçado para trás por quem tem acesso ao banco). Esperado: 539 e recuperação do número, sem nota duplicada.
18. Simular resposta perdida: matar o worker depois de enviar e antes de gravar. Esperado: a conciliação autoriza a nota pela chave, sem reenviar.

## Denegada e fora de prazo (A-01, A-02)

19. Se a SEFAZ de homologação permitir, emitir para um destinatário com situação irregular. Esperado: nota REJEITADA com cStat 110/301/302 e, ao reemitir, número novo.

## Decisões que dependem de você (A-04 e A-05 do relatório)

20. Decidir se a NF-e passa a aceitar indicador de presença (`indPres`) e prazo de pagamento (`indPag`) informados.
21. Decidir se cStat 108/109 (serviço paralisado) deve levar a NFC-e à contingência e a NF-e ao EPEC.
