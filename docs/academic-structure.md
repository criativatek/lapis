# Estrutura do Ano Letivo — Configuração vs. Contexto

Fatia 5. Este documento explica uma regra de navegação nova, e por que ela não
mudou nenhum domínio, rota ou policy por baixo.

## A regra

> **Configuração** = onde se define a estrutura.
> **Topo (context bar)** = onde se escolhe o contexto de trabalho.

Ano letivo e Disciplina são **entidades estruturais**: criam-se e editam-se em
Configuração. Continuam a aparecer no topo — mas só para **selecionar**,
nunca para criar. Turma continua a criar-se em Turmas e Alunos (decisão já
tomada na Fatia 4, preservada aqui sem alteração — §50). Período configura-se
dentro do seu ano letivo (ver "Períodos" abaixo).

## O que já existia, e não foi tocado

`AcademicYear` e `Subject` já eram entidades completas antes desta fatia —
models, controllers (`AcademicYearController`, `SubjectController`),
policies (`AcademicYearPolicy`, `SubjectPolicy`), `FormRequest`s, páginas Vue
(`academic-years/{Index,Create,Edit,Form}.vue`, `subjects/Index.vue`), e as
rotas `/academic-years` e `/subjects`. Nada disto mudou nesta fatia:

- **Nenhuma rota nova.** `academic-years.index` e `subjects.index`
  continuam exatamente onde estavam.
- **Nenhum controller novo, nenhuma policy nova.** A regra de autorização
  já existente é a mesma: leitura para qualquer membro; escrita só para
  `ownsCurrentOrganization()` — owner institucional escreve, member lê,
  numa organização pessoal o dono é a única pessoa, por isso a distinção
  colapsa naturalmente.

O que faltava era **descoberta**: estas páginas só eram alcançáveis a partir
de um link no seletor de contexto do topo (`ContextBar.vue`), nunca a partir
da barra lateral. Um professor sem nenhum ano letivo ainda configurado não
tinha onde ser guiado.

## O que esta fatia acrescentou

1. **Uma entrada na barra lateral**, em Configuração, antes de "Perfis de
   Avaliação": `config/navigation.php`, chave `academic-structure`,
   `module => null` (disponível em todos os planos, tal como já acontecia
   com o acesso direto via topo — não é uma capacidade nova, é a mesma
   tornada visível). Aponta para a rota já existente `academic-years.index`.

2. **Uma tira de separadores partilhada**, `resources/js/components/AcademicStructureTabs.vue`
   ("Anos letivos" / "Disciplinas"), inserida no topo de
   `academic-years/Index.vue` e `subjects/Index.vue`. Não é uma página
   agregadora nova — é um componente fino sobre as duas páginas que já
   existiam, para que mudar de uma para a outra pareça uma única área.

3. **Cópia mais útil no seletor do topo** (`ContextBar.vue`): quando não
   existe nenhum ano letivo ou nenhuma disciplina, mostra "Sem ano letivo
   configurado" / "Sem disciplinas configuradas" em vez de um traço vazio —
   o link para configurar já existia, só o texto ficou mais claro (§48-§49).

4. **`scope.academicYear` deixou de estar sempre `null`.** O prop partilhado
   já existia (`HandleInertiaRequests::share()`) mas estava fixo a `null`
   com um comentário "Empty until the academic model lands in Fase 1" —
   desatualizado, porque o modelo académico já existia. Passou a usar a
   mesma heurística já testada de `AcademicYearRetentionClassifier::currentYearFor()`
   (um `Active` quando existe exatamente um; senão o mais recente por
   `starts_on`) — só leitura, nunca inventa dados.

## Períodos — sem separador próprio

Um período (`AcademicPeriod`) já se cria e edita **dentro** do formulário do
seu ano letivo (`academic-years/Form.vue`), não como catálogo autónomo como
`Subject`. Dar-lhe um terceiro separador em "Estrutura do Ano Letivo"
duplicaria essa UI em vez de a uniformizar (§51) — por isso não existe. A
gestão de períodos permanece exatamente onde já estava.

## O que não mudou

- Nenhuma policy foi alterada "para facilitar" a UX (§57) — um membro
  institucional continua a ver sem poder escrever, com a mesma cópia já
  estabelecida na Fatia 1 ("Gerido pelo responsável da organização").
- Nenhuma rota histórica foi removida ou redirecionada — só ficou mais fácil
  de encontrar a que já existia (§56).
- Configurações pessoais (`/settings/*`) e Perfis de Avaliação continuam
  onde estavam — "Estrutura do Ano Letivo" não os absorveu (§59).
