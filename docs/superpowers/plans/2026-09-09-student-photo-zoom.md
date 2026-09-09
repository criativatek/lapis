# Ampliação da fotografia do aluno Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Tornar as fotografias reais dos alunos ampliáveis através do `StudentAvatar`, com um Dialog acessível e responsivo, sem alterações no backend.

**Architecture:** `StudentAvatar.vue` recebe `zoomable` e `studentName`, mantém o fallback não interativo e controla o `Dialog` reutilizável quando existe uma foto válida. Todos os usos atuais que representam fotografias de alunos passam as props explicitamente; os componentes de avatar genérico não são alterados.

**Tech Stack:** Vue 3, TypeScript, Reka UI, Tailwind CSS, Vitest, Vue Test Utils, ESLint, vue-tsc, Vite.

## Global Constraints

- Manter exatamente a URL autorizada já fornecida em `photoUrl`; não criar endpoint, URL pública, storage ou persistência.
- Usar o `Dialog` existente para overlay, foco, Esc e clique fora.
- A thumbnail mantém as dimensões atuais; só fotos reais de alunos recebem `zoomable`.
- Não adicionar download, edição, partilha, navegação ou alteração de backend.
- Código e nomes internos em inglês; texto de interface e labels em pt-PT.
- A foto sem valor ou que falhe ao carregar não pode abrir o modal.
- Validar ESLint, vue-tsc, Vitest e build antes de concluir.

---

### Task 1: Expor o zoom acessível no StudentAvatar

**Files:**
- Modify: `resources/js/components/StudentAvatar.vue`
- Test: `resources/js/components/StudentAvatar.test.ts`

**Interfaces:**
- Consumes: `photoUrl?: string | null`, `size?: 'xs' | 'md'`, `class?: HTMLAttributes['class']`.
- Produces: `zoomable?: boolean`, `studentName?: string`; quando ativos e há foto válida, um botão com `aria-label="Ampliar fotografia de <studentName>"` abre o Dialog com a mesma URL.

- [ ] **Step 1: Criar testes Vitest do comportamento público**

Adicionar um teste de componente com `mount(StudentAvatar, { props })`, mockando apenas o necessário para os componentes Dialog existentes. Cobrir:

```ts
it('abre a fotografia correta ao ativar a thumbnail por teclado', async () => {
    const wrapper = mount(StudentAvatar, {
        props: {
            photoUrl: '/students/authorized-photo',
            studentName: 'Ana Martins',
            zoomable: true,
        },
    });

    const trigger = wrapper.get('button[aria-label="Ampliar fotografia de Ana Martins"]');
    await trigger.trigger('keydown.enter');

    expect(wrapper.get('img[alt="Fotografia de Ana Martins"]').attributes('src'))
        .toBe('/students/authorized-photo');
});
```

Acrescentar casos para:

- `photoUrl` nula não renderizar botão nem Dialog;
- Space abrir;
- X fechar;
- Escape fechar;
- clique no overlay fechar;
- foco regressar ao botão de origem.

- [ ] **Step 2: Executar o teste antes da implementação**

Run: `npm run test:unit -- resources/js/components/StudentAvatar.test.ts`

Expected: FAIL porque `zoomable`, `studentName` e o Dialog ainda não existem.

- [ ] **Step 3: Implementar o trigger e o Dialog**

No `StudentAvatar.vue`:

1. Importar `ref`/`watch` já usados, `Dialog`, `DialogContent` e `DialogClose` das pastas existentes.
2. Adicionar as props opcionais com defaults `zoomable: false` e `studentName: ''`.
3. Derivar `isInteractive` de `zoomable && showPhoto`.
4. Guardar uma referência ao botão e controlar `dialogOpen`.
5. Renderizar a thumbnail dentro de `<button type="button">` apenas quando `isInteractive`; manter o `<span>` atual nos restantes casos.
6. Usar `@click="dialogOpen = true"` e deixar a semântica nativa tratar Enter/Space.
7. Renderizar o Dialog com `v-model:open`, imagem usando exatamente `photoUrl`, `alt` com o nome, `object-contain`, `max-h-[calc(100vh-5rem)]`, `max-w-[calc(100vw-2rem)]` e botão X acessível.
8. No fecho, aguardar a atualização do Dialog e chamar `.focus()` no trigger ainda montado.
9. Limpar/fechar o estado se `photoUrl` mudar para nulo ou falhar.

- [ ] **Step 4: Executar os testes do componente**

Run: `npm run test:unit -- resources/js/components/StudentAvatar.test.ts`

Expected: PASS for all trigger, accessibility, close, no-photo, and focus-return cases.

- [ ] **Step 5: Commitar a unidade**

```powershell
git add resources/js/components/StudentAvatar.vue resources/js/components/StudentAvatar.test.ts
git commit -m "feat: ampliar fotografia do aluno no avatar"
```

### Task 2: Ativar explicitamente o zoom nos usos de fotografias de alunos

**Files:**
- Modify: `resources/js/pages/classes/Show.vue`
- Modify: `resources/js/pages/students/Index.vue`
- Modify: `resources/js/pages/instruments/Grid.vue`
- Modify: `resources/js/pages/assessments/Show.vue`
- Modify: `resources/js/pages/classifications/Show.vue`
- Modify: `resources/js/pages/results/Show.vue`

**Interfaces:**
- Consumes: `StudentAvatar` com `photo-url`, `studentName` derivado da linha atual e `zoomable`.
- Produces: comportamento uniforme de ampliação em todas as fotografias reais já renderizadas.

- [ ] **Step 1: Atualizar cada uso com props explícitas**

Em cada ocorrência, passar o nome da mesma entidade que aparece ao lado da thumbnail:

```vue
<StudentAvatar
    :photo-url="student.photo_url"
    :student-name="student.name"
    zoomable
    size="xs"
/>
```

Preservar todas as classes, tamanhos e condições existentes. Nos dois casos de `instruments/Grid.vue`/listas, usar o objeto local (`student` ou equivalente); não alterar o payload.

- [ ] **Step 2: Executar os testes de páginas existentes**

Run: `npm run test:unit -- resources/js/pages/classes/Show.test.ts resources/js/pages/students/Index.test.ts resources/js/pages/evaluation-sheets/Show.test.ts`

Expected: PASS, sem mudanças de layout ou regressões nos snapshots/asserções existentes.

- [ ] **Step 3: Verificar referências**

Run: `rg -n "StudentAvatar" resources/js`

Expected: cada uso de fotografia real passa `zoomable` e `studentName`; nenhum `components/ui/avatar/*` foi alterado.

- [ ] **Step 4: Commitar a integração**

```powershell
git add resources/js/pages/classes/Show.vue resources/js/pages/students/Index.vue resources/js/pages/instruments/Grid.vue resources/js/pages/assessments/Show.vue resources/js/pages/classifications/Show.vue resources/js/pages/results/Show.vue
git commit -m "feat: ativar zoom nas fotografias das turmas"
```

### Task 3: Executar gates frontend e validar responsividade

**Files:**
- No código adicional esperado; corrigir apenas problemas diretamente causados pelas Tasks 1–2.

- [ ] **Step 1: Executar lint**

Run: `npm run lint:check`

Expected: exit code 0.

- [ ] **Step 2: Executar verificação TypeScript**

Run: `npm run types:check`

Expected: exit code 0, sem erros Vue/TypeScript.

- [ ] **Step 3: Executar Vitest completo**

Run: `npm run test:unit`

Expected: todos os testes passam.

- [ ] **Step 4: Executar build**

Run: `npm run build`

Expected: build Vite e geração Wayfinder terminam com exit code 0.

- [ ] **Step 5: Verificar no browser desktop e mobile**

Arrancar o ambiente existente e validar manualmente:

1. Abrir uma página com aluno que tenha fotografia.
2. Confirmar que a thumbnail mantém o tamanho e cursor de interação.
3. Clicar e confirmar imagem centrada, overlay escuro, sem download/edição.
4. Fechar por X, Esc e clique fora; confirmar retorno do foco.
5. Repetir com teclado (Tab, Enter e Space).
6. Confirmar que aluno sem foto não abre nada.
7. Em viewport de aproximadamente 390px, confirmar que a imagem e o X cabem sem overflow.

- [ ] **Step 6: Executar gate PHP/documentar o resultado**

Run: `composer ci:check`

Expected: exit code 0. Se algum subgate pré-existente falhar, registar o comando e a falha sem alterar backend não relacionado.

- [ ] **Step 7: Commitar correções de verificação, se existirem**

```powershell
git add resources/js/components/StudentAvatar.vue resources/js/components/StudentAvatar.test.ts resources/js/pages
git commit -m "fix: validar ampliação responsiva da foto do aluno"
```

Só criar este commit se houver correções após os gates; não criar commit vazio.
