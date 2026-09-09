# Importar e corrigir a lista de alunos e as fotografias

Este documento descreve o fluxo de **turma** — a Relação de Turma (Excel,
modelo EB058e) e a folha de fotografias (Word, modelo EB019), ambas exportadas
do Intuitivo ou compatível.

> Não confundir com [docs/data-import.md](data-import.md), que é o restauro de
> um **backup da organização** e não partilha código nenhum com este fluxo.

## Princípio

**Corrigir uma importação nunca pode exigir apagar a turma.** Uma turma
importada com o ficheiro errado — ou com a opção «colocar o nome ao lado da
foto» por marcar — corrige-se por cima do que já existe. Apagar alunos para
recomeçar levaria atrás tudo o que está pendurado no registo de cada um:
resultados, classificações, autoavaliações, registos pedagógicos, intervenções
e evidências. O `pseudonym_code` de que tudo isso depende nunca é reescrito.

## Três passos, uma escrita

Os dois pontos de partida — «Importar lista» e «Adicionar ou corrigir fotos» —
convergem na **mesma** página de pré-visualização e no **mesmo** `confirm()`.
Não há um segundo assistente, e portanto não há um segundo sítio onde a
fotografia de uma criança possa ficar esquecida em disco.

1. **Carregamento** — `POST classes/{class}/roster-imports` (Excel) ou
   `POST classes/{class}/photos` (Word). Lê e valida o ficheiro, cria um
   *token* que é apenas o nome de uma pasta em disco privado
   (`roster-imports/{token}/`), e estagia lá as imagens.
   **Nada é escrito na base de dados.**
2. **Pré-visualização** — a página mostra, linha a linha, o que vai acontecer:
   reconhecidos, a inscrever, nomes a corrigir, fotos a associar, fotos a
   substituir, ambiguidades, duplicados e linhas ignoradas. O professor
   desmarca, corrige nomes e atribui fotos à mão. **Continua a não escrever
   nada.** «Escolher outro ficheiro» apaga a pasta no próprio pedido.
3. **Confirmação** — `POST classes/{class}/roster-imports/{token}/confirm`.
   É o único ponto que escreve, e a pasta temporária é apagada no `finally`,
   aconteça o que acontecer.

Fora deste fluxo, `roster-imports:prune` varre de hora a hora as pastas
abandonadas por quem simplesmente fechou o separador — ver
[docs/data-lifecycle.md](data-lifecycle.md).

## Correspondência: identificadores primeiro

`App\Services\Import\MatchRosterToEnrollments` decide a que aluno da turma uma
linha pertence, por esta ordem:

1. **N.º de processo** (`student_identities.school_number`) — o identificador
   que a escola dá ao aluno. Um nome corrige-se; um n.º de processo não.
2. **Nome normalizado**, e só quando **exatamente uma** inscrição responde.

Se mais do que uma inscrição responder à mesma evidência, a linha é
`ambiguous`: fica **fora** da importação, e os candidatos viajam para a
pré-visualização, onde o professor aponta o certo — ou diz que é um aluno novo.
O sistema propõe; não decide (§3.3).

`school_number` e `display_name` estão cifrados em repouso (ADR-0004), por isso
nenhum dos dois se compara em SQL: a pauta inteira é lida **uma vez** para dois
índices em memória. Trinta alunos é o que torna isso barato, e as inscrições
são lidas através da turma, pelo que o âmbito da organização já está aplicado
(ADR-0002). São lidas **todas** as inscrições, não só as ativas — um aluno que
saiu e reaparece no ficheiro corrigido da escola é o mesmo aluno.

### Nunca duplicar em silêncio

Duas linhas que apontem à **mesma** inscrição — mesmo com nomes diferentes,
por um n.º de processo repetido — são marcadas como duplicadas e nenhuma
entra. A marcação é apresentação; quem recusa é o servidor: `confirm()` escreve
cada inscrição **uma vez só por pedido**, mesmo que o cliente envie o mesmo
`enrollment_id` duas vezes.

## Fotografias

- Uma foto **sem legenda nunca se associa sozinha a ninguém.** Um EB019
  exportado sem a opção «colocar o nome ao lado da foto» traz as imagens e
  nenhum nome; elas chegam à pré-visualização para serem atribuídas à mão.
  Associar pela posição na grelha seria inventar uma correspondência.
- A legenda corresponde ao nome por **subsequência de palavras** em qualquer
  das direções — a folha de fotos traz «Afonso Mordomo» onde o Excel traz
  «Afonso Pito Mordomo».
- Escrever é sempre `StudentPhotoService`, o mesmo escritor único do caminho
  manual de um aluno: aponta a identidade ao ficheiro novo e só **depois**
  larga o antigo, pelo que substituir não deixa nada órfão nem apaga um
  ficheiro ainda referenciado. Disco privado, nunca `public`.
- O `token` nomeia um sítio em disco, não diz de quem é: `belongsToClass()` é
  verificado antes de cada leitura da pasta.

## Remover um aluno

Não é a forma de corrigir uma importação, e a aplicação não a apresenta como
tal. Continua a existir para a inscrição genuinamente enganada, com as regras
da 0.138.2 intactas:

- Sem história pedagógica: apaga-se, e só as pertenças a grupos do horário
  saem com ela.
- Com história: **recusada**, com uma frase que diz o que lá está. Nada é
  apagado em cascata para o botão poder funcionar.
- Já removida (segundo clique, separador antigo): respondida com «Este aluno
  já não está nesta turma» — o estado final é o pedido. `enrollments` não tem
  soft delete, pelo que o ULID fica irresolúvel, e o 404 cru que isso produzia
  não descrevia nada de útil.
- ULID de **outra turma** da mesma organização: continua a ser 404. Confirmar
  que aquela inscrição existe seria dizer mais do que quem pergunta tem
  direito a saber (ADR-0002).
