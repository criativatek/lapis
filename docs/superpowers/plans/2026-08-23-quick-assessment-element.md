# Quick Assessment Element Implementation Plan

> **For agentic workers:** Execute inline and verify each task before proceeding.

**Goal:** Criar Elementos de Avaliação simples em poucos campos sem bifurcar o domínio.

**Architecture:** O formulário Vue existente ganha dois modos sobre o mesmo `useForm`. O POST, request, builder, modelos e workflow de correção permanecem canónicos.

**Tech Stack:** Laravel 13, Inertia, Vue 3, TypeScript, Tailwind 4, PHPUnit 12.

## Global Constraints

- UI em pt-PT e sempre “Elemento de Avaliação”.
- Sem migrations, modelos Quick*, scores ou resultados automáticos.
- Guardar não conclui; conclusão e reabertura continuam explícitas.
- IDs tenant-owned continuam validados por `BelongsToCurrentOrganization`.

---

### Task 1: Cobertura Feature do payload rápido

**Files:**
- Create: `tests/Feature/Assessment/QuickInstrumentCreationTest.php`

- [ ] Escrever testes para criação canónica, alocação 100%, ausência de scores e estado não concluído.
- [ ] Escrever testes para domínio/período/tipo cross-tenant e turma não autorizada.
- [ ] Escrever teste de edição e workflow normal.
- [ ] Correr o ficheiro e confirmar o estado inicial.

### Task 2: Alternador rápido/detalhado

**Files:**
- Modify: `resources/js/pages/instruments/InstrumentForm.vue`
- Modify: `resources/js/pages/instruments/Create.vue`
- Modify: `resources/js/pages/instruments/Edit.vue`

- [ ] Partilhar o mesmo estado do formulário entre os modos.
- [ ] Mostrar turma, designação, tipo, data, período e domínio no modo rápido.
- [ ] Manter todo o formulário atual no modo detalhado.
- [ ] Encaminhar múltiplos domínios para o modo detalhado e impedir ocultação insegura de estrutura avançada.
- [ ] Correr ESLint, vue-tsc e build.

### Task 3: Fecho e release

**Files:**
- Modify: `config/app.php`
- Modify: `CHANGELOG.md`

- [ ] Correr testes focados e suite completa.
- [ ] Correr todos os gates pedidos.
- [ ] Atualizar versão para a próxima minor pré-1.0 e documentar a fatia.
- [ ] Criar commits conventional locais, sem push.
