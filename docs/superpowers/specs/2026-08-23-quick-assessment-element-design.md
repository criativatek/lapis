# Criação rápida de Elementos de Avaliação

## Objetivo

Acrescentar uma entrada curta ao formulário canónico de `Instrument`, sem criar
modelos, tabelas, resultados ou regras de cálculo paralelos.

## Decisões

- A turma é o contexto da rota e aparece como campo informativo; não pode ser
  trocada por payload.
- A escala vem da versão ativa do perfil da turma. O elemento não escolhe uma
  escala no fluxo atual, portanto o modo rápido também não inventa essa escolha.
- O modo rápido pede designação, tipo, data, período e domínio principal.
- A estrutura persistida é o grupo implícito e um `InstrumentItem` Q1 de 100
  pontos. O domínio único usa o sincronismo já existente no formulário e chega
  ao `InstrumentBuilder` como uma alocação de 100%.
- Guardar cria o elemento no workflow normal e nunca chama a ação de conclusão.
  Não são criadas linhas de `student_item_scores`.
- Escolher/configurar vários domínios abre o modo detalhado, que mantém todas as
  capacidades atuais. O mesmo estado Vue é partilhado pelos dois modos.
- Voltar ao modo rápido só é permitido enquanto a estrutura continuar simples;
  isto evita esconder configuração avançada já preenchida.

## Segurança

O modo rápido submete ao endpoint e ao `InstrumentRequest` existentes. A policy
da turma, o scope de organização e `BelongsToCurrentOrganization` continuam a
validar turma, período, tipo, domínio, grupos e itens. Impersonation continua a
agir como o utilizador impersonado, sem exceções neste fluxo.

## Mobile e tablet

O modo rápido apresenta um seletor segmentado, uma grelha de campos essenciais
que colapsa para uma coluna e uma única ação final. Importação, finalidade,
contabilização, bónus, grupos, itens e resumos ficam no modo detalhado.

## Aceitação

Testes Feature provam entidade canónica, item/alocação, ausência de scores,
estado não concluído, edição/reabertura pelo fluxo normal, validações tenant e
autorização. Os gates TypeScript, ESLint e build validam o formulário Vue.
