# Ampliação da fotografia do aluno — desenho

## Objetivo

Permitir que o professor veja uma fotografia de aluno em maior dimensão sem
sair da página, mantendo a thumbnail atual e reutilizando o mecanismo
autorizado que já serve a imagem.

## Âmbito

O comportamento será implementado no componente reutilizável
`resources/js/components/StudentAvatar.vue`, mas será opt-in através da prop
`zoomable`. Os usos existentes que representam fotografias reais de alunos
passarão também o `studentName` e `zoomable`; avatares genéricos da aplicação
ficam inalterados.

Locais abrangidos:

- página da turma;
- diretório de alunos;
- grelhas de instrumentos;
- avaliações;
- classificações;
- resultados.

Não haverá alterações no backend, storage, importação, remoção de fotografias,
URLs, landing ou módulos pedagógicos.

## Arquitetura e fluxo

`StudentAvatar` continuará a mostrar a thumbnail e o fallback atual. Quando
`zoomable` for verdadeiro, `photoUrl` existir e a imagem não tiver falhado, a
thumbnail será um botão acessível. O clique ou a ativação por Enter/Space abre
um `Dialog` controlado pelo componente, usando a mesma `photoUrl` autorizada.

O `Dialog` existente fornece portal, overlay, foco, Esc, clique fora e botão
de fecho. O conteúdo será uma imagem sem ações adicionais, centrada e limitada
ao viewport (`max-width`/`max-height`), preservando a proporção. Não será
introduzida qualquer URL nova ou persistência.

Se a imagem falhar ao carregar, o componente mantém o fallback e não permite
abrir a ampliação. A prop `photoUrl` continua a ser a única origem da imagem.

## Acessibilidade e interação

- O botão terá `aria-label="Ampliar fotografia de <nome>"`.
- A thumbnail sem fotografia continuará não interativa.
- O Dialog bloqueará a interação com o fundo.
- O botão X terá nome acessível em pt-PT.
- Esc, clique fora e X fecham o Dialog.
- O foco regressa ao botão que abriu a ampliação.
- A imagem ampliada terá `alt="Fotografia de <nome>"`.

## Mobile

O conteúdo usará dimensões relativas ao viewport, com margens seguras para o
botão X. A imagem manterá a proporção com `object-contain`, sem overflow em
viewports estreitos (incluindo aproximadamente 390px).

## Testes

Os testes Vitest do componente/página cobrirão:

- abertura com uma fotografia existente;
- ausência de abertura sem fotografia;
- abertura por teclado;
- imagem correta e respetivo nome acessível;
- fecho pelo X;
- fecho por Esc;
- fecho ao clicar fora;
- manutenção do layout e dos usos existentes.

Após a implementação serão executados ESLint, `vue-tsc`, Vitest e build. Como
não há alteração no backend, os gates PHP serão avaliados apenas se o estado
do repositório os exigir.
