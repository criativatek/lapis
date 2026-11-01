# Proposta — «Aderi à greve» como especificação facultativa

**Estado:** PROPOSTA TÉCNICA. Não é texto legal aprovado, não altera a Política de
Privacidade nem o Acordo de Subcontratação, e **não considera concluída nenhuma validação
jurídica**. A funcionalidade está desligada em produção (`LAPIS_STRIKE_ADHESION_RECORDING=false`).

## 1. O que o código faz

Numa aula **não realizada** com a causa «Greve», o professor pode, **se quiser**, especificar:

| Opção no ecrã | Valor | Significado | Oferecida |
|---|---|---|---|
| «Greve» | `null` | Só «Greve» — sem especificação (opção por defeito) | sempre |
| «Greve do professor» | `adhered` | O próprio aderiu: declaração **voluntária e individual**, reservada ao titular | só com `lapis.strike_adhesion_recording` |
| «Greve — escola encerrada» | `school_closed` | A escola estava encerrada por causa da greve: **circunstância da escola**, não declaração de ninguém; lê-se como a causa, por toda a turma | sempre (não depende da chave da adesão) |
| — | `not_adhered`, `not_informed` | Valores de registos anteriores | nunca se oferecem; lêem-se sempre, sem conversão. `school_closed` **não** é `not_adhered` |

A chave `lapis.strike_adhesion_recording` controla **só** «Greve do professor»; «Greve» e «Greve — escola encerrada»
dependem apenas de `lapis.lesson_not_held_enabled`. Desligar qualquer chave depois de existirem registos não apaga nem
esconde nada: o que está gravado lê-se, e a declaração gravada mantém-se ao corrigir outra coisa.

- Nunca se infere (nem da falta de sumário, nem das faltas, nem da própria não realização).
- Nunca se atribui a outro professor: só quem registou o resultado a declara, e um observador
  não regista resultados.
- «Greve do professor» só o professor que registou a lê (`LessonOutcomeDetails`); colegas veem «Aula não realizada»,
  a causa («Greve» ou «Greve — escola encerrada») e que o resto está reservado.
- Não vai para a auditoria, para a IA, para pesquisas, painéis nem relatórios gerais da turma.
- O relatório geral mostra apenas a causa agregada «Greve». O relatório pessoal mostra a
  especificação do próprio, quando existe.
- O backup integral de recuperação (a cópia diária da base, só da operação) guarda-a, para o restauro ser fiel. A exportação pessoal restaurável só leva a do próprio; a de colegas viaja reduzida.
- **Correções.** O professor pode corrigir a especificação (inclusive deixá-la vazia). Cada correção
  guarda o valor anterior e o novo num histórico visível só para ele (não vai para a auditoria geral
  nem para relatórios), com um motivo textual curto. O histórico viaja na exportação pessoal **do
  próprio**; o dos colegas nunca.
- **Direção.** A correção administrativa (titular fora da turma) só muda a causa, nunca declara nem lê
  a especificação; ao mudar a causa para uma que não é «Greve», a especificação do titular deixa de
  estar na aula e fica só no histórico, ilegível para a direção.

## 2. O que precisa de validação jurídica (por decidir por quem tem competência)

1. Se a especificação perante a greve se enquadra como dado sobre convicções/filiação sindical
   (categoria especial) ou como dado operacional do professor, e com que fundamento
   de licitude do tratamento. **Não se pressupõe resposta.**
2. Texto da Política de Privacidade (secção «Dados do professor») a descrever: a causa de uma
   aula não realizada, a especificação facultativa, quem a lê e para quê.
3. Texto do Acordo de Subcontratação (secção «Objeto, duração e natureza do tratamento»)
   a nomear estes dados e a sua natureza.
4. Nova data de entrada em vigor e notificação, conforme o procedimento já usado.
5. Se o backup/exportação pessoal deve incluir a especificação dos colegas da mesma turma.
   (Hoje não inclui: viaja reduzida.) **[A VALIDAR]**
6. Conservação da especificação **e do seu histórico de correções**; se uma correção que apaga a
   especificação deve ou não manter o valor anterior no histórico. **[A VALIDAR]**
7. Se a correção administrativa que remove a especificação (por mudar a causa) é aceitável sem
   informar o titular. **[A VALIDAR]**

> Todos os pontos desta secção ficam **[A VALIDAR]** por quem tem competência; nenhum é dado por
> concluído nem condiciona o desenvolvimento ou os testes.

## 3. Como se liga, depois da validação

1. Atualizar `LegalDocuments` e o `LessonOutcomeLegalTest` **na mesma alteração** (o teste
   fixa o que o pedido aceita e o que o texto descreve).
2. `LAPIS_LESSON_NOT_HELD_ENABLED=true` (a causa) e, só então, `LAPIS_STRIKE_ADHESION_RECORDING=true`.
3. Antes de qualquer reversão de código: `php artisan lapis:lesson-outcome-recovery-check`.

A causa «Greve» sem especificação não depende do ponto 2.1 mais do que as restantes causas;
mas a chave `lesson_not_held_enabled` também está desligada e segue a mesma validação.
