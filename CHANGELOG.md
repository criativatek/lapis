# Changelog — Lapispro

Formato: [Keep a Changelog](https://keepachangelog.com/pt-BR/1.1.0/). Versão semântica pré-1.0 enquanto as fases são construídas.

> **Nota de marca.** O produto chamou-se LÁPIS até à versão 0.78.0. As entradas
> anteriores a 0.79.0 mantêm o nome com que foram escritas: um changelog é um
> registo do que aconteceu, e reescrevê-lo apagaria a própria mudança de marca.

> **Nota de reconciliação técnica (0.85.0).** Existiram **duas versões 0.82.0
> distintas**, em linhagens paralelas que nunca se viram: uma comercial, que
> chegou a produção, e uma de pesquisa do Centro de Ajuda, sobre a qual seguiram
> a 0.83.0 e a 0.84.0. Não eram o mesmo release e não são aqui apresentadas como
> se fossem — os dois corpos estão preservados abaixo sob a entrada 0.82.0,
> cada um sob o título da frente a que pertenceu. A 0.85.0 é o primeiro
> release em que as duas linhagens voltam a ser uma só.

> **Nota de reconciliação (0.99.5).** Entre 2026-08-29 e 2026-08-30 correram
> **duas linhas em paralelo** a partir da 0.88.0: uma de produto (0.89.0 →
> 0.91.0 — condições comerciais e lugares Fundador, diretório de alunos, contas
> de teste), publicada e instalada em produção; e uma do site público (0.89.0 →
> 0.99.4 — landing, multi-página, SSR, SEO). Os números repetem-se porque cada
> linha os atribuiu sem ver a outra. A 0.99.5 funde as duas. As entradas da
> linha do produto ficam com os números com que foram publicadas e instaladas
> (0.89.0, 0.90.0, 0.91.0); as da linha do site, que nunca saíram desta
> máquina, foram renumeradas para **0.91.1 a 0.91.4** — um número de versão é
> único por definição, e `ReleaseVersionTest` afirma-o.

## [0.99.5] — 2026-08-30

Fusão das duas linhas (ver nota acima). Nada foi reescrito: o site público
desta linha, com as funcionalidades da outra. Uma ligação nova: `/planos`
recebe do servidor se a condição Membro Fundador ainda está aberta
(`FounderAvailability`), tal como a landing antiga recebia, e esconde o
distintivo e a banda Fundador quando fecha.

Uma revisão adversarial da fusão (37 agentes, três lentes, cada achado
sujeito a dois céticos) confirmou doze problemas, todos corrigidos aqui:

- **O hero de `/planos` prometia a condição Fundador mesmo depois de fechada.**
  O distintivo, a banda e a resposta do FAQ já estavam presos ao
  `founder.open`; o primeiro ecrã não estava, e a descrição de SEO também não
  — um snippet sobrevive à promoção. Agora o texto e os chips do hero mudam
  com a condição, e a meta description não a nomeia.
- **A home dizia «sem prazo» sobre o plano Base**, que é gratuito *no ano
  letivo 2026/27* — a única frase materialmente falsa que restava no site.
- **`/seguranca` prometia gestão de sessões e dispositivos**, que não existe.
- Os `Offer` do JSON-LD apontavam para `#planos`, âncora que a home deixou de
  ter na 0.94.0; passam a apontar para `/planos`.
- `Welcome.vue` declarava `plans` e `founder` que ninguém enviava nem lia, e o
  `HomeController` ainda fazia a consulta dos planos por nada. Os testes de
  payload dos planos passam para `/planos`, que é onde eles vivem.
- `commercial.test.ts` montava o FAQ sem a prop obrigatória `founderOpen`.
- `og.jpg` era o hero da 0.93; regenerada a partir do hero actual.
- O CHANGELOG repetia 0.89.0, 0.90.0 e 0.91.0 (uma vez por linha): as da linha
  do produto ficam com o número publicado, as do site passam a 0.91.1–0.91.4.

## [0.99.4] — 2026-08-30

Revisão técnica das nove páginas públicas (headings, alt, alvos de toque,
tamanhos de texto, metas, dados estruturados) e o que dela saiu:

- **JSON-LD em todas as páginas**, não só na home: `WebPage` +
  `BreadcrumbList` (`PublicPages::structuredData()`), e `Organization` em
  `/sobre` com a entidade real de `LegalDocuments::controller()` — nome legal,
  NIF, morada, e-mail. Sem `aggregateRating`, sem perfis sociais inventados;
  `MarketingPagesTest` valida o JSON de cada página.
- `site.webmanifest` + ícones 192/512 (do apple-touch-icon) + `theme-color`.
- Alvos de toque: botão do menu 44px; pills de páginas relacionadas e de
  documentos legais mais altas.
- Notas de preço de 12px para 13px; `/planos` sem salto h1→h3 (h2 invisível
  quando o cabeçalho da secção é o do hero).
- Confirmado: 1 `<h1>` por página, alts descritivos em todas as fotografias e
  capturas, sem scroll horizontal a 390px, 10 metas OG por página.

## [0.99.3] — 2026-08-30

Nas páginas sem faixa de factos (funcionalidades, planos, segurança, sobre) o
cartão do hero colava à faixa navy. `PageHero` passa a ter margem inferior
própria; a home compensa-a na faixa de factos.

## [0.99.2] — 2026-08-30

A faixa de factos deixa de estar sobreposta à base do cartão do hero — meio
em cima do cartão, meio colada à faixa navy, «empoleirada». Passa a uma fila
própria abaixo do hero, com respiro antes da faixa de produto.

## [0.99.1] — 2026-08-30

A grelha bento da home tinha o cartão «Avaliação de alunos» em 2×2 com 60% de
branco e a captura escondida no canto — «empoleirado», nas palavras do Pedro.
Passa a 2×1 com a captura a preencher a metade direita até ao fundo; «Planos»
vira uma faixa horizontal a fechar a grelha, com botão.

## [0.99.0] — 2026-08-30

Passagem de `/polish` e uma armadilha de SSR apanhada pelo caminho.

- **Com o SSR ligado, o `<title>` servido é o do `<Head>` do Vue, não o do
  blade.** As páginas de funcionalidades diziam «Avaliação de alunos |
  Lapispro» ao crawler em vez do título de SEO declarado em `PublicPages`.
  Agora o servidor passa `seoTitle` (o mesmo string) a cada página de
  marketing, e `MarketingPagesTest` afirma que coincidem. Os testes correm com
  o SSR desligado (`INERTIA_SSR_ENABLED=false` no `phpunit.xml`): um servidor
  SSR a correr na máquina fazia falhar três asserções sobre o `<title>`.
- Navegação com seis páginas na barra (entra «Aulas»); Relatórios e Sobre no
  menu móvel e no rodapé.
- `LandingSection`, FAQ e IA alinhados ao tratamento novo: eyebrow sem régua,
  título maior e mais apertado, cartão `card-soft`.
- Faixa de factos toda numérica e verdadeira: 3 regras · 0 nomes chegam à IA ·
  2 fatores + passkeys · 0 €.
- Rodapé com a entidade e a morada (prop partilhado `legalEntity`, da mesma
  fonte que a Política de Privacidade); escondido até estar configurado.

## [0.98.0] — 2026-08-30

Correcções do `/critique` (crítica de design contra o `.impeccable.md`):

- **/planos tem um hero a sério** (`PageHero` com a fotografia do
  planificador, título bicolor, chips); `LandingPricing` ganha `headless` para
  não repetir o cabeçalho por baixo.
- **O único movimento da página**: no cartão do hero, o «confirmar» da última
  linha vira o «5» do professor quando o cartão entra no ecrã — a proposta a
  tornar-se decisão, uma vez, 900ms, e nunca com `prefers-reduced-motion`
  (`HeroCard`, keyframe `pop`).
- **/seguranca deixa de dizer o mesmo duas vezes**: sai `LandingSecurity`
  (as seis medidas já o diziam).
- **Tiles bento com desenho próprio** (`TileArt`): lista de alunos, linha de
  evolução, horário, relatório — em CSS/SVG, sem dados nem afirmações.
- **A linha azul do H1 é sempre uma frase completa**, ou não existe: revistos
  os títulos de Avaliação, Turmas, Aulas, Relatórios e Segurança.
- **Telemóvel**: a fotografia do hero fica acima do cartão em vez de debaixo
  dele — tapava as mãos.

## [0.97.0] — 2026-08-30

Passagem de design com o ui-ux-pro-max («falta um pouco mais design»). A
recomendação para um produto de educação foi «bento grid + soft UI»: cantos de
20–24px, profundidade por duas camadas de sombra em vez de bordas, elevação
por `transform` no hover, fundos off-white, cartões de tamanhos diferentes.

- Utilitários novos em `app.css` (só para as páginas públicas): `card-soft`,
  `card-soft-hover`, `clay-disc` (disco de ícone com gradiente e sombra
  interior), `dots-pattern`, `glow-blue`.
- Home: grelha **bento** — «Avaliação de alunos» ocupa 2×2 com uma fatia da
  grelha real; a faixa de factos sobrepõe a base do cartão do hero.
- `ScreenFrame` ganha a barra de browser (três pontos + barra de endereço).
- Ícones em discos «clay» nos cartões, factos e passos.
- CTA primário em pill com brilho azul (`LANDING_PRIMARY`); botões do CTA
  final e «Ver como funciona» em pill.
- Faixas âmbar e esmeralda com padrão de pontos; títulos de secção maiores e
  mais apertados.

## [0.96.0] — 2026-08-30

Segunda ronda de feedback do ChatGPT sobre a home («7,5/10 — agora é
polimento, confiança e humanização»). Entrou o que se pode fazer com verdade;
ficou de fora o que exigia dados que não existem: **números de prova social**
(«+120 escolas») e **testemunhos** com nome e escola. Quando houver professores
reais a dizê-lo, entram — não antes.

> Nota: o commit 0.95.0 foi feito por outra sessão a meio deste trabalho e
> arrastou parte destas alterações (paleta navy, `HeroCard` em grelha,
> `PhotoBand prominent`). O changelog dessa versão descreve o cartão «Olá,
> Professora Ana» e a foto em círculo, que já não existem. Esta entrada é a
> descrição fiel do estado da página.

- **Paleta azul institucional.** A faixa do produto e o CTA final deixam o
  royal blue (`blue-600`) por um degradê navy `#1E4AB0 → #183B8F → #102A56`
  (`ColorBand`, `LandingFinalCta`). Os botões de ação continuam `blue-600`.
- **Hero com mais produto.** Coluna da fotografia ligeiramente mais larga e,
  sobre ela, uma fatia real da grelha (três alunos por pseudónimo, três
  domínios, «—» para não aplicável, proposta e nível — um «por confirmar»)
  em vez do cartão «resumo de hoje» (`HeroCard`, `PageHero`).
- **Mais pessoas.** Duas fotografias novas, geradas, sem caras
  reconhecíveis: professora inclinada sobre o trabalho de três alunos
  (`teacher-students.webp`, no CTA final — o CTA vende o resultado humano,
  não o caderno) e professora a consultar o portátil numa sala de professores
  (`teacher-laptop.webp`, em «O sistema propõe»).
- **«O sistema propõe. O professor decide.» com protagonismo.** `PhotoBand`
  ganha `prominent`: fundo `slate-50`, título maior, fotografia 4:3 com
  sombra e mais largura.
- **IA a funcionar, não explicada.** O cartão da direita passa a ser um mock
  de «Sugestão de próximo passo · Aluno A07» — leitura dos resultados, proposta
  e os botões «Aplicar sugestão · Editar · Descartar» — com «IA sugere.
  Professor decide.» como legenda. Pseudónimo, não nome: a IA nunca recebe
  um (`LandingAi`). Título: «IA para professores. O professor mantém sempre a
  decisão final.»
- **Menos ar entre secções.** Espaçamento vertical reduzido ~20–25 % em
  `ColorBand`, `LandingSection`, `PhotoBand` e no CTA final.

## [0.95.0] — 2026-08-29

Afinação visual sobre uma referência que o Pedro trouxe (um mockup do
ChatGPT — «esquece o conteúdo, apenas referência visual»). Do mockup entrou o
layout; do mockup NÃO entrou nada do que inventava: «+120 escolas»,
«99,9% disponibilidade», brasões de escolas, caras de crianças, «Assiduidade»,
«Comunicação com encarregados», blog, webinars, telefone. O site não inventa
prova social nem funcionalidades.

- Hero dentro de um cartão arredondado cinza-claro, fotografia a sangrar até
  à borda, cartão «Olá, Professora Ana» com o resumo do dia (dados do cenário
  demo) sobreposto — `PageHero` + `HeroCard`.
- Título em duas cores: a posição em tinta, a promessa em azul
  (`titleAccent`, também nas cinco páginas de funcionalidades, Segurança e
  Sobre).
- Chips de confiança sob os botões: «Sem cartão · RGPD desde a arquitetura ·
  O professor decide».
- Faixa de quatro factos verificáveis por baixo do hero, no lugar onde o
  mockup punha estatísticas: 3 regras de cálculo, pseudónimos, 2FA + passkeys,
  0 € no Base.
- Passos com marcadores redondos azuis e linha tracejada.
- Segurança na home com três cartões ao lado do título.
- CTA final num cartão navy arredondado dentro da medida, com fotografia em
  círculo (só na home) e chips.

## [0.94.0] — 2026-08-29

**O site público deixa de ser uma página só.** O Pedro achou o layout «muito
fraco» e pediu várias páginas e uma direcção «humana, com cor» (fotografia,
blocos de cor cheia, formas arredondadas — Canva for Education, não Linear).
Nove páginas indexáveis, cada uma com o seu H1, título e descrição
(`App\Support\Seo\PublicPages`, a única lista; o blade, o sitemap, o
robots.txt, o bloqueio de tema claro e `MarketingPagesTest` lêem-na):

- `/` — porta de entrada: hero com fotografia, faixa azul com a grelha real,
  seis cartões para as áreas, como funciona, «o professor decide» com
  fotografia, IA e regras, segurança, CTA.
- `/funcionalidades/{avaliacao,turmas,acompanhamento,aulas-e-sumarios,relatorios}`
  — um componente (`marketing/Feature.vue`), cinco textos
  (`components/marketing/features.ts`), cada um com captura real e fotografia.
- `/planos` — os mesmos componentes de preços, comparação, voucher e FAQ.
- `/seguranca` — as seis medidas, a secção existente e os documentos legais.
- `/sobre` — princípios e contacto, com a entidade de `LegalDocuments::controller()`
  (a mesma da Política de Privacidade).

Fotografias geradas por IA sem rostos identificáveis (mãos, sala vazia,
professora de costas, mesa) em `public/images/marketing/`, WebP 1600px.
Peças novas em `components/marketing/`: `MarketingShell`, `PageHero`,
`ColorBand` (azul cheio / âmbar / esmeralda), `ScreenFrame`, `BenefitCard`,
`PhotoBand`. A navegação passa a páginas reais (`navigation.ts`), com a
página actual assinalada no cabeçalho. `PlanCards` sai do `HomeController`
para ser partilhado com `/planos`. Saem `LandingHero`, `LandingFeatures` e os
mocks em CSS que só eles usavam. Home no telemóvel: 14 700px → 9 300px.

## [0.93.0] — 2026-08-29

**Render no servidor** para a landing e as páginas legais. A resposta de `/`
era uma casca de 14 KB sem `<h1>`, sem texto e sem links — tudo chegava com o
JavaScript, que o Google renderiza tarde e com orçamento e que Bing, LinkedIn,
WhatsApp e os bots de LLM não renderizam. Era o achado n.º 1 da auditoria de
SEO. Agora `resources/js/ssr.ts` (modo auto do Inertia 3: o plugin do Vite
embrulha o `createInertiaApp` em `createServer`) corre como
`php artisan inertia:start-ssr`; título e layout ficam num módulo partilhado
(`resources/js/inertia.ts`) para o cliente e o servidor não divergirem.
`RevealOnScroll` passa a nascer visível — no HTML do servidor o texto não pode
estar em `opacity-0` — e só se esconde, já no cliente, o que está abaixo da
dobra. `bootstrap/ssr` entra no pacote de release. Sem o processo Node o
Inertia cai para render no cliente: página igual, só o crawler perde.

**og:image.** O hero real, 1200×630 (`public/images/landing/og.jpg`), em
`og:image`, `twitter:image` (`summary_large_image`) e na `image` do JSON-LD;
as páginas legais partilham-no. Regenerar quando o hero mudar.

## [0.92.0] — 2026-08-29

Landing mais curta: 17 secções passam a 10, e a página passa de ~22 600px para
~14 700px no telemóvel (15 600 → 9 400 no desktop). Os Planos aparecem ao
sexto bloco, não ao décimo segundo. Base: a revisão com o ui-ux-pro-max
(«Minimal single column» — CTA único, muito branco, poucas secções, contacto
visível) e a leitura das capturas full-page.

- **Saíram** «O problema», «Porquê Lapispro?», «O modelo da sua escola»,
  «O dia a dia» e «O que ganha»: repetiam, com outro título, o que o hero e
  Funcionalidades já diziam.
- **Fundiram-se** «O cálculo» (as três regras) em «Inteligência artificial»:
  são a mesma promessa vista de dois lados — o número é determinístico, o
  modelo lê, o professor decide.
- «O dia a dia» virou a tab **Planear** em Funcionalidades, com captura real
  do calendário. Funcionalidades vem logo a seguir ao hero.
- O **hero mostra a grelha de resultados real**, não o mock em CSS.
- **FAQ** de 18 perguntas para 8 (as de intenção de compra e de confiança).
- **Comparação** fechada por omissão (`<details>` nativo): trinta linhas são
  para quem já está a escolher.
- O **CTA final é a única faixa de cor cheia** da página (`blue-600`, texto
  branco) — o azul deixa de ser só botões.
- O rodapé mostra o **e-mail de contacto** das definições da plataforma quando
  existe: uma escola que confia dados de menores procura quem está por trás.

## [0.91.4] — 2026-08-29

Landing com o produto a sério. As secções «Organizar», «Avaliar» e
«Acompanhar» de Funcionalidades mostram agora capturas reais da aplicação —
turma, grelha de resultados e quadro síntese — em vez de mocks em CSS. As
capturas vêm do cenário do `DemoDataSeeder` (alunos fictícios, §26.6), a
2×, recortadas à área de conteúdo, WebP de 48–87 KB em
`public/images/landing/`. «Intervir» e «Documentar» mantêm o mock: nos dados
demo esses ecrãs ainda são formulários vazios, e um formulário vazio vende
menos do que um desenho. O hero também mantém o mock, porque é o único que
mostra proposta e decisão lado a lado.

Saiu a linha descritiva de quatro linhas ao lado do logótipo no cabeçalho.

## [0.91.3] — 2026-08-29

Landing: uma cor de acção. A 0.89.0 pintou de azul o hero e o cabeçalho e
deixou os botões dos Planos, do FAQ e do fecho em navy, e o cartão Fundador em
creme — duas paletas na mesma página. Agora há `LANDING_PRIMARY` em `chrome.ts`
e todos os CTAs passam por ele; o cartão Fundador, a nota Fundador no cartão
Pro e o separador activo de «Funcionalidades» também são azuis. O `primary`
navy da aplicação não muda.

Corrigido o scroll horizontal no telemóvel: a 375px o cabeçalho media 424px
(logo + «Experimentar Lapispro» + botão do menu). Abaixo de `sm` o botão diz
só «Experimentar».

## [0.91.2] — 2026-08-29

A landing e as páginas legais são só claras. Com o sistema em modo escuro, a
0.89.0 aparecia a preto — o Pedro viu-a assim e perguntou «assim?». Marketing
tem um tema; o escuro é uma preferência para trabalhar dentro da aplicação.
O servidor põe `data-theme-lock="light"` no `<html>` para `Welcome` e
`legal/Document` e nunca aplica `dark`; `updateTheme()` respeita o bloqueio;
`useLightThemeLock()` levanta-o ao sair por Inertia para que o painel volte à
preferência real sem reload.

## [0.91.1] — 2026-08-29

Landing pública com base branca. O creme «papel» do cabeçalho, rodapé e das
secções `warm`, e a alternância cinzenta das secções `tinted`, davam à página
um peso que não vinha do conteúdo — o Pedro chamou-lhe «muito mau», e uma
auditoria independente (Codex) chegou à mesma conclusão: competente, mas
monótono e pesado. Esta versão é o primeiro passo da direcção
«caderno branco»: fundo branco em todo o lado, azul (`blue-600`) como cor de
acção (CTAs do hero e do cabeçalho, eyebrows e réguas das secções), a faixa
navy de «O que ganha» passou a azul-claro, e o wash âmbar do hero passou a
azul. A paleta quente sobrevive apenas em modo escuro, onde continua a fazer
sentido. Ficam para os passos seguintes: screenshots reais do produto,
fotografia, e a redução de 17 para ~10 secções.

- `chrome.ts`: `CHROME_SURFACE` e companhia em branco/tokens neutros no tema claro.
- `LandingSection.vue`: `tinted` deixa de pintar; `warm` passa a `blue-50/60`.
- `LandingBenefits.vue`: faixa navy → azul-claro em tema claro.
- Cores do cartão Fundador e dos botões de Planos/CTA final ainda navy/creme — próximo passo.
## [0.91.0] — 2026-08-30

A aplicação passa a saber distinguir uma conta real de uma conta de ensaio. Até
aqui não sabia, e isso parou um deploy: o pré-voo comercial recusou-se a avançar
sobre subscrições em vigor sem condição registada — e fez bem, porque uma conta
de um cliente sem termos gravados é uma dívida por esclarecer. Só que nenhuma
daquelas contas era de um cliente: eram todas de ensaio, incluindo as de
professores parceiros convidados para experimentar o produto. O portão estava a
fazer, sobre uma população inteira, uma pergunta que não se lhe aplicava, e as
únicas saídas eram fabricar contratos que ninguém acordou ou desligar o portão.
Nenhuma das duas é verdade, e a lacuna era estrutural. Passa a haver uma marca
explícita — e continua a não haver contrato nenhum, porque não existe.

### Added

- **Classificação explícita de conta de teste.** `organizations.is_test_account`
  diz que uma organização existe para experimentar o produto. É um booleano e
  não um enum, de propósito: não há hoje um terceiro estado que se consiga
  nomear sem o inventar.
- **Gestão auditada no backoffice.** A ficha da conta mostra se é conta de teste
  ou conta real, e um operador marca ou desmarca com confirmação. Cada alteração
  regista quem decidiu, o valor anterior e o novo. O pedido leva o estado que
  quer, nunca «inverte o que lá estiver».
- **Comando seguro para classificação histórica**
  (`lapis:mark-test-accounts`). Aplica em bloco uma decisão já tomada, pela
  mesma acção que o botão usa e com uma auditoria por organização. Exige um
  administrador identificado, confirmação explícita e um instante-limite
  obrigatório, lido à letra e recusado se for futuro — uma classificação
  histórica que alcança contas ainda por existir seria uma armadilha à espera do
  próximo cliente.

### Changed

- **O pré-voo comercial exclui apenas as contas explicitamente marcadas como
  teste**, e diz quantas excluiu. Uma conta real sem condição registada continua
  a parar o deploy, exactamente como antes.

### Security/Safety

- **Um registo público novo continua a ser conta real**, por omissão da base de
  dados. A coluna está fora do `Fillable` do modelo, por isso nenhum formulário
  — nem um `$request->all()` distraído — a consegue escrever.
- **Nenhuma inferência.** Nada deduz «conta de teste» a partir do email, do
  domínio, do nome, do plano, do id ou da ausência de pagamentos. A marca vem de
  um operador, e só de um operador.
- **Classificar não altera planos, direitos nem snapshots comerciais.** Plano,
  versão do plano, estado, módulos, limites, condição comercial, preço
  contratado e prazo ficam como estavam. Nenhuma conta existente recebeu
  condição comercial: `commercial_condition` e `contracted_price_cents`
  continuam por preencher, porque continua a não existir contrato.

## [0.90.0] — 2026-08-29

«Alunos» deixa de ser uma promessa no menu e passa a ser uma página. A entrada
existia desde a primeira fase da navegação, com o seu módulo e o seu lugar, e
respondia com o *placeholder* de «em breve»: um professor com trezentos alunos
distribuídos por oito turmas não tinha nenhum sítio onde perguntar «onde está o
João?» sem abrir turma a turma até dar com ele. A partir desta versão há um
diretório — encontra a pessoa, mostra em que turmas ela está, e entrega a
pergunta a quem já a sabia responder. Não é uma segunda ficha do aluno: o
**Acompanhamento** continua a ser a leitura pedagógica de uma pessoa e as
**Turmas** continuam a ser a casa administrativa da inscrição. Esta página é o
índice sobre as duas, e por isso não escreve nada.

### Added

- **Diretório central de alunos** (`/students`). A lista dos alunos das turmas
  do professor, cem por página, cada linha com o pseudónimo, o nome, a
  fotografia e todas as inscrições dessa pessoa nas turmas que este professor
  leciona.
- **Pesquisa e filtros.** Pesquisa pelo **nome completo** — através do índice
  cego, que responde a igualdade e mais nada — ou pelo **início do pseudónimo**.
  Filtros por turma, por ano letivo e por estado da inscrição, todos resolvidos
  contra as turmas que o professor vê e não contra a base de dados.
- **Acesso rápido ao acompanhamento individual.** Cada inscrição da linha abre
  o acompanhamento no par (turma, inscrição) correto — a rota que já existia,
  sem rota nova e sem leitura nova.
- **Navegação para o acompanhamento a partir da turma.** A pauta de
  «Turmas → turma» ganha, por aluno, o atalho para o mesmo acompanhamento, que
  até aqui obrigava a sair e a escolher outra vez a mesma turma.

### Changed

- **«Alunos» deixa de ser um *placeholder*.** A entrada do menu passa a apontar
  para um destino real, no mesmo endereço `/students` a que o *placeholder*
  respondia — um marcador criado antes da página existir continua a chegar lá.
  A chave, o módulo, o rótulo e a posição no menu não mudaram.
- **A listagem respeita apenas as turmas do professor.** O diretório parte de
  `SchoolClass::taughtBy()`, o mesmo âmbito que «Turmas» e o «Horário do
  Professor» já usam: os alunos de um colega da mesma escola não aparecem aqui.
- **Suporte explícito a alunos em várias turmas.** Quem está inscrito em mais do
  que uma turma deste professor é **uma linha**, com as suas inscrições listadas
  dentro dela — e não uma linha por inscrição.

### Security/Privacy

- **Isolamento entre organizações testado.** A listagem parte de `Student`, que
  carrega o âmbito global da organização; `StudentIdentity` não o carrega, e por
  isso todas as consultas deste ecrã que lhe tocam declaram `organization_id`
  explicitamente.
- **Isolamento entre professores da mesma organização testado.** Um `ulid` de
  uma turma de um colega não resolve como filtro: a página responde como se
  nenhuma turma tivesse sido pedida, sem confirmar que essa turma existe.
- ***Payload* minimizado.** Sai o pseudónimo, o nome, uma URL para a rota
  guardada da fotografia — nunca o caminho nem os *bytes* — e o mínimo de cada
  inscrição. Nada mais da identidade atravessa a fronteira.
- **Pesquisa sobre `StudentIdentity` protegida explicitamente por
  `organization_id`**, além do âmbito que já a cobria.

## [0.89.0] — 2026-08-29

As condições comerciais deixam de viver na landing e passam a viver na base de
dados. Até aqui, «Gratuito no ano letivo 2026/27» e «Faça parte dos primeiros
250» eram frases numa página: uma adesão não guardava rasto nenhum daquilo que
lhe tinha sido prometido, e o contador dos lugares lia as subscrições com
condição `founder` — uma população que **nenhum fluxo escrevia**. O checkout
marcava a condição no pagamento e a subscrição só passava a fundadora se, dias
depois, um operador o dissesse à mão; o contador mostrava «restam 250» para
sempre, e o 251.º comprador veria o preço de fundador sem forma de saber que
era o 251.º. A partir desta versão, uma adesão grava a condição que lhe foi
dada — com o preço congelado e a data até quando vale — e um lugar de Membro
Fundador é uma linha com um ordinal que a base de dados recusa duplicar. A
decisão está registada na **ADR-0009**.

### Added

- **Condições comerciais gravadas no momento da adesão.** `CommercialTerms`
  decide os termos de uma subscrição a partir do que foi de facto contratado, e
  `ContractedTerms` transporta-os como um valor único — condição, preço em
  cêntimos, moeda, periodicidade e a data até quando o termo comercial vale.
  Deixa de ser preciso ler a base de dados para saber em que condições uma
  conta entrou.
- **Promoção Base 2026/27.** Uma adesão Base nova grava
  `Promotional / 0 / EUR / 2027-08-31` enquanto a janela estiver aberta. As
  contas que já lá estavam **não** são tocadas — nem por migração, nem por
  comando nenhum — porque aplicar a promoção retroativamente afirmaria uma
  coisa que a base de dados nunca teve prova para dizer.
- **Anualidade explícita.** `BillingPeriod::Annual` passa a ficar registado na
  subscrição, onde «subscrição anual» estava simplesmente em falta. O enum não
  tem caso mensal, de propósito: é o único ciclo pago que este produto tem.
- **Lugares de Membro Fundador auditáveis** (`founder_seats`). Cada lugar é uma
  linha com o ordinal prometido, o preço congelado no momento da adesão, quando
  foi tomado, até quando a reserva se aguenta e quando o dinheiro entrou. O
  lugar é **reservado no checkout** — o único momento transacional e auditável
  que existe — e liberta-se sozinho se a janela de transferência passar sem
  confirmação, sem job e sem *scheduler*. Um lugar não é um direito: um Membro
  Fundador tem exatamente os módulos de um Pro normal.
- **A regra dos primeiros 250, imposta pela base de dados.** `UNIQUE` sobre
  `seat_number`, ordinais densos e o teto verificado antes de inserir tornam o
  251.º lugar um estado que a base de dados recusa, e não apenas algo que o
  código desaconselha. O teto comercial vive em `billing.founder.seats`; a
  `CHECK` na tabela é um travão de sanidade num valor deliberadamente mais alto.
- **Pré-voo comercial** (`lapis:commercial-preflight`). Só leitura, sem
  `--apply`, e sai diferente de zero quando há subscrições em vigor sem
  condição registada — para que um procedimento de deploy pare sem ninguém ter
  de ler a saída com atenção. Não classifica contas nem inventa uma noção de
  conta interna: mostra os factos e deixa a leitura a quem sabe.
- **Backoffice comercial: o que foi contratado, em leitura.** O ecrã de conta
  passa a mostrar as condições contratadas — preço, moeda, periodicidade, fim
  do termo, versão de plano — e o lugar de Fundador, se existir. Tudo sem
  edição: são prova imutável, e um campo editável faria a promessa dos 250
  depender de quem escrevesse por cima. As três ocorrências do lugar
  (atribuído, confirmado, libertado) entram no trilho de auditoria.
- **ADR-0009** — «Os primeiros 250» é um lugar, e um lugar é uma linha.

### Changed

- **Checkout Fundador coerente com a reserva e a sua expiração.** O lugar é
  tomado quando o comprador recebe a referência e confirmado quando o pagamento
  entra; uma reserva vencida deixa de contar para os 250 e o lugar volta ao
  bolo. Esgotadas as tentativas de atribuição, o checkout segue ao preço de
  tabela e regista a ocorrência — em nenhum caminho sai um erro de base de
  dados para quem está a comprar.
- **Referências de transferência caducadas passam a ser revalidadas.** Quem
  voltasse ao checkout depois de a reserva expirar recebia de volta a mesma
  referência a 29,90 € — um preço de fundador sem lugar por trás, que já
  ninguém podia honrar. `revalidate()` decide antes: reafirma o lugar se ainda
  o houver, toma um novo se houver vaga, e caso contrário emite a referência ao
  preço de tabela.
- **A landing deixa de oferecer Fundador quando o prazo passou ou os lugares
  esgotaram.** A promessa tinha duas condições de validade e a página continuava
  a fazê-la de qualquer maneira. Passa a ser um booleano vindo do servidor —
  ainda disponível, ou já não — e não um contador público, que continua a ser
  uma decisão comercial por tomar.

### Segurança e salvaguardas

- **Concorrência dos lugares, em quatro camadas.** `UNIQUE(seat_number)`,
  `lockForUpdate()` sobre uma linha que existe sempre, um ciclo de tentativas
  que converge para o menor número livre, e o teto verificado em PHP antes de
  inserir. A camada do bloqueio é provada em MySQL com duas ligações reais
  (`FounderSeatsMysqlGuaranteesTest`, *opt-in*), porque o SQLite dos testes
  ignora `lockForUpdate()`.
- **Rollback recusado depois de haver lugares.** O `down()` da migração reverte
  enquanto a tabela estiver vazia e **recusa-se** assim que houver um lugar
  atribuído: cada linha é a prova de uma condição acordada com uma pessoa, e
  nada a reconstrói depois de a tabela desaparecer.
- **O pré-voo nunca escreve.** Não tem `--apply`, e a ausência é deliberada: um
  comando de inspeção que também soubesse corrigir seria um comando que alguém
  corrige por engano. Aplicar uma condição a uma conta antiga faz-se uma a uma
  no backoffice, por `SetCommercialCondition`, que regista quem o disse e
  quando. O pré-voo corre também **antes** da migração, contra o esquema da
  release anterior, e nessa passagem adia a secção dos lugares em vez de
  rebentar — para que o código de saída continue a significar «há contas por
  classificar» e não «correste-me cedo demais».
- **Nenhum corte automático depois de 31/08/2027.** `commercial_term_ends_at`
  diz até quando o termo comercial vale e **não é lido por nada no caminho do
  acesso**. O que acontece a uma conta quando o termo chega ao fim é uma
  política que ainda não existe; nada nesta versão a inventa.

## [0.88.0] — 2026-08-29

Um plano deixa de **ser** a sua composição. Até aqui, mover uma capacidade
entre o Base e o Pro reescrevia — retroativamente, em silêncio, e para toda a
gente — aquilo a que cada subscritor desse plano alguma vez tivera direito: o
`EntitlementsSeeder` fazia `sync()` a `module_plan` em cada execução, e
`plans.limits` era uma coluna viva. A própria 0.87.0 é a prova: a fronteira
Base/Pro mudou e não ficou registo nenhum de que os direitos de ontem eram
outros. A partir desta versão, `Plan` é só a identidade comercial («Pro») e a
oferta que ele vendeu num dado momento é uma `PlanVersion` imutável. Publicar
o Pro v2 passa a ser a forma de mudar a oferta, e deixa intacta cada subscrição
que ficou no Pro v1 — o *grandfathering* deixa de ser uma funcionalidade de que
alguém tem de se lembrar e passa a ser a ausência de um ato. A decisão está
registada na **ADR-0008**.

### Added

- **Versões imutáveis de plano** (`plan_versions`, `module_plan_version`). Cada
  versão fixa uma composição de módulos e os seus `limits` comerciais, com um
  número por plano (Base v1, Pro v1, …), uma data de publicação e um *hash* da
  composição. Uma versão publicada é imutável por construção, e não por
  promessa num comentário: só `retired_at` e `notes` se movem — retirar uma
  versão do catálogo não muda nada para quem já está nela. Uma versão com
  subscritores não pode ser apagada.

- **A subscrição refere a versão contratada**
  (`organization_subscriptions.plan_version_id`, obrigatória). `plan_id`
  mantém-se por compatibilidade — o backoffice filtra por ele, o CSV comercial
  exporta-o, os emails leem `plan->name` — e as duas colunas nunca podem
  discordar: a garantia é uma **chave estrangeira composta**
  `(plan_version_id, plan_id)` sobre `plan_versions (id, plan_id)`, e não uma
  convenção que o PHP tenha de manter.

- **Instantâneo comercial mínimo na adesão**: `contracted_price_cents`,
  `contracted_currency`, `billing_period` e `commercial_term_ends_at`, escritos
  uma vez e imutáveis a partir daí, como já acontecia com
  `SubscriptionPayment`. O sistema preservava bem o dinheiro e mal a
  **promessa**: não havia onde registar o que foi acordado enquanto nenhum
  pagamento existe — o Base gratuito, o período experimental, a concessão do
  operador, a quinzena entre pedir uma transferência e confirmá-la. **NULL não
  é zero:** NULL é «nunca foi acordado nem registado», `0` é «alguém acordou
  explicitamente que isto não custa nada». Pela mesma razão, um
  `billing_period` NULL não é `none`. E `commercial_term_ends_at` **não é**
  `ends_at`: um é até quando a *condição* se mantém, o outro é até quando o
  *acesso* corre.

- **`CommercialCondition::Promotional`**, uma condição por tempo limitado que
  não é `Standard` — usar `standard` faria `normallyPaid()` responder `true`
  para contas que nada devem. O enum passa a poder nomeá-la; ninguém a aplica
  automaticamente a conta nenhuma.

- **A versão contratada é visível no backoffice.** A ficha da conta mostra
  «Plano atual» e, ao lado, `v1`/`v2` — **apenas leitura**. Duas contas em «Pro»
  podem estar em ofertas diferentes, e responder «v1 ou v2?» deixa de exigir um
  cliente SQL. Não existe editor de versões nesta versão: mover uma subscrição
  entre versões é um ato deliberado e não se faz a partir de uma etiqueta.

### Changed

- **A composição e os limits passam a ser versionados.** `Entitlements` e
  `Limits` deixam de ler o plano e passam a ler a versão contratada pela
  subscrição em vigor. Os *caps* seguiram os módulos de propósito: versionar a
  composição e deixar os limites vivos recriaria o mesmo defeito uma dimensão
  ao lado — quem comprou o Pro com 8 turmas mantém 8 quando o Pro passar a
  vender 20. A quota opcional de IA lida por `AiQuota` é um limite como os
  outros e ficou congelada na versão pela mesma razão; a quota **por omissão da
  plataforma** não é versionada, porque não promete nada a ninguém.

- **`Plan::modules()` e `plans.limits` foram removidos, não descontinuados.**
  Deixados a coexistir com a versão, cada leitor por migrar continuaria a
  responder — de forma plausível, errada e silenciosa. Removidos, cada um parte
  e é migrado. `module_plan` deixou de ser fonte funcional e a tabela é largada
  na própria migration. A landing e o backoffice de IA passam a ler a **versão
  atualmente publicada** de cada plano: quem visita a página de preços não é
  ainda cliente grandfathered de ninguém.

- **O seeder publica em vez de alterar.** O `EntitlementsSeeder` continua a ser
  o único sítio onde a composição de cada plano está escrita — isso não mudou.
  Mudou o que faz com ela: compara-a com a última versão publicada e só publica
  a versão N+1 se forem genuinamente diferentes. Idempotente no sentido forte —
  correr duas vezes cria **uma** versão; correr depois de uma alteração real
  cria **exatamente mais uma**; a versão anterior fica intacta. A primeira
  execução depois da migration reconhece a v1 equivalente e não publica nada.

- **A descida de plano lê a versão original.** `retainReadOnlyAfterDowngrade()`
  percorre as subscrições históricas e lê, de cada uma, a **versão que ela
  contratou** — nunca a composição que esse plano tem hoje. Era este o método
  em torno do qual a ADR-0008 foi escrita: perguntar «o que é que este
  professor podia fazer quando escreveu isto?» ao plano atual fazia com que
  correr o seeder reescrevesse o passado.

- **Todos os caminhos que criam uma subscrição ligam-na a uma versão real.**
  `SubscribeOrganization`, `CreatePersonalOrganization`,
  `CreateInstitutionalOrganization` e `ChangeOrganizationPlan` resolvem pela
  versão que o plano vende hoje; `ChangeOrganizationPlan` aceita também uma
  versão explícita, e mover um subscritor para a frente passa a ser um ato
  explícito. Um plano sem nada publicado **recusa a venda** em vez de produzir
  uma subscrição com direito a nada.

### Segurança da migração

- **O backfill preserva integralmente as capacidades e os limits.** A v1 de
  cada plano é uma cópia byte a byte do que os *resolvers* já liam — Base v1,
  Pro v1 e Institucional v1 são a composição da 0.87 — e **todas** as
  subscrições, incluindo as fechadas, recebem a v1 do seu próprio plano.
  «Ninguém ganha nem perde uma capacidade» é uma consequência da construção e
  não uma esperança: o mapa de acesso efetivo antes e depois é comparado num
  teste. Zero órfãos — uma subscrição sem versão para apontar aborta a migration
  em vez de se inventar uma.

- **Nenhuma história comercial é inventada.** As quatro colunas do instantâneo
  ficam **NULL** em todas as linhas existentes. Fazer o contrário seria afirmar
  que contas anteriores à coluna aderiram sob a condição promocional de 2026/27,
  que a base de dados nunca teve prova para dizer. Não se inventa condição
  2026/27, não se inventa Fundador, não se inventa periodicidade.

- **O rollback recusa perder história.** Reverter é suportado exatamente no
  estado que as migrations deixam ao correr pela primeira vez: uma versão por
  plano e nada registado no instantâneo. A partir do momento em que existe uma
  v2, ou em que subscritores do mesmo plano estão espalhados por versões
  diferentes, ou em que há prova comercial escrita, o `down()` **recusa em voz
  alta** — o esquema anterior só sabe guardar uma composição por plano, e um
  «caminho de downgrade» que escolhesse uma versão por subscrição estaria a
  inventar o facto que acabara de apagar. Cada recusa acontece **antes** de
  qualquer destruição, e a base de dados fica a funcionar.

- **Três migrations novas**, deliberadamente separadas porque fazem promessas
  diferentes e cada uma tem de ser verificável sozinha: `plan_versions` +
  `module_plan_version` com a v1 de cada plano; `plan_version_id` nas
  subscrições, com backfill e chave composta; e o instantâneo comercial, sem
  backfill nenhum.

### Notas

- **Sem alterações funcionais visíveis para o utilizador final.** Nenhuma
  capacidade mudou de plano nesta versão. O que mudou é de onde a resposta é
  lida.

- **O deploy tem de correr as migrations e, depois,
  `php artisan db:seed --class=EntitlementsSeeder`.** O seeder é idempotente e,
  imediatamente a seguir ao backfill, reconhece a v1 como equivalente e não
  publica uma v2.

- **Nada aqui implementa renovação, faturação recorrente, vouchers, a condição
  de Fundador, a aplicação da promoção de 2026/27 ou o cancelamento
  self-service.** O esquema passa a saber **registar** as condições que a ADR
  nomeia; aplicá-las é uma decisão do operador, tomada mais tarde, como um
  UPDATE auditado.

- **Testes.** Nove ficheiros novos sustentam cada promessa acima:
  `PlanVersionBackfillTest` (o mapa de acesso é idêntico antes e depois),
  `PlanVersionHistoryTest` (uma composição futura não reescreve o passado),
  `PlanVersionPublishingTest` (idempotência e imutabilidade),
  `PlanVersionRollbackSafetyTest` (as recusas, e que nada é destruído a caminho
  delas), `CommercialSnapshotTest` (NULL ≠ 0, e um termo comercial expirado não
  termina o acesso), `PlanVersionLimitsTest`, `PlanVersionArchitectureTest`
  (nada na aplicação lê a composição de um plano diretamente),
  `ContractedVersionVisibilityTest` e `ChangeOrganizationPlanVersionTest`.

## [0.87.0] — 2026-08-29

A fronteira entre Base e Pro passa a ser a que a Matriz Mestre descreve, nos
dois sentidos. Quatro capacidades vendidas como Pro estavam visíveis no Base e
duas prometidas ao Base estavam fechadas atrás do Pro; nenhuma das duas coisas
impedia o produto de funcionar, e as duas impediam-no de ser vendido de forma
coerente. O critério é o da §24 — **«Base regista e mostra. Pro cruza,
interpreta e ajuda a agir.»** Nenhum facto saiu do Base: saiu o cruzamento.

### Added

- **Calendário do ano letivo no plano Base** (`calendar`). As vistas de mês e
  de ano e os acontecimentos manuais passam a estar incluídos nos três planos.
  Uma organização Base definia períodos, interrupções e feriados na Estrutura
  do Ano Letivo e não conseguia abrir nenhuma das vistas do que acabara de
  definir.

- **`calendar_import`, uma chave nova.** A importação avançada de calendário —
  o ficheiro que o agrupamento publica, lido para dentro da estrutura do ano —
  é a única linha da tabela do calendário que continua marcada só para Pro e
  Institucional, e passa a ter chave própria em vez de andar à boleia de
  `calendar`. As rotas de importação exigem as duas.

- **`data_backup_restore`, uma chave nova.** Repor uma cópia de segurança
  completa por cima dos dados de uma organização, e o histórico dos backups
  tomados, passam a ser Pro e Institucional. **A exportação não é afetada:**
  exportar os próprios dados e a exportação RGPD continuam sem gate nenhum, em
  qualquer plano, porque portabilidade é uma propriedade da plataforma e não
  uma funcionalidade paga. Até agora nenhuma das duas metades era verificada, e
  uma organização Base podia restaurar um backup completo.

- **Descida de plano não destrutiva.** Uma capacidade que a organização teve,
  já não tem, e que `RetainedOnDowngrade` nomeia, passa a resolver para
  **read-only** em vez de ficar bloqueada. Quem descia de Pro para Base deixava
  de conseguir abrir um único sumário que tinha escrito: nada era apagado, mas
  nada era alcançável, o que do lado do professor é a mesma coisa. A regra lê o
  histórico de subscrições que já existia e aplica-se apenas quando há uma
  subscrição em vigor — uma subscrição caducada sem sucessor não é uma descida
  de plano, é uma conta sem plano.

### Changed

- **A leitura automática do movimento da turma passa a Pro.** Em Análise da
  Turma, `evolution` e `continuous_evolution` — cada aluno arrumado em
  progrediu / manteve-se / regrediu, e a matriz de quem atravessou a linha da
  escala — deixam de entrar no payload sem `advanced_analytics`. **A evolução
  factual simples fica no Base:** a série por período e a variação de cada
  aluno continuam onde estavam, tal como todas as médias, taxas, distribuições
  e estatísticas por domínio.

- **A comparação contextual com a turma passa a Pro,** nas três superfícies que
  a mostravam: o painel de Evolução do Aluno, o documento de impressão e a
  síntese global do relatório individual. «72,1%, acima da média da turma
  (66,4%)» é a frase que a Matriz usa para descrever o que o Pro acrescenta.

- **«Atenção» e «Pontos fortes identificados automaticamente» passam a Pro,** e
  passam a não ser sequer calculados sem a capacidade, em vez de calculados e
  escondidos. Juntar resultados, registos, TPC, autoavaliações e intervenções
  numa frase sobre o que merece atenção é cruzar fontes, seja quem for a fazer
  a aritmética. **Os números continuam todos no Base:** a tabela de domínios
  continua a dizer que Gramática está a 37,5%; o que o Pro acrescenta é dizer
  que a prioridade de consolidação é Gramática.

- **«Desde o relatório anterior» passa a exigir a mesma capacidade** que o
  resto da camada interpretativa dos relatórios.

- **A oferta pública deixou de contradizer a composição real.** «Agenda
  integrada» era uma linha só, mapeada a `calendar`, e com o calendário no Base
  passaria a marcar ✓ Base a dizer ao visitante que o ficheiro da escola vem
  com o plano gratuito. A comparação de planos passa a ter duas linhas
  adjacentes — o calendário e a sua importação —, uma linha para o restauro e o
  histórico de backups, e uma nota a dizer que a exportação existe em todos os
  planos. A banda «O dia a dia» e a resposta das perguntas frequentes deixam de
  atribuir a agenda ao plano Pro, e o cartão do Base passa a nomear o
  calendário que agora inclui.

- **A composição continua a ser dados, nunca código.** As duas chaves novas
  entram pelo `EntitlementsSeeder` como todas as outras, e `advanced_analytics`
  foi reutilizada em vez de partida em duas — dividir a chave quebraria em
  silêncio as concessões individuais já atribuídas. O plano Institucional muda
  só por herança.

### Notas

- **Sem migrations.** O deploy desta versão tem de correr
  `php artisan db:seed --class=EntitlementsSeeder`, que é idempotente: sem isso
  as duas chaves novas não existem na base de dados e as capacidades que elas
  guardam ficam inalcançáveis para todos os planos.

- **Nada do módulo de Inteligência Artificial foi tocado.** Nenhum ficheiro de
  `app/Services/Ai`, nenhum artigo de ajuda de IA e nenhuma linha de
  `config/lapis.php` mudaram nesta versão. A IA continua desligada por omissão
  e sem fornecedor configurado.

- **Testes.** `BaseProBoundaryTest` afirma uma célula da Matriz por asserção em
  quatro superfícies — menu, endpoint, URL escrito à mão e props;
  `PlanDowngradeTest` separa as promessas da descida de plano do fim do período
  experimental de Pro. Do lado do texto comercial, as listas de módulos de cada
  plano deixaram de estar escritas à mão nos testes e passam a ser lidas do
  próprio `EntitlementsSeeder`: uma cópia da composição escrita ao lado dela é a
  única fixture que nunca falha.

## [0.86.0] — 2026-08-29

O módulo de Inteligência Artificial passa de duas funcionalidades a seis, todas
pela mesma porta. A decisão comercial que estava em aberto desde a 0.83.0 — a
que planos pertencem as capabilities de IA — foi tomada, e vem da Matriz Mestre.

### Added

- **IA na avaliação** (`ai_assessment`). Em Resultados, por baixo da grelha,
  «Analisar a avaliação com IA» dá uma leitura em palavras dos resultados do
  período: padrões, onde a evidência é sólida, pontos de atenção, sugestões e —
  a parte que interessa — as limitações da própria leitura. Um domínio com
  cobertura parcial nunca é descrito como forte nem como frágil. Abaixo de três
  alunos com resultado, o pedido não chega a ser feito.

- **IA no acompanhamento** (`ai_followup`). Em Evolução do Aluno, «Síntese de
  acompanhamento com IA» junta num texto o que a página já mostra: sinais
  positivos antes dos pontos de atenção, o que mudou, e um próximo passo a
  considerar. Os factos continuam a ser calculados pelo Lapispro e a aparecer
  acima; a síntese é a camada de interpretação, rotulada como tal. **Não recebe
  o texto livre que o professor escreveu** — descrições de registos, objetivos
  de intervenções — só categorias, estados e contagens.

- **Governação institucional de IA** (`ai_governance`). Administração
  institucional › Inteligência Artificial mostra a um administrador que
  funcionalidades o plano inclui, que limites estão em vigor, quanto foi
  consumido no mês e por que motivo foram recusados pedidos. Contagens e
  configuração — nunca o que alguém perguntou, nunca o que a IA respondeu, e
  **nunca o consumo de cada professor em particular**.

- **Plafond organizacional** (`ai_institutional_pool`). Um teto mensal para o
  conjunto de todas as funcionalidades de IA, com teto individual opcional
  dentro dele, aplicável só a organizações institucionais. Está **inerte por
  omissão**: um plafond é uma figura contratual, e um valor por omissão
  inventaria um para todos os clientes de uma vez.

- **Painel de utilização no backoffice.** Administração › Inteligência
  Artificial passa a ler o contador que escreve desde o primeiro dia: pedidos,
  concluídos, erros, recusas e tokens, por funcionalidade e por tipo de pedido.
  Fecha a dívida registada no ADR-0006 §«Dívidas registadas» 3.

- **Sete artigos novos no Centro de Ajuda**, um por funcionalidade mais dois
  transversais: privacidade nas funcionalidades de IA, e «Limites de IA e porque
  a IA pode não aparecer» — o artigo que responde à pergunta mais provável do
  módulo, com as quatro razões possíveis e a pessoa a quem cada uma se dirige.

- **Aviso e deteção preventiva em todo o texto livre que chega à IA.** Onde o
  professor escreve à mão — a pergunta ao assistente, o objetivo de uma sugestão
  de estratégia, o corpo de uma secção antes de ser aperfeiçoada — passa a haver
  um lembrete permanente e, antes do envio, uma verificação local à procura dos
  mesmos padrões que o sanitizador do servidor remove: e-mails, contactos,
  códigos postais, «n.º 12», ULID/UUID e séries longas de algarismos. Se
  encontrar algum, o pedido para, o texto **não é alterado**, e continuar passa
  a ser uma escolha explícita. A verificação corre no navegador e **não chama
  nada**. Nas páginas que já têm o nome do aluno à frente por outra razão, esse
  nome concreto também é procurado; o Centro de Ajuda não procura nome nenhum e
  não carrega roster para o fazer. Palavras capitalizadas **não** são tratadas
  como nomes, e o produto não promete detetar nomes próprios arbitrários — a
  Política e o Centro de Ajuda dizem-no por palavras.

### Changed

- **A composição comercial das capabilities de IA foi decidida**, a partir da
  Matriz Mestre: o assistente do Centro de Ajuda passa a estar incluído também
  em **Base**; a análise pedagógica, a avaliação, o acompanhamento, as
  estratégias e os relatórios em **Pro** e **Institucional**; a governação e o
  plafond só em **Institucional**. `AiEntitlementMatrixTest` afirma as vinte e
  quatro células à mão.

- **«Aperfeiçoar redação» e «Sugestões de estratégia» passaram a atravessar o
  `AiGateway`.** Eram as duas últimas funcionalidades a resolver um fornecedor
  por si próprias, o que significava que a funcionalidade de IA mais usada do
  produto era invisível para o contador que existe para responder «quanto custa
  a IA». Passam a ter entitlement, rate limit, quotas, plafond, verificação de
  privacidade e linha em `ai_usage_events` como todas as outras.

- **`ai_reports` e `ai_strategies` substituem `ai_assistance`** como chaves
  dessas duas funcionalidades — uma escola passa a poder ter uma sem a outra.
  **Nada muda para quem já tinha acesso:** `ai_assistance` continua no catálogo,
  continua em Pro e Institucional, e continua a conceder as duas através de
  `AiCapability::legacyModuleKeys()`.

- **Os throttles de rota saíram de «Aperfeiçoar redação» e de «Sugestão de
  estratégia».** O gateway já aplicava o mesmo teto por capability; manter os
  dois contava cada pedido duas vezes e reduzia o limite a metade. O quarto
  clique deixa de ser um 429 e passa a ser uma frase ao lado do texto, com a
  secção intacta.

- **A Administração de IA fala português de produto.** «Estas definições
  sobrepõem-se ao `.env`» passa a «têm prioridade sobre a configuração técnica
  do servidor», e o aviso «nenhum plano inclui esta funcionalidade» dá lugar aos
  planos reais de cada capability, lidos da base de dados.

### Fixed

- **O motor compatível com `/chat/completions` passa a enviar um teto de
  resposta.** O driver do Gemini nasceu com um; este, mais antigo, nunca teve
  nenhum — e um motor sem teto responde a uma pergunta de duas linhas com duas
  mil, que é a única definição de `lapis.ai` que é diretamente uma fatura. Passa
  a enviar `max_tokens` a partir de `lapis.ai.max_output_tokens`, a mesma
  configuração que a Administração já mostrava como «teto de tokens de resposta»
  e que já valia para o Gemini. **Nenhuma instalação foi afetada:** não há
  fornecedor de IA configurado em produção.

- **`AiTextRequest::maxOutputCharacters` foi removido.** Estava declarado desde
  a criação da camada de texto, nunca foi preenchido por quem chamava e nunca
  foi lido por motor nenhum — e estava em caracteres, unidade que nenhuma API
  aceita. Um teto que quem chama pode exprimir é um teto que quem chama pode
  levantar: o único que existe é `lapis.ai.max_output_tokens`, decidido pela
  instalação. `AiArchitectureTest` passa a afirmar que todos os motores reais o
  recebem, que o recebem sem valor por omissão, e que o põem no fio.

### Notas — o que continua por ligar

- **O Gemini real continua por ativar.** Sem credencial e sem fornecedor
  configurado; todo o fluxo é exercitado contra o fornecedor Fake. A IA continua
  desligada por omissão e a aplicação inteira funciona nesse estado.

- **Nenhuma quota comercial foi inventada.** Os números em `config/lapis.php`
  são tetos técnicos de custo com uma variável de ambiente à frente; quanto é
  que um plano INCLUI vive em `plans.limits`, onde muda sem deploy, e continua
  por decidir.

- **A descrição pública da IA foi corrigida.** A secção «Inteligência
  artificial» da Política de Privacidade afirmava que não eram enviados
  resultados, classificações nem autoavaliações, e que todos os números saíam
  substituídos por marcadores. Isso descrevia «Aperfeiçoar redação» — onde
  continua verdadeiro — enunciado como regra do módulo inteiro, e era falso para
  as três leituras que existem precisamente para interpretar esses dados. A
  secção passa a separar **duas situações com garantias diferentes**: o contexto
  que o Lapispro monta sozinho, onde nenhum identificador direto entra; e o
  texto que o professor escreve à mão, onde há aviso e deteção preventiva mas
  **não** uma garantia absoluta. Duas passagens do Acordo de Tratamento de Dados
  foram alinhadas pelo mesmo critério. **A redação continua por validar por
  jurista**, como todo o texto legal; o que mudou é que já não descreve um
  comportamento diferente do real.

- **A afirmação sobre retenção passou a ter sujeito.** «Não é guardado o texto
  das perguntas nem o das respostas» lia-se como universal. A Política passa a
  dizer o que o **Lapispro** guarda — nada de conteúdo, sem memória entre
  pedidos, sem gravação automática de decisões — e ressalva explicitamente que o
  que um fornecedor faça do seu lado depende do fornecedor e do contrato, e será
  descrito quando um for escolhido. Não se promete por conta de terceiros que
  ainda não existem.

- **Sem migrations.** O plafond é guardado na coluna JSON que o backoffice já
  escrevia, sob uma chave reservada.

## [0.85.0] — 2026-08-28

Um release de reconciliação: não acrescenta funcionalidade nova, reúne numa só
linhagem o que estava em duas. A frente comercial que corria em produção e a
frente de Ajuda/IA que corria no repositório passam a existir no mesmo sítio, e
esta versão existe precisamente para que nenhum número de versão volte a
designar dois estados diferentes do produto.

### Added

- **A frente comercial volta à linhagem principal.** O checkout por
  transferência bancária, o `BillingProfile`, a configuração de faturação, a
  contagem de Membro Fundador (`FounderAvailability`), os emails de instruções e
  de confirmação bancária, a confirmação no backoffice, o `LoginResponse` e o
  `VerifyEmailResponse` que respeitam o destino guardado em sessão, o branding
  recuperado e os testes comerciais — tudo o que a 0.82.0 comercial pôs em
  produção está aqui, incluindo a migration `billing_profiles`.

- **A migration histórica de contactos da plataforma foi recuperada.**
  `2026_09_07_000100_add_support_and_privacy_emails_to_platform_settings`
  voltou byte a byte. **Não muda a fonte de verdade:** o email de apoio e o de
  privacidade continuam a ser lidos de `config/lapis.php`/`.env`, e as colunas
  ficam como dívida histórica de schema, para que uma instalação nova chegue ao
  mesmo esquema que produção tem.

### Changed

- **A versão canónica salta para 0.85.0 e não reutiliza 0.82.**  Uma das 0.82.0
  esteve em produção e a outra não; reaproveitar o número obrigaria a escolher
  qual das duas «conta», e nenhuma das respostas seria verdadeira. 0.85.0 é o
  primeiro número que designa um só estado do produto.

### Notas — o que continua por ligar

- **Nenhuma capability de IA tem plano atribuído.** `help_assistant` e
  `ai_pedagogical_analysis` continuam no catálogo e em plano nenhum, tal como a
  0.83.0 e a 0.84.0 as deixaram. Esta reconciliação não tomou a decisão
  comercial que faltava, e não é o sítio para a tomar.

- **O Gemini real continua por ativar.** Sem credencial e sem fornecedor
  configurado; o fluxo é exercitado contra o fornecedor Fake. A IA continua
  desligada por omissão e a aplicação inteira funciona nesse estado.

- **Nada de comercial foi sacrificado para resolver conflitos**, e nada de
  Ajuda ou de IA foi sacrificado para acomodar o comercial. Os conflitos reais
  foram quatro e estão descritos no commit de reconciliação.

## [0.84.0] — 2026-08-28

As duas primeiras experiências de IA que um professor vê: um assistente no
Centro de Ajuda e uma leitura em palavras da Estatística de uma turma. Ambas
construídas inteiramente sobre o gateway da 0.83.0 — nenhuma delas fala com um
motor por sua conta. **Continuam invisíveis a um professor hoje** — ver as
notas no fim da entrada.

### Added

- **Assistente Lapispro no Centro de Ajuda.** Uma pergunta escrita por palavras
  do próprio professor — «como começo a usar o Lapispro?», «como crio uma
  turma?», «onde configuro a avaliação?» — recebe uma resposta em prosa e a
  lista dos artigos em que assenta. Vive dentro do Centro de Ajuda, na página de
  índice e na de pesquisa, e em mais lado nenhum: não há bolha flutuante por
  cima da aplicação, porque isso seria um produto diferente com uma promessa
  diferente.

- **O grounding é a documentação, e só a documentação.** A recuperação é a
  pesquisa em linguagem natural que a 0.82.0 já tinha, chamada tal como está: o
  assistente é mais um consumidor de `HelpCenter::search()` e não uma segunda
  leitura do conjunto de artigos, pelo que uma pergunta que se encontra na caixa
  de pesquisa encontra-se aqui, e uma palavra acrescentada a
  `config/help-search.php` melhora as duas ao mesmo tempo. Sem embeddings, sem
  base de dados vetorial, sem pesquisa na web e sem índice externo. Os três
  artigos mais relevantes viajam identificados pelo seu id, e a resposta é
  obrigada a citar apenas ids que recebeu — um artigo inventado é descartado no
  parser e nunca chega ao ecrã como ligação para um 404.

- **«Não há informação suficiente» é uma resposta, não um erro.** Quando a
  pesquisa não encontra nada, não há em que fundamentar uma resposta e nenhum
  pedido é feito: o professor é informado de que a documentação não cobre a
  pergunta, sem se gastar capability nenhuma. Quando os artigos chegam mas não
  bastam, o motor responde com uma sentinela e o ecrã diz o mesmo, com calma e
  sem estado de erro.

- **Análise pedagógica com IA na Estatística da turma.** Uma ação explícita
  («Analisar com IA») no fim da página de Estatística devolve quatro blocos:
  **síntese**, **padrões observados**, **pontos de atenção** e **sugestões
  pedagógicas**. Escolheu-se esta página porque é a única onde a IA não tem nada
  para calcular: `BuildClassStatistics` já decidiu médias, distribuição pela
  escala, evolução entre períodos, taxa de sucesso e estatística por domínio, e
  a IA limita-se a descrever por palavras números que já existem. Abrir a página
  nunca chama um motor; a análise só acontece a pedido explícito.

- **A aplicação é a fonte da verdade; a IA interpreta.** Notas, resultados,
  médias, pesos, classificações e regras de avaliação continuam a ser decididos
  pelo motor de cálculo. A instrução proíbe explicitamente calcular, corrigir,
  arredondar ou reformular um número recebido, e o contexto só transporta
  figuras já fechadas. As médias por aluno passam a viajar com a precisão com
  que a aplicação as mostra — e não com a precisão interna do read model — para
  que um valor citado seja um valor que o professor reconhece no ecrã.

- **A IA sugere, o professor decide.** Nada nesta fatia escreve seja o que for.
  Não existe rota que aceite uma análise de volta, e o objeto que a transporta
  não tem id, chave nem verbo à volta do qual uma pudesse ser construída: são
  quatro blocos de texto para uma pessoa ler. Nenhuma nota, resultado,
  classificação, peso ou critério é alterado por uma resposta de IA, e o painel
  não oferece «aplicar», «guardar» nem «aceitar» — apenas «analisar de novo» e
  «tentar novamente». A linguagem da resposta é de possibilidade e não de
  certeza («os resultados podem justificar verificar…», e não «o aluno precisa
  de…»), e cada resposta traz o aviso de que a IA apoia a análise, pode cometer
  erros, e que as decisões pedagógicas continuam a ser do professor.

- **Dois artigos novos no Centro de Ajuda**, escritos na estrutura existente:
  «O Assistente Lapispro» e «Analisar os resultados de uma turma com IA».
  Descrevem o que cada um faz, o que não faz, que dados saem da aplicação, e por
  que razão o termo usado é **pseudonimização** e nunca «anonimização».

### Changed

- **Tudo passa pelo `AiGateway`.** As duas experiências chamam `ask()` e mais
  nada: não resolvem fornecedor, não leem credencial, não verificam plano, não
  contam tokens e não aplicam rate limiting. Entitlement, quota, verificação de
  privacidade do payload e registo em `ai_usage_events` acontecem por trás da
  porta única, com um caso de uso próprio para cada experiência (`help_answer` e
  `pedagogical_analysis`), para que o custo de um assistente de ajuda e o de uma
  leitura pedagógica sejam separáveis. A abstração provisória de capabilities
  que a fatia tinha antes da integração foi removida — não ficam duas fontes de
  verdade.

- **As rotas não acrescentam `throttle:` nenhum.** O rate limit por capability
  vive dentro do gateway; um segundo teto na mesma capability contaria cada
  pedido duas vezes e reduziria o limite a metade. Dois testes verificam que as
  rotas não voltam a ganhar throttle próprio.

- **O contexto pedagógico usa o `AiContext` do Core**, com a ordem obrigatória
  **allowlist → pseudonimização → serialização → sanitização**. A allowlist é a
  única parte que pertence a esta fatia: decidir *que* estatísticas um modelo
  pode ver é um juízo pedagógico. A substituição de nomes é a do Core e não uma
  segunda implementação — a linha de cada aluno leva o nome real ao `add()`, que
  o substitui enquanto ainda é um escalar isolado com significado conhecido, e
  um teste lê os campos antes da serialização para provar que a ordem foi essa.
  Um efeito prático: um domínio a que alguém tenha chamado «Apoio ao João» sai
  igualmente pseudonimizado, o que uma allowlist sozinha não teria apanhado.

- **Os pseudónimos são atribuídos por resultado e não pela ordem da pauta.**
  `Pseudonyms::of()` é posicional, e o read model entrega os alunos por ordem
  alfabética ou de número — ambas dizem alguma coisa sobre quem é «Aluno A».
  Ordenar pelo valor em discussão antes de construir o mapa corta essa
  correspondência, e produz de caminho a ordem que quem procura um padrão
  queria.

- **A pergunta do professor é conteúdo, nunca instrução.** Viaja como
  `SanitisedPayload` no campo `content` do `AiAsk`, que por tipo não pode ser
  concatenado com a instrução. Uma pergunta que diga «ignora as instruções
  anteriores» é uma frase que alguém escreveu numa caixa de pesquisa, e chega
  num sítio onde não pode ser lida como ordem.

- **Estados de indisponibilidade adaptados aos do gateway.** O ecrã distingue «o
  plano não inclui» de «não está ativado nesta instalação» e de «não está
  configurado nesta instalação». Os seis motivos técnicos que o gateway sabe
  distinguir (`off`, `credential_missing`, `model_missing`, `endpoint_missing`,
  `unknown_driver`, `fake_in_production`) colapsam nestas três frases: nomear a
  definição em falta a um professor seria dar-lhe um pormenor sobre o qual não
  pode agir. Quota, rate limit, timeout, indisponibilidade temporária e resposta
  ilegível têm todos mensagem própria, sempre com o botão de tentar de novo — e
  nunca com um código de estado, um endpoint, o nome do fornecedor ou uma página
  de erro do framework.

### Security

- **Nenhum dado pedagógico chega ao assistente de ajuda.** O serviço não aceita
  turma, aluno nem resultado: não há parâmetro por onde isso possa entrar, e um
  pedido deliberadamente carregado com campos extra é ignorado até ao último. As
  únicas entradas são a pergunta e o conjunto de artigos.

- **Nomes, números e identificadores não saem na análise pedagógica.** As linhas
  por aluno são construídas a partir de uma lista de campos permitidos — e não
  por remoção dos perigosos —, pelo que um campo acrescentado ao read model no
  futuro chega aqui como nada, em vez de chegar como uma fuga que ninguém notou.
  Nome, número de aluno e id de inscrição nunca são transportados; o ulid da
  turma também não. O que chega são pseudónimos, resultados, níveis, evolução,
  distribuição e domínios — a substância sem a qual não haveria leitura nenhuma.

- **Nem o prompt nem a resposta são guardados.** `ai_usage_events` não tem
  coluna onde caibam, e a trilha de negócio desta fatia regista fornecedor,
  modelo, ids de artigos, contagens e versão do prompt — nunca o texto da
  pergunta, nunca o texto da análise. Uma resposta vive na sessão durante um
  pedido e desaparece na visita seguinte; não há memória entre perguntas.

- **É pseudonimização, e está escrito assim em todo o lado.** «Aluno A» é
  reversível por quem tem a pauta à frente, e os artigos do Centro de Ajuda
  dizem-no por palavras em vez de prometerem anonimato. Os pseudónimos também
  não são estáveis entre pedidos, para que não se acumule um perfil.

### Notas — o que ainda não está ligado

- **As capabilities continuam sem atribuição comercial.** `help_assistant` e
  `ai_pedagogical_analysis` continuam no catálogo e em nenhum plano, tal como a
  0.83.0 as deixou: a que subscrição pertencem é uma decisão comercial que
  continua por tomar, e esta fatia não a tomou. Na prática, hoje nenhuma
  organização tem acesso, os dois ecrãs mostram o estado «plano», e nada chega a
  um motor. Para um piloto, o caminho suportado continua a ser um override por
  organização — que é também como os testes desta fatia concedem acesso.

- **O Gemini real continua por ativar.** Esta instalação não tem credencial nem
  fornecedor configurado, e nenhum teste faz uma chamada real: o fluxo é
  exercitado de ponta a ponta contra o fornecedor Fake. A ausência de credencial
  é um estado suportado, e as duas páginas continuam a funcionar sem IA — os
  artigos do Centro de Ajuda e a Estatística da turma não dependem dela.

- **Um nome escrito à mão numa pergunta de ajuda não é removido.** O sanitizador
  retira emails, telefones, códigos postais, endereços de internet, números na
  forma `n.º NN`, identificadores internos e corridas de seis ou mais
  algarismos — reconhece **formatos**, não pessoas. Um nome por extenso não tem
  formato que o distinga de qualquer outra palavra, e o Centro de Ajuda
  deliberadamente não tem uma pauta com que o comparar: ir buscar uma
  significaria este fluxo tocar em dados de alunos para evitar enviar dados de
  alunos. As mitigações são as que existem — o texto do campo pede que não se
  escrevam dados pessoais, o artigo explica-o, e a pergunta não fica guardada.
  Um teste fixa esta limitação de propósito, para que o dia em que mudar seja
  uma decisão e não uma descoberta. **Não é anonimização e não é descrita como
  tal.**

## [0.83.0] — 2026-08-28

Infraestrutura central de IA: uma porta única para fora, uma política de
privacidade única, e um painel onde o operador liga, desliga e mede. **Nada
disto está visível a um professor hoje** — ver as duas notas no fim da entrada.

### Added

- **Gateway central de IA (`AiGateway`).** Passa a existir um só ponto de saída
  da aplicação para um motor de IA. Uma chamada atravessa, por esta ordem:
  entitlement da capability → existe motor configurado → rate limit por minuto
  (utilizador e organização) → quota (utilizador/dia, organização/mês) →
  verificação de privacidade do payload → chamada → registo de utilização. Cada
  recusa fica registada antes de a exceção subir. Funcionalidades novas chamam
  `ask()` e mais nada: não resolvem fornecedor, não leem credencial, não decidem
  planos e não contam nada por sua conta. O contrato que a camada de
  experiências consome está em `docs/ai-core-contract.md`; as decisões e os
  porquês em `docs/adr/0007-ai-core-one-gateway-one-policy.md`.

- **Fornecedor Gemini.** `GeminiProvider` fala `POST /models/{model}:generateContent`
  e regista-se como qualquer outro driver — uma classe, um binding, um `case` no
  resolver. Não existe `if ($provider === 'gemini')` em lado nenhum, e trocar de
  motor é uma alteração de definições. A chave viaja no cabeçalho
  `x-goog-api-key` e **nunca** em `?key=`, porque proxies e access logs registam
  URLs. Trata 401/403, 429, 5xx, timeout e host inalcançável — e os casos em que
  um HTTP 200 não é resposta: `blockReason`, `finishReason: MAX_TOKENS`, sem
  candidatos, sem partes, texto vazio.

- **O fornecedor Fake passa a cobrir o fluxo completo.** Permite desenvolvimento
  e testes de ponta a ponta sem credencial nenhuma, e continua a recusar-se a
  correr em produção.

- **Administração → Inteligência Artificial (`/admin/ai`).** Só `platform-admin`.
  Liga e desliga a IA, escolhe fornecedor e modelo, define timeout, teto de
  tokens de resposta, pedidos por minuto e quotas por capability, guarda ou
  substitui a credencial, e testa a ligação contra o fornecedor real. O que fica
  guardado sobrepõe-se ao `.env` ao arranque, e o que não fica cai para lá — o
  mesmo arranjo que a configuração de SMTP já usava. O estado apresentado no ecrã
  é a resposta do resolver e não o valor da caixa: ativar sem credencial mostra o
  motivo, em vez de um sinal verde que nenhum outro ecrã confirma.

- **Medição por chamada (`ai_usage_events`).** Uma linha por chamada, com
  organização, utilizador, capability, caso de uso, fornecedor, modelo, tokens de
  entrada/saída/total, duração, estado (`succeeded`/`failed`/`blocked`),
  categoria de erro e um hash do assunto. **A tabela não tem coluna onde caiba um
  prompt ou uma resposta** — não é uma regra que alguém siga, é o esquema. Fecha
  a dívida nº 3 registada no ADR-0006 («os contadores são gravados desde o
  primeiro dia, mas não há painel que os leia»).

- **Quotas e rate limiting configuráveis.** Quotas por utilizador/dia e por
  organização/mês, e rate limit por minuto com um balde independente por
  capability — um professor a esgotar o assistente de ajuda não pode ser o motivo
  de uma análise pedagógica ser recusada. Os valores vêm de configuração e do
  painel, nunca de constantes no código. O rate limit vive **dentro** do gateway
  e não em middleware de rota, para valer também para um job ou um comando; uma
  rota construída sobre o gateway não deve acrescentar `throttle:` para a mesma
  capability.

- **Duas capabilities novas: `help_assistant` e `ai_pedagogical_analysis`.**
  Separadas de propósito — uma escola pode razoavelmente querer o assistente do
  Centro de Ajuda e não a análise pedagógica, e é a segunda que vê material sobre
  crianças. Nenhuma delas é `ai_assistance`, que fica exatamente onde estava, a
  controlar «Aperfeiçoar redação» nos Relatórios e o sugeridor de estratégias nas
  Intervenções.

- **`AiContext` — construção de contexto por allowlist.** A ordem obrigatória
  para qualquer conteúdo com estrutura é **allowlist → pseudonimização →
  serialização → sanitização**. Cada valor é pseudonimizado no momento em que é
  acrescentado, enquanto ainda é um escalar isolado com significado conhecido, e
  só depois é que alguma coisa é junta em texto. `add()` recusa em tempo de
  execução tudo o que não seja `string|int|float|null`: um registo Eloquent
  satisfaz um parâmetro tipado `string` por coerção (`Model::__toString()`
  devolve `toJson()`), o que teria posto uma linha inteira da base de dados
  dentro de um prompt, em silêncio.

- **Auditoria de plataforma.** `audit_events.organization_id` passa a admitir
  nulo, e o registo ganha `recordPlatform()` para atos do operador que afetam
  todas as organizações e não pertencem a nenhuma. Eventos:
  `ai.settings_updated` (com os campos alterados e os valores novos),
  `ai.credential_created`, `ai.credential_replaced`, `ai.credential_removed` e
  `ai.connection_tested`. As linhas de plataforma são invisíveis na trilha de
  qualquer organização, porque o global scope compara a coluna a um número e NULL
  nunca é igual a um número.

### Changed

- **A pseudonimização passa a ter uma só implementação.** O algoritmo que vivia
  em `PseudonymMap` (mais longo primeiro, nomes próprios incluídos, tokens curtos
  excluídos, palavra inteira) mudou-se para `App\Support\Privacy\Pseudonyms`, sem
  alteração de comportamento. `PseudonymMap` continua a existir e a responder
  exatamente o mesmo — passou a saber apenas **quais** os nomes de um relatório,
  e delega o resto.

- **`sanitiseFields()` deixa de juntar os campos antes de os limpar.** Passa a
  delegar no `AiContext` e ganha a ordem correta. A versão anterior montava o
  parágrafo e sanitizava-o, o que fazia das expressões regulares a única barreira.

- **`AiRequestFailed` ganha uma categoria de erro** (`timeout`, `unauthorized`,
  `rate_limited`, `refused`, `provider_error`, `unusable_answer`, `unreachable`),
  a partir de um vocabulário fechado. É o que a medição grava e o que o painel de
  administração mostra ao operador. A mensagem apresentada a um professor não
  mudou, e continua sem distinguir um 401 de um 500.

### Security

- **Minimização e pseudonimização centralizadas.** `AiPayloadSanitizer` remove
  por omissão nomes da pauta (que passam a «Aluno A», «Aluno B»), emails,
  telefones, códigos postais, URLs, números de registo na forma `n.º NN`,
  identificadores internos (ULID/UUID) e qualquer corrida de seis ou mais
  algarismos. Mantém percentagens, classificações, contagens e períodos — sem a
  substância pedagógica não há pergunta que valha a pena fazer. Depois de
  substituir, **verifica o seu próprio trabalho**: volta a correr todos os
  detetores e recusa o payload se algum disparar.

  É **pseudonimização**, e o termo é escolhido com cuidado: «Aluno A» é
  reversível, e o mapa que o reverte existe em memória nesta máquina durante um
  pedido. Não é anonimização e não deve ser descrito como tal em documento
  nenhum. O sanitizador é a **segunda** barreira e não a única — é feito de
  expressões regulares à procura de formas que conhece, e é cego a um nome de rua
  ou a um irmão. A minimização a sério acontece no `AiContext`, onde ainda se vê
  o que cada valor é.

- **A credencial do fornecedor não pode ser lida de volta.** Guardada cifrada em
  `platform_settings.ai_api_key`, não é preenchível por atribuição em massa, e
  tem um único caminho de escrita e um único caminho de leitura. **Não existe
  rota que a devolva**: a ação chama-se «Substituir credencial» e não «Mostrar
  chave». O painel mostra apenas «configurada», a data, e os últimos quatro
  caracteres quando a chave tem vinte ou mais. Não aparece em payloads Inertia,
  logs, mensagens de exceção nem linhas de auditoria — nem o valor, nem o
  comprimento, nem um hash. Uma credencial recusada na validação também não fica
  na sessão (`dontFlash`, registado no handler de exceções, que é o único sítio
  onde essa lista tem efeito).

- **A proveniência do payload é imposta pelo tipo.** `AiAsk` não aceita `string`:
  aceita `SanitisedPayload`, cujo construtor é privado e cuja classe é final, e
  cuja única fábrica está marcada `@internal`, exige um sanitizador e tem um teste
  de arquitetura a garantir que não ganha um segundo chamador. O gateway volta a
  verificar o payload imediatamente antes do fio, sem confiar no tipo.

- **Em produção, um gestor de segredos externo é preferível** a uma coluna
  cifrada, com a chave injetada em `LAPIS_AI_KEY` no deploy e o campo do painel
  vazio: uma coluna cifrada só é tão forte quanto a `APP_KEY` que a decifra, e a
  `APP_KEY` vive no mesmo ficheiro, na mesma máquina. A ordem de fallback já torna
  esse o arranjo suportado.

### Notas — o que ainda não está ligado

- **Nenhum plano inclui as capabilities novas.** `help_assistant` e
  `ai_pedagogical_analysis` estão no catálogo e **em nenhum plano**: a que
  subscrição pertencem é uma decisão comercial que ainda não foi tomada, e
  escrevê-la num array seria tomá-la em silêncio. Hoje, portanto, nenhuma
  organização as tem, todos os ecrãs mostram o estado «plano», e nada construído
  sobre o gateway chega a um motor. Um teste falha de propósito no dia em que
  alguém as compuser num plano. Para um piloto, o caminho suportado é um override
  por organização.

- **Não há ativação real do Gemini, por ausência de credencial.** O driver está
  escrito e testado de ponta a ponta contra um cliente HTTP simulado, mas esta
  instalação não tem chave do fornecedor e nenhum teste faz uma chamada real. A
  ausência de credencial é um estado suportado: o resolver responde
  `credential_missing`, o painel diz o que falta, e a aplicação inteira continua a
  funcionar.

## [0.82.0] — 2026-08-28

> **Duas linhas de trabalho partilharam este número, e não foram o mesmo
> release.** A frente comercial e a frente do Centro de Ajuda seguiram em
> paralelo sem se verem, e ambas lançaram uma 0.82.0: a comercial chegou a
> produção, a de Ajuda ficou no repositório e recebeu por cima a 0.83.0 e a
> 0.84.0. As duas ficam aqui, cada uma identificada, porque foi isso que
> aconteceu — o formato deste changelog admite uma entrada por versão, e é essa
> a única razão de partilharem um cabeçalho. A 0.85.0 é o primeiro número a
> designar de novo um só estado do produto.

### Frente comercial — o release que esteve em produção

#### Added

- **Um professor passa a poder subscrever o Pro sozinho, por transferência bancária.** Até agora o domínio comercial só tinha a metade do operador: registar um pagamento recebido, marcar a condição, reembolsar, anular. Do lado de quem compra não havia nada — nem forma de dizer «quero o Pro», nem sítio para os dados de faturação, nem IBAN. O `/settings/plan` oferecia o período experimental e mais nada.

  O caminho é: **escolher o Pro → preencher os dados de faturação → receber referência e IBAN, no ecrã e por email → transferir → um administrador confirma**. Cada passo faz uma coisa só.

  **O checkout não aprovisiona nada, e é o ponto todo.** Cria um `SubscriptionPayment` em `pending` e deixa a conta exactamente no plano em que estava. Uma transferência não avisa ninguém quando chega; dar acesso no momento do clique seria dar acesso a quem clicou e não a quem pagou. O ecrã diz isso com todas as letras em vez de se parecer com um recibo.

  **`recorded_by` fica a `null` e `provider` a `bank_transfer`.** A distinção é a que o `RecordSubscriptionPayment` já fazia ao deixar `provider` nulo: aquilo é dinheiro que um operador confirmou ter recebido, isto é uma intenção declarada por quem compra. Pôr o cliente em `recorded_by` faria uma passar pela outra.

  **Um segundo clique devolve a mesma referência.** Os dados de faturação actualizam-se — é para isso que se volta ao formulário —, mas não nasce uma segunda referência: duas referências para a mesma compra é a forma mais rápida de ninguém saber o que foi pago.

- **`billing_profiles`** — nome, NIF, morada e email de faturação, separados do nome da organização porque divergem quase sempre: «Escola de Alvalade» não é a entidade que paga. O NIF é opcional de propósito; um particular pode pedir fatura sem contribuinte, e obrigar afastaria quem compra a título individual.

- **A condição Membro Fundador passa a ser contável.** A landing promete «os primeiros 250 ou até 31 de dezembro de 2026», e esse número vivia só em `commercial.ts` — do lado do browser, onde não se conta nada. O 251.º comprador teria visto 29,90 €. `FounderAvailability` conta-os a partir de um facto registado (as subscrições que um operador marcou como `founder`), nunca deduzido do valor pago — a regra que o `RecordSubscriptionPayment` já impunha.

- **As transferências por confirmar aparecem no topo do `/admin/commercial`**, num painel próprio, fora dos filtros e da paginação. Um pedido por confirmar não é um plano — é trabalho por fazer, e estava invisível: só se encontrava abrindo a ficha de uma conta que já se soubesse ter pago. Quem abre o ecrã comercial passa a ver o que está à espera dele, com a referência, a conta, o valor e há quantos dias espera.

- **E o passo de ativar o plano deixou de estar escondido.** Registar dinheiro continua a não mudar o plano — a separação é deliberada e mantém-se —, mas quem acabou de confirmar um pagamento tinha de sair do ecrã, encontrar a ficha da conta noutro sítio do backoffice e lembrar-se do que ia lá fazer. Agora aparece ali, e **só quando faz sentido**: há dinheiro registado e a conta ainda não está no Pro, que é exactamente o estado de quem se esqueceu de um passo.

- **«Confirmar recebimento» no backoffice**, na ficha comercial da conta, com o valor e a data editáveis: quem confirma está a ler o extrato, e se lá estiver outro número é esse que é receita.

  **Produz duas linhas, não uma alterada.** Um pagamento é imutável excepto no estado e `paid_at` não se escreve depois da criação — uma linha `paid` sem data cairia fora de todos os totais por período enquanto contava para o total de sempre. Por isso o pedido é anulado com razão e o pagamento verdadeiro é registado de novo, que é exactamente o mecanismo que o `CorrectSubscriptionPayment` documenta para qualquer correcção.

  **E não activa o plano.** Aprovisionar e cobrar continuam a ser factos independentes; o aviso ao operador di-lo em vez de o esconder, porque é o passo que se esquece.

- **O IBAN vive no `.env`, não na base de dados** — ao contrário do SMTP e do endereço de contacto público. Um IBAN é para onde vai dinheiro: numa tabela, quem entrasse no backoffice redirecionava os pagamentos sem tocar no servidor. `BILLING_BANK_BENEFICIARY`, `BILLING_BANK_IBAN` e `BILLING_BANK_BIC`; sem IBAN configurado não há botão nenhum, em vez de haver um que leva a um campo em branco.

- **O cliente é avisado quando o pagamento entra.** Sem isto, o silêncio entre transferir e ver o plano mudado é indistinguível de o pagamento se ter perdido — e a primeira coisa que uma pessoa faz nesse silêncio é escrever a perguntar, ou transferir outra vez. O email diz o valor, a referência e até quando vale a subscrição, e **não promete o que não controla**: diz que o plano vai ser ativado, não que já está, porque ativar é o acto seguinte.

- **Os logótipos passam a ser os da identidade.** O `AppLogoIcon` era um símbolo desenhado à mão em código — um lápis e um ponteiro de relógio inventados aqui dentro, que nunca foram a marca de ninguém — e o `favicon.svg` era **o do Laravel**, por substituir desde o arranque do projeto. Entram a marca e o logótipo por extenso dos ficheiros originais, mais o `favicon.ico` (16/32/48) e o `apple-touch-icon` a 180px.

  **Um ficheiro por logótipo, não dois.** As duas versões da identidade — a azul e a branca — diferem só na cor do texto, por isso essa parte é `currentColor` e a troca de tema faz-se com uma classe. Duas imagens trocadas por CSS piscariam ao mudar de tema e obrigariam a carregar as duas. O favicon leva a mesma ideia dentro de si, com uma media query: numa barra de separadores escura, o azul da marca desapareceria.

#### Fixed

- **Quem carregava em «Escolher Pro» aterrava no painel.** O Fortify mandava toda a gente para `/dashboard` depois de entrar, sem olhar ao destino guardado em sessão. O visitante entrava, via um painel com dezoito entradas de menu, e tinha de descobrir sozinho onde é que se subscreve — o passo em que se desiste. O botão passa a apontar para o checkout (o `Authenticate` guarda-o), e o `LoginResponse` lê-o.

  **E o `VerifyEmailResponse` também, que é onde isto quase falhava.** Quem se regista só volta à aplicação depois de clicar no link que lhe chega por correio, muitas vezes noutro dispositivo e meia hora depois; consumir o destino no registo deitá-lo-ia fora antes de servir para alguma coisa.

- **Os emails do Laravel saíam em inglês.** «Hello!», «Verify Email Address», «Regards,» e «All rights reserved.» chegavam assim a professores portugueses — a verificação de conta é o primeiro email que alguém recebe do produto, e estava numa língua que não é a do produto. Todos passam por `Lang::get()`, por isso um `lang/pt_PT.json` resolve os dois emails inteiros e qualquer notificação predefinida que venha a existir, sem tocar numa classe.

  A chave do rodapé do botão **tem uma quebra de linha a meio** e só corresponde byte a byte — traduzida por aproximação, voltaria silenciosamente ao inglês.

- **O nome no cabeçalho e no rodapé dos emails vinha do `APP_NAME`**, que em produção ainda dizia `LAPIS`. Não é código: é uma linha no `.env` do servidor, e sem ela o rebranding da 0.79.0 não chegou ao correio.

#### Changed

- **O guarda do período experimental ficou mais apertado, não mais frouxo.** Proibia em bloco qualquer tabela cujo nome contivesse `billing`; com o `billing_profiles` a existir, passou a afirmar a lista exacta — um `billing_cards` que alguém acrescente falha aqui, em vez de passar por conter a mesma palavra. E verifica agora que activar a experiência não cria sequer um perfil de faturação.

- **O prefixo das referências é `LPRO`**, não `LAPIS`: a marca mudou na 0.79.0 e a referência que um professor lê ao telefone segue-a.

### Frente Centro de Ajuda — a linha que continuou no repositório

A pesquisa do Centro de Ajuda passa a responder a perguntas escritas, e não só
a palavras soltas.

#### Changed

- **A pesquisa do Centro de Ajuda deixa de tratar a pergunta como uma única
  string literal.** Procurava a query inteira com `str_contains` contra título,
  keywords, resumo e conteúdo — o que responde a «avaliação», por ser
  literalmente uma palavra dos artigos, e fica em silêncio perante «por onde
  começo», «o que faço primeiro» ou «quais são as primeiras coisas a fazer na
  aplicação?». O artigo «Começar a utilizar o Lapispro» responde às três e não
  era alcançado por nenhuma: das 24 perguntas naturais medidas antes da
  alteração, **15 devolviam zero resultados**. A falha nunca foi do conjunto de
  artigos — é que uma pergunta escrita não é substring de nada.

  Passam a existir duas passagens. A primeira é a antiga, intacta: a frase
  completa continua a ser procurada com os mesmos pesos (título 8, keywords 5,
  resumo 3, conteúdo 1), pelo que nenhuma pesquisa que funcionava deixou de
  funcionar nem trocou de primeiro resultado. A segunda reduz a pergunta aos
  termos que interessam, alarga cada termo ao seu grupo de sinónimos e dá a
  cada termo o **melhor** campo que alcança — melhor e não soma, para uma
  palavra repetida no corpo de um artigo não pesar mais do que uma no título.

- **Normalização mais completa.** Além de minúsculas e acentos, a pontuação é
  removida e os espaços colapsados, pelo que «Avaliação?» e «avaliacao»
  devolvem exactamente o mesmo. Uma só regra de folding para a pergunta e para
  os artigos: dois foldings a divergir é o tipo de erro que fica invisível até
  a pesquisa começar, em silêncio, a não acertar.

#### Added

- **`config/help-search.php`** — as stopwords e os grupos de sinónimos, como
  **dados e não regras em código**. Quem notar uma pergunta que não encontra
  nada acrescenta uma palavra ao ficheiro, revisível num diff e reversível, em
  vez de um ramo no scorer. Os grupos são simétricos («estas palavras
  significam o mesmo aqui») e são uma afirmação sobre o vocabulário deste
  produto, não um dicionário de português: «começo/começar/primeiros passos»,
  «ano escolar/ano letivo», «aluno/estudante», «adicionar/criar/novo»,
  «avaliar/avaliação».

  A lista de stopwords é deliberadamente aborrecida. Cada palavra lá é uma
  palavra que a pesquisa deixa de encontrar, por isso «ano», «nota», «conta» e
  «peso» — que têm forma de palavra de pergunta em português corrente e são
  assuntos deste produto — ficam de fora, e não devem ser acrescentadas.

#### O que impede isto de devolver lixo

Alargar termos, por si só, transforma uma pesquisa num gerador de artigos ao
acaso. Duas regras impedem-no:

- **Correspondência por prefixo no início de palavra**, e não `str_contains`,
  na passagem de termos. É o que mantém «ano» fora de «plano», enquanto deixa
  «turma» alcançar «turmas» e «escolar» alcançar «escolaridade».

- **Gate de sobreposição.** Um termo isolado só conta se aterrar onde o artigo
  declara o que é — o seu título ou as suas keywords; qualquer coisa mais fraca
  precisa de um segundo termo a corroborar. E duas palavras do mesmo grupo de
  sinónimos contam como uma, senão «primeiros passos» passaria o gate apenas
  por dizer duas vezes a mesma coisa. «existe parecido» continua a devolver
  zero, apesar de «existe» aparecer no corpo de três artigos.

#### Não incluído nesta fatia

Nada de IA, embeddings, base de dados vectorial, chat ou serviço externo — e
nenhuma dependência nova. A pesquisa continua determinística: a mesma pergunta
devolve sempre os mesmos artigos pela mesma ordem, e o comportamento é função
apenas do config e dos artigos. As rotas (`help.index`, `help.search`,
`help.show`), os URLs e o conjunto de nove artigos ficam exactamente como
estavam — **nenhum artigo foi alterado**, porque o vocabulário já lá chegava.

## [0.81.0] — 2026-08-28

Onboarding e Centro de Ajuda: orientar quem chega pela primeira vez sem tour
obrigatório, sem modal e sem bloquear ninguém.

### Added

- **"Primeiros passos"**, no Dashboard. Um cartão dispensável, nunca uma
  janela obrigatória, com quatro passos calculados ao vivo a partir dos dados
  reais — turma criada, aluno inscrito, instrumento criado, resultado
  registado — nunca de uma caixa de verificação guardada. É um conceito
  deliberadamente separado da checklist de "configuração mínima" já existente
  (`readiness()`, "Prepare o ano letivo"): uma conta pode estar totalmente
  configurada e ainda não ter começado a usar o Lapispro, ou o inverso, e as
  duas nunca se misturam. A única coisa que fica guardada é ter dispensado o
  cartão — o progresso em si nunca é, para não haver um checkbox a dizer que
  algo aconteceu quando não aconteceu.

- **Estados vazios mais orientadores.** Sete páginas que mostravam apenas
  "Ainda não tem turmas." (ou equivalente) sem qualquer ação passam a
  oferecer o botão para o primeiro passo real — sempre condicionado a ter
  permissão para o executar.

- **Centro de Ajuda** (`/help`), acessível a partir do menu do utilizador em
  qualquer página autenticada. Nove artigos escritos a partir do que a
  aplicação faz hoje — começar a utilizar o Lapispro, criar uma turma,
  inscrever ou importar alunos, configurar perfis de avaliação, criar um
  elemento de avaliação, registar resultados, consultar relatórios e pautas,
  exportar dados — cada um verificado contra o código real antes de escrito,
  não documentação especulativa. Pesquisa simples, tolerante a acentos, sem
  motor externo.

- **Ajuda contextual** ("Precisa de ajuda?") nos três pontos da aplicação com
  maior carga cognitiva para quem começa: configurar perfis de avaliação,
  criar um elemento de avaliação, e a pré-visualização da importação de
  alunos.

- **Preparação estrutural para um futuro Assistente Lapispro.** O Centro de
  Ajuda já responde a "encontra um artigo", "pesquisa" e "o que é relevante
  nesta página" através de uma única classe de leitura — o mesmo sítio que um
  assistente viria a consultar. Nada de IA, chat, fornecedor externo ou chave
  de API nesta versão: só a base de conhecimento que uma resposta desse tipo
  precisaria de ler.

## [0.80.0] — 2026-08-28

Backoffice comercial para o superadministrador, construído sobre uma separação
que o modelo não tinha: **plano ≠ condição comercial ≠ pagamento**.

### Added

- **`Admin > Comercial`** (`/admin/commercial`) — contas, subscrições e receita
  numa área própria, reservada ao operador da plataforma. Cartões de resumo,
  listagem paginada com filtros, detalhe comercial por conta e exportação CSV.

- **Condição comercial na subscrição** (`organization_subscriptions.commercial_condition`).
  «Membro Fundador» **não é um quarto plano** — é uma condição de adesão ao Pro,
  e um Fundador tem direito exactamente aos mesmos módulos que um Pro standard.
  `Entitlements` nunca lê esta coluna, por desenho: marcar uma conta como
  Fundadora não lhe dá nada. As condições são `standard`, `founder`, `voucher`,
  `admin_grant`, `institutional`, `legacy` e `other`; `trial` **não** é
  armazenado, porque `status = trial` já o regista de forma imutável, e duplicá-lo
  criaria dois sítios para discordarem.

- **`subscription_payments`** — dinheiro que entrou mesmo, e mais nada. Valor em
  cêntimos inteiros, moeda, estado, método, referência, condição comercial no
  momento do pagamento, código de voucher como texto literal, período pago, data
  de recebimento, autoria e metadata. Sem gateway, sem dados de cartão, sem
  factura ou recibo — nada disso está no âmbito.

- **Registo manual de pagamento recebido.** Como não há gateway, esta é a única
  forma de entrar dinheiro no sistema: uma transferência ou MB WAY que alguém
  confirmou, lançada depois. Registar um pagamento **não mexe no plano** — pagar
  não é provisionar.

- **Correcção sem reescrita.** O modelo recusa, ao nível do próprio Eloquent,
  qualquer update que toque no valor, na moeda, na data de recebimento, na
  organização, no período ou em quem registou. Só o estado se move, e sempre com
  motivo e autoria: **reembolso** (o dinheiro voltou) ou **anulação** (o registo
  estava errado). Corrigir um valor mal lançado é anular com motivo e registar o
  certo — duas linhas e uma trilha, nunca uma linha que mudou de sentido.

- **Auditoria comercial** — `commercial.condition_set`, `commercial.payment_recorded`,
  `commercial.payment_refunded` e `commercial.payment_voided`, na trilha da
  organização-alvo, como todas as acções de operador. O detalhe da conta mostra-as
  a par de `admin.plan_changed`, `admin.subscription_suspended` e
  `admin.subscription_reactivated`.

### Changed

- **`ActivateProTrialTest` deixou de afirmar que não existe nenhuma tabela de
  pagamentos.** Era verdade quando foi escrito e passou a ser deliberadamente
  falso. O que aquele teste protegia continua igual, e é o que passou a afirmar:
  **um trial não toca em dinheiro** — nenhuma linha de pagamento, nenhum cêntimo
  de receita, e continua a não haver gateway, factura, cartão ou checkout.

### Regra de receita

Receita é a soma dos pagamentos com estado `paid`, agrupada por `paid_at` — a
data em que o dinheiro chegou, nunca a data em que alguém o lançou. `pending`,
`failed`, `cancelled` e `refunded` não contam. **Nunca** `nº de contas Pro ×
preço de tabela`: essa conta estaria errada para todas as linhas desta base de
dados, porque uma subscrição Pro pode existir por condição de lançamento,
voucher, trial, concessão administrativa ou acordo institucional, e nenhuma
dessas é 44,90 €.

Sem pagamentos registados, o valor correcto é **0 €**, e o painel diz porquê em
vez de preencher o espaço com uma estimativa.

### Dados históricos

**Nada foi preenchido retroactivamente.** Todas as subscrições existentes ficam
com `commercial_condition` a NULL, apresentado como «Origem não registada» — não
«standard». Uma conta Pro de hoje pode ter chegado ali por concessão do operador,
por um trial que converteu ou por uma condição de lançamento, e a base de dados
nunca guardou a evidência para as distinguir. Um valor errado com ar de certo é
pior do que um desconhecido honesto, por isso é o operador que marca cada uma.

`trial` é a excepção que não precisa de backfill: deriva de `status = trial`,
que `ChangeOrganizationPlan::supersede()` já preserva para sempre.

### Privacidade

A área comercial não transporta um único dado pedagógico (§19). Nem aluno, nem
turma, nem classificação, nem relatório — o entitlement em vigor é enviado como
**contagem**, não como lista de chaves, precisamente para que `students`,
`classes` e `reports` não apareçam sequer como nomes num payload comercial. Os
únicos dados pessoais presentes são o nome e o email do titular da conta, que é
o mínimo para gerir uma subscrição.

### Vouchers

Continuam **sem backend**. Não há tabela de vouchers, campanha, validação nem
resgate, e esta fatia não inventou nenhum: o código é guardado como texto literal
porque escrevê-lo é registar um facto, ao passo que resolvê-lo seria inventar um
sistema que não existe. A `LandingVoucher` continua exactamente como estava.

E a UI di-lo onde o código é **lido**, não apenas onde é escrito. Um «Código: X»
nu, ao lado de um valor em euros, lê-se como se o sistema tivesse verificado
alguma coisa — por isso o código aparece agora marcado como **referência
administrativa, não validada nem resgatada pelo sistema**, e o painel diz o
mesmo do balde «Voucher» na repartição do Pro.

## [0.79.2] — 2026-08-27

Auditoria de fecho do rebranding, fora da landing. A landing não foi tocada.

### Fixed

- **O pacote de configuração descarregava-se como `lapis-configuracao-<data>.json`.** É um ficheiro que um professor envia a outro, e o nome com que chega é marca — passou despercebido em 0.79.0 porque o nome está numa `Content-Disposition`, e não numa string que uma busca por texto visível apanhe. Passa a `Lapispro-configuracao-<data>.json`.

  O `kind` lá dentro — `lapis_configuration_package` — fica exatamente como está: é validado com `in:`, e renomeá-lo rejeitaria todos os pacotes já exportados.

- **`.env.testing.example` ainda declarava `APP_NAME=LAPIS`.** É um ficheiro versionado, e `APP_NAME` alimenta três sítios de uma vez: o `<title>` de todas as páginas autenticadas, o `MAIL_FROM_NAME` e o `name` que o Inertia partilha com os layouts de autenticação. Quem montasse um ambiente de testes a partir dele reintroduzia a marca antiga nos três.

### Added

- **Quatro testes de guarda nas superfícies que faltavam** a `BrandingTest`, escolhidas por serem as que ninguém revisita: a aplicação autenticada (o dashboard — a superfície mais vista do produto, e a que mais tempo levaria a alguém notar que ficou para trás), o email de convite (assunto e corpo — chega sem a aplicação à volta, e é a primeira coisa que alguém de fora lê), o nome do pacote de configuração descarregado, e o `APP_NAME` dos ambientes versionados.

  `downloadable_file_names_carry_the_new_brand` passa também a varrer o `ConfigurationSharingController`.

### Unchanged

- **Os identificadores internos ficam todos.** `config/lapis.php` e as chaves que lê, os nomes das variáveis `LAPIS_*`, os comandos artisan `lapis:*`, `DB_DATABASE=lapis`, `lapis_testing`, `LapisGridContract`/`LapisGridDeclaration`, os nomes definidos `LAPIS_GRID` / `LAPIS_ITEM_*` dentro dos ficheiros Excel, `backup-lapis.json` (nome de entrada dentro do ZIP, lido de volta por `ReadBackupUpload`), as versões de prompt `lapis-rewrite/1` e `lapis-intervention-suggestion/2` (persistidas em `prompt_version`, onde são registo de auditoria), e os prefixos `tempnam()`. Nenhum deles chega ao utilizador; renomear qualquer um é uma migração, não um rebranding.

- **Dois fixtures de teste continuam a dizer `product.name = "LAPIS"`**, agora com o motivo escrito por cima: representam um pacote exportado ANTES do rebranding, que é o que existe no disco de quem já exportou. O campo é informativo e nunca validado, e mantê-los assim afirma que um pacote antigo continua a importar.
## [0.79.1] — 2026-08-27

### Changed

- **Os planos passam a chamar-se Base, Pro e Institucional.** A marca já dá o contexto: num produto chamado Lapispro, «Lapispro Pro» repete-a sem acrescentar nada, e o cartão de preços dizia-a três vezes na mesma linha de visão. Chaves, slugs, entitlements e lógica de acesso intactos — só o `name` que a apresentação lê, propagado pelo `updateOrCreate` do `EntitlementsSeeder`.

- **Nenhum documento afirma que o professor é o responsável pelo tratamento.** Afirmava, e era um pressuposto que não se sustenta: um professor que trate dados de alunos sob a autoridade de uma escola não é o responsável — a instituição é. Atribuir-lhe genericamente essa qualidade atribuía-lhe também obrigações que podem não ser suas.

  Os três documentos passam a assentar no princípio, e não numa escolha por ele: **«Quem introduz dados de alunos no Lapispro deve fazê-lo enquanto responsável pelo tratamento ou devidamente autorizado pelo responsável pelo tratamento competente. Quando a HORIZONLEVEL trata esses dados apenas para prestar o serviço, atua como subcontratante.»** O Acordo diz as duas situações por extenso — o professor a título próprio, e o professor sob autoridade de escola ou agrupamento — e aplica-se às duas.

  **Sem gate, sem declaração, sem caixa de seleção.** A formulação comporta os dois casos precisamente para não obrigar ninguém a fazer, num ecrã de registo, uma qualificação jurídica que pode não saber fazer.

- **A Política troca retórica por facto.** «Não reclamamos [...] fundamento próprio [...] Fazê-lo seria afirmar um poder de decisão sobre a avaliação de menores que o produto não tem» dá lugar a **«Relativamente aos dados pedagógicos dos alunos tratados por conta do responsável pelo tratamento, a HORIZONLEVEL não determina as respetivas finalidades pedagógicas nem define a base jurídica aplicável.»** Diz a mesma coisa, é verificável, e não argumenta.

- **Nenhum documento assume que o professor é consumidor.** Podia contratar no exercício de uma atividade profissional e não o ser. As duas passagens que o classificavam passam a uma ressalva condicional — **«Quando lhe seja aplicável a legislação de proteção dos consumidores, mantém integralmente os direitos que dela resultem»** — verdadeira nos dois casos. Lei portuguesa e tribunais territorialmente competentes nos termos da lei, inalterados.

### Added

- Dois testes de guarda: `no_document_asserts_that_every_teacher_is_the_controller` varre os três documentos por sete formulações que atribuiriam a qualidade de responsável a todo o professor, e `no_document_assumes_the_teacher_is_a_consumer` faz o mesmo para a qualificação de consumidor.

## [0.79.0] — 2026-08-27

### Changed

- **O produto passa a chamar-se Lapispro, em `lapispro.com`.** 185 ficheiros, e um critério de corte só: substituiu-se o que um utilizador lê, não o que o sistema usa para se identificar a si próprio.

  **Substituído:** landing, autenticação, aplicação, menus, títulos, emails, páginas legais, FAQ, título e descrição meta, Open Graph, JSON-LD, rodapé, relatórios gerados, documentação, `APP_NAME` (alimenta `VITE_APP_NAME` e `MAIL_FROM_NAME`), e os nomes dos ficheiros que o professor descarrega — um ZIP chamado `LAPIS-exportacao` mostra o nome antigo a quem o abre meses depois.

  **Intocado:** comandos artisan (`lapis:build-package`, `lapis:make-admin`, `lapis:release-check`, `lapis:repair-overlapping-subscriptions`), `config/lapis.php` e as chaves que lê, os NOMES das variáveis `LAPIS_*`, `DB_DATABASE=lapis`, `LapisGridContract`/`LapisGridDeclaration` e os nomes definidos `LAPIS_GRID` / `LAPIS_ITEM_*` dentro dos ficheiros Excel. O último é um contrato de compatibilidade: renomeá-lo partia todas as grelhas que um professor já descarregou. Renomear qualquer um dos outros é uma migração de infraestrutura, não um rebranding.

  **O acrónimo desapareceu.** «Laboratório de Apoio ao Professor, Informação e Simplificação» já não descreve nada — as iniciais deixaram de coincidir com o nome. Vivia em quatro sítios, incluindo uma secção da landing que assentava inteiramente nele, com as letras destacadas. Essa secção ganhou texto novo sobre a ferramenta; os outros três perderam-no sem substituto.

  **O CHANGELOG não foi reescrito.** As entradas anteriores mantêm o nome com que foram escritas, com uma nota no topo a dizer porquê: reescrevê-lo apagaria a própria mudança de marca.

  **O título SEO perdeu uma palavra.** «Lapispro» é três caracteres mais longo do que «LÁPIS» e empurrou o título para 63, acima dos 60 que um resultado de pesquisa mostra. Saiu «IA» e não «Turmas» — «gestão de turmas» é uma pesquisa que um professor faz, e a IA continua na descrição, no Open Graph, num cabeçalho de secção e no corpo da página.

- **Os documentos legais passam a três, porque são três figuras jurídicas.** A Política de Privacidade descrevia os dados dos alunos como se a plataforma respondesse por eles; não responde. Quem decide que alunos existem, que dados sobre eles são registados e para que servem é o professor — logo é ele o responsável pelo tratamento, e o Lapispro é subcontratante.

  A Política passa a cobrir só o que a HORIZONLEVEL decide: a conta, a segurança, o suporte, a relação comercial. Os dados dos alunos mudam-se para um **Acordo de Tratamento de Dados** próprio, em `/tratamento-de-dados`, público e indexável como os outros dois — um acordo de subcontratação que só se lê depois de aceite é um acordo que ninguém leu.

  **Reclamar uma base legal própria sobre dados pedagógicos seria afirmar um poder de decisão sobre a avaliação de menores que o produto não tem.** Há um teste que falha no dia em que alguém escrever o contrário.

- **Bases legais declaradas, e só para a conta.** Execução do contrato, obrigação jurídica e interesse legítimo. O interesse legítimo vem ponderado, não apenas invocado, e a ponderação aponta para uma restrição concreta que já existia no código: o registo de atividade não guarda IP nem navegador.

- **Os prazos de conservação passam a ser os que o código executa.** O texto lê `config/retention.php`, que é o mesmo sítio de onde `retention:execute` e `data-exports:prune` leem — um documento legal que diz «60 dias» enquanto o comando apaga aos 90 é pior do que um que não diz prazo nenhum. Publicam-se cinco prazos reais; **não se publicam** os dois valores que existem na configuração mas que nenhuma rotina cumpre (retenção pedagógica por antiguidade e purga do registo de auditoria), e a página diz que não existem em vez de os afirmar.

- **Subcontratantes: só os que se conseguem comprovar.** Contabo (alojamento) e Cloudflare (rede), ambos documentados em `docs/deployment.md`. O servidor de correio é configurado pelo operador no backoffice e não é comprovável a partir do repositório — a página diz isso em vez de nomear um fornecedor plausível, e há um teste que falha se aparecer um. As transferências são ditas com o que se sabe e com o que ainda não se pode afirmar: nenhum mecanismo de transferência é invocado, porque nenhum foi verificado.

- **Lei portuguesa e um foro prudente** — «os tribunais territorialmente competentes nos termos da lei», nunca um foro exclusivo. O professor individual contrata como consumidor, e uma cláusula de foro exclusivo contra um consumidor é do género que um tribunal desconsidera. A autoridade de controlo passa a ter nome: **CNPD**.

- **Menores, sem inventar um mecanismo que não existe.** O Lapispro não é oferecido a menores nem a encarregados de educação, e não há caminho na aplicação por onde um consentimento parental entrasse. O texto diz que não o recolhe nem verifica, em vez de descrever uma recolha que não acontece.

- **Categorias especiais, ditas a partir do modelo de dados.** Não há campo nenhum que peça saúde ou NEE — é uma exclusão deliberada, documentada em `docs/domain-model.md` §11.3. O que existe é texto livre, e o texto pede que não se escreva lá o que não há fundamento para tratar. As medidas de suporte descrevem a ação do professor e não o estatuto formal do aluno, que é a distinção que `SupportMeasureLevel` já fazia no código.

- **A exportação deixa de ser apresentada como o direito de portabilidade.** São coisas diferentes: uma é uma funcionalidade do produto, o outro é um direito cujo âmbito a lei define, e apresentar a primeira como cumprimento integral do segundo é a forma educada de o restringir.

- **«Exportação para o INOVAR» passa a «Grelhas preparadas para o INOVAR».** Não existe exportação para o INOVAR: o professor descarrega a grelha do INOVAR, carrega-a aqui, e o Lapispro devolve-a preenchida. O rótulo antigo prometia uma integração que não existe. Na mesma auditoria, **«Auditoria e Segurança» passa a «Registo de Auditoria»** — «e Segurança» num módulo institucional diz, por omissão, que os outros planos não a têm, e o isolamento por organização, a cifra da identidade do aluno, os dois passos e as passkeys existem em todos.

### Added

- **Registo da aceitação dos Termos** (`users.terms_version`, `users.terms_accepted_at`), escrito na mesma transação da conta. Uma repartição de responsabilidades que ninguém consegue demonstrar ter sido aceite não é uma repartição: é uma página no sítio.

  **A versão é a data de entrada em vigor**, e não um número à parte — dois valores que têm de concordar são duas oportunidades para deixarem de concordar. **Vem do servidor, nunca do pedido:** um campo de formulário a dizer que versão foi aceite é um campo que o cliente altera. **Não se guarda IP nem navegador** — seria recolher dados de tráfego, com base legal mais frágil, para uma finalidade que duas colunas já cumprem, e há um teste a garantir que a tabela não ganha onde os pôr. **Sem caixa de seleção:** aceitar os Termos é condição do contrato, não uma escolha separada, e uma checkbox obrigatória não acrescenta consentimento nenhum — só um passo entre o professor e a conta.

- **Testes de regressão da marca** (`BrandingTest`). Um rebranding é uma substituição em 185 ficheiros, e o que falha numa dessas não rebenta: uma página fica com o nome antigo, mais ninguém repara, e passa a haver duas marcas em produção. Duas camadas, porque o SSR está desligado: o que o servidor renderiza testa-se sobre a resposta, e o que vive só num `.vue` testa-se sobre o código-fonte. Apanhou logo dois: `APP_NAME` no ambiente de testes, e o `<Head>` do `Welcome.vue` — cujo comentário já dizia «MUST MATCH `LandingSeo::TITLE`», sem nada a impor. Divergiram nesta própria fatia.

### Fixed

- **O `<Head>` da landing voltou a coincidir com o título renderizado pelo servidor**, e passou a haver um teste a exigi-lo. Encurtar a constante para caber nos 60 caracteres deixara o componente com o título antigo — sintoma invisível: o separador diz uma coisa, o crawler lê outra.

- **O formatador de títulos deixou de reconhecer a marca por acidente.** `/l[áa]pis/i` continuava a acertar em «Lapispro» por coincidência do rebranding; passa a `/lapispro/i`.

### Deferred

- **O módulo Institucional não está disponível para adesão**, e o lançamento é para professores individuais. A landing di-lo, a FAQ di-lo, os Termos dizem-no, e o Acordo de Tratamento de Dados diz que o documento institucional **ainda não existe**.

  **O portão já é estrutural, não uma promessa:** não há caminho self-service para uma organização institucional — `CreateInstitutionalOrganization` só é alcançável por um administrador da plataforma. A lista do que tem de estar fechado antes de a atravessar (contrato institucional, acordo de subcontratação com a instituição como responsável, papéis internos, prazos acordados) está em `docs/legal.md`, e o docblock da ação remete para lá.

## [0.78.0] — 2026-08-27

### Added

- **Termos de Utilização e Política de Privacidade, em `/termos` e `/privacidade`.** Não existiam — e a aplicação trata dados de alunos, na maioria menores. Era o último dos quatro P0 da auditoria de prontidão, e o único que não era código a faltar mas uma obrigação por cumprir.

  **Cada frase foi escrita a partir do que o código faz, e verificada no código.** A identidade do aluno está cifrada e o resto da aplicação usa um pseudónimo — porque é o que `student_identities` e `students` fazem. As sessões guardam IP e navegador; o registo de atividade não. Não há analytics, marketing nem qualquer serviço de terceiros a carregar no navegador: os tipos de letra são servidos do próprio domínio. Os cookies são três, todos necessários ou de funcionamento, e por isso não há pedido de consentimento a inventar.

  **Onde o produto não permitia concluir, o texto diz que está por definir.** A identidade do responsável não existia em lado nenhum do repositório: os quatro valores estão em `config/lapis.php`, todos a `null`, e a página mostra «Por definir» em cada linha em vez de um nome plausível. Há um teste que falha se alguém os encher com um exemplo. Os subprocessadores existem mas a relação jurídica não está clara, pelo que **nenhum é nomeado**. Não se declara lei aplicável, foro, transferências internacionais, DPO nem certificações — nada disso foi validado.

  A secção de IA descreve o que o código faz e nada mais: sugere, não decide, nomes e números saem substituídos, e pode estar desligada — como está. A de retenção descreve os prazos técnicos reais e **não afirma** a diferenciação Base +2 / Pro +5 da Matriz, que não está implementada.

  O texto vive em `App\Support\Legal\LegalDocuments` e não nos componentes Vue: com o SSR desligado, texto escrito num `.vue` não chega à resposta e nenhum teste de servidor lhe poderia tocar. Daqui viaja no payload do Inertia, e doze testes afirmam o que lá está.

### Fixed

- **As páginas legais montavam o shell da aplicação autenticada e rebentavam.** O resolvedor de layout em `app.ts` não tinha caso para `legal/`, pelo que caíam no `default` e recebiam o `AppLayout` — que lê `auth.user.name` e falha sem sessão. Só se via em ecrãs largos, que é onde o menu de utilizador é desenhado; em telemóvel a página parecia correta. São documentos públicos e passam a não ter layout, como a landing.

## [0.77.0] — 2026-08-27

### Added

- **O encerramento de conta passa a ser executado.** Pedir o encerramento, a janela de 60 dias, o `scheduled_deletion_at`, os banners e o `retention:status` existiam todos — e **nada, em momento algum, o levava a cabo**. Uma pessoa podia pedir que a sua conta fosse encerrada, ver a contagem chegar a zero, e os dados ficavam exactamente onde estavam, indefinidamente. `retention:execute` é a metade que faltava, agendada diariamente às 03:40.

  **Anonimiza em vez de apagar, porque é o que o esquema permite.** Há 34 chaves estrangeiras `ON DELETE RESTRICT` para `users` — todos os `created_by`, `confirmed_by`, `reviewed_by` — e 40 para `organizations`. A decisiva: `audit_events.causer_id` é RESTRICT em todas as organizações, e o próprio `RequestPersonalAccountClosure` grava `account.closure_requested` com essa pessoa como causer. **Pedir o encerramento escreve a linha que torna impossível apagar essa conta.** Não é um acidente a contornar: o `AdminAccountController::destroy` já recusava apagar contas com dados, e o princípio está escrito lá — apagar histórico para fazer um delete passar «não está em cima da mesa (§22.4, §31)».

  Então a identidade sai e as linhas ficam. O nome, o email, a password, o 2FA, as passkeys e as sessões desaparecem de `users`; as `student_identities` e as `organization_identities` da organização pessoal são eliminadas, com as fotografias no disco — e é isso que remove toda a PII de aluno, porque `students` só guarda um pseudónimo. Uma classificação, um registo ou um relatório sobrevivem, e deixam de dizer respeito a pessoa identificável.

  **Uma organização institucional nunca é tocada.** Quem é responsável por uma não consegue sequer pedir o encerramento; na execução só pode ser membro, e a saída de um membro não leva as turmas nem os alunos de uma escola. A membership fica deliberadamente: a conta já não autentica, e removê-la deixaria `class_teachers` órfão para aulas que aconteceram mesmo.

### Fixed

- **Uma primeira versão desta ação apagava as identidades de alunos de todas as organizações da plataforma.** `StudentIdentity` **não** usa `BelongsToOrganization` — tem coluna `organization_id` mas nenhum scope global, porque é sempre alcançada através do aluno a que pertence. Um `runFor($org, fn () => StudentIdentity::query()->delete())` não filtra coisa nenhuma e esvazia a tabela inteira. O teste de isolamento apanhou-o antes de sair daqui: um encerramento levava consigo os alunos de um estranho. A consulta passa a filtrar `organization_id` explicitamente, e o aviso ficou escrito no código e na documentação, porque é uma armadilha que a próxima pessoa encontraria da mesma maneira.

## [0.76.1] — 2026-08-27

### Fixed

- **O canonical seguia o domínio por onde o pedido entrava, e não uma decisão.** `url('/')` não lê o `APP_URL` num pedido HTTP — enraíza-se em `$request->root()`; o `APP_URL` só semeia o gerador quando não há pedido nenhum (consola, filas, email). Como esta instalação é servida em **dois domínios**, o mesmo servidor respondia `<link rel="canonical" href="https://lapispro.com">` num host e `…href="https://lapis.criativatek.com">` no outro — com o `APP_URL` apontado ao segundo o tempo todo. Dois domínios a declararem-se ambos originais é conteúdo duplicado com o sinal de posicionamento dividido entre os dois, e a `sitemap.xml` e a linha `Sitemap:` do `robots.txt` introduzidas na [0.76.0] iam amplificá-lo, anunciando um site diferente conforme quem perguntasse.

  Passa a existir **`lapis.public_url`** (`LAPIS_PUBLIC_URL`): o endereço que o site declara como sendo o seu, para o canonical, o `og:url`, o `<loc>` do sitemap, a linha `Sitemap:` e os `url` dos `offers`. **Não é o `APP_URL`, deliberadamente** — o `APP_URL` é para onde o email transacional envia as pessoas (verificação, reposição de palavra-passe, convites), que é outra pergunta com outras consequências. Podem ter o mesmo valor; não podem ser a mesma definição. Por definir, recai no host do pedido, que é a resposta certa numa instalação local e em qualquer site servido num só domínio.

## [0.76.0] — 2026-08-27

### Added

- **A página pública passa a dizer o que o LÁPIS é — para quem lê e para quem indexa.** O `<h1>` era «Mais simples. Mais tempo para o que realmente importa.»: uma posição, não um produto. Passa a **«Menos peso administrativo. Mais espaço para ser professor.»**, imediatamente seguido da frase que faltava — *«O LÁPIS é uma plataforma para professores que reúne avaliação de alunos, gestão de turmas, acompanhamento pedagógico, aulas, sumários e relatórios num único lugar.»* Nenhuma das expressões que descrevem este produto — plataforma para professores, avaliação de alunos, gestão de turmas, acompanhamento pedagógico — aparecia uma única vez na página inteira.

- **Duas secções para as duas metades do produto que a página nunca mencionava.** «Funcionalidades» percorre o ano de avaliação — organizar, avaliar, acompanhar, intervir, documentar — e nenhuma dessas cinco etapas é o horário, a aula, o sumário ou a agenda, que é a maior parte do que um professor abre numa terça-feira. **«O dia a dia»** (`#dia-a-dia`) nomeia as quatro, todas reais e todas Pro. E **«Inteligência artificial»** (`#ia`) diz o que a IA faz — interpreta resultados já calculados, identifica potencialidades, propõe estratégias e o próximo passo, aperfeiçoa a redação de um relatório — e, com o mesmo destaque, o que nunca faz: **IA sugere, professor decide**, nenhuma classificação atribuída, alterada ou decidida por um modelo.

- **`robots.txt` e `sitemap.xml` passam a ser rotas.** Ambos têm de nomear o endereço do próprio site, e um ficheiro estático não o sabe: um `robots.txt` com `https://lapispro.com` lá dentro anunciava o sitemap de produção a partir de qualquer instalação local. `SeoController` usa `url()` e está certo em todos os ambientes por construção. O `public/robots.txt` foi eliminado no mesmo passo — um ficheiro real ali é servido pelo servidor web antes de o Laravel ver o pedido, e a rota nunca chegaria a correr.

- **`App\Support\Seo\LandingSeo`** — título, descrição, canonical, `featureList` e `offers` num só sítio. As mesmas quatro frases eram precisas três e quatro vezes cada (`<title>`, `og:title`, `twitter:title`; descrição em mais três), e literais que têm de concordar são outras tantas oportunidades para deixarem de concordar — de forma invisível, porque nada renderiza mal: a página apenas começa a dizer ao Google uma coisa diferente da que diz ao LinkedIn. Com os valores numa classe, `LandingSeoTest` consegue verificar que o título cabe numa página de resultados, que a descrição cabe num *snippet* e que os preços do *structured data* são exatamente os aprovados.

### Changed

- **Título e descrição.** De «LÁPIS — Mais simples. Mais tempo.» para **«LÁPIS | Plataforma para Professores — Avaliação, Turmas e IA»** (60 caracteres, dentro do que uma página de resultados mostra antes de cortar). A descrição passa a abrir com o que o produto é em três palavras, em vez de com um slogan, e a fechar na posição que o distingue de um gerador de texto. O Open Graph e o Twitter ganham títulos próprios, mais curtos, porque um cartão social corta mais cedo.

- **O `<title>` deixa de dizer duas coisas diferentes.** O servidor renderizava «LÁPIS — Mais simples. Mais tempo.» e, depois da hidratação, o formatador do `app.ts` acrescentava « - LAPIS» ao que o componente pedia — pelo que o separador do browser e o que o robô lia nunca foram a mesma string. O `<Head>` passa a usar um `<title>` filho, que não atravessa o formatador.

- **`featureList` no *structured data*.** Com SSR desligado, o JSON-LD é a única descrição substancial e legível por máquinas que existe no HTML antes de correr uma linha de JavaScript. Passa a listar quinze capacidades reais do catálogo de módulos, e dois `offers` com os preços aprovados: Base a 0 € (com `priceValidUntil` no fim do ano letivo para que o «gratuito em 2026/27» não fique a valer para sempre) e Pro a 44,90 €. Sem `aggregateRating`, sem `review`, sem contagens de utilizadores.

- **As Perguntas passam de dez a dezassete**, com as que respondem a intenção de pesquisa real — o que é o LÁPIS, gerir várias turmas, acompanhar a evolução dos alunos, horário/aulas/sumários, se usa IA, se a IA decide classificações, quanto custa o Pro, se há solução para escolas. Os preços e os limites vêm agora de `commercial.ts`, e não de literais escritos ao lado.

- **Copy semântica distribuída pelas secções existentes**, em vez de concentrada no topo: gestão de turmas e alunos em «Organizar», acompanhamento do progresso em «Acompanhar», relatórios de avaliação em «Documentar», critérios/ponderações/instrumentos em «O modelo da sua escola». «Análises avançadas» e «Sugestões pedagógicas com IA» juntam-se à lista de capacidades dos planos superiores, onde faltavam.

- **Os mocks de produto ganham texto alternativo.** Não são imagens — são DOM —, pelo que um leitor de ecrã percorria a tabela do mock e lia uma coluna de números sem saber que ecrã estava a ver. `ProductWindow` aceita agora um `label`, e as seis utilizações dizem que ecrã do LÁPIS estão a mostrar.

### Security

- **Todas as páginas que não são a página pública passam a ser `noindex, nofollow`.** A aplicação do professor está atrás de autenticação e não tem nada para posicionar, mas duas rotas públicas chegam a um browser sem sessão: a autoavaliação assinada que um aluno abre e um convite institucional. Nenhuma delas deve alguma vez aparecer num resultado de pesquisa, e «precisa de assinatura» deixa de ser resposta a partir do momento em que alguém cola a ligação onde um *crawler* a lê.

## [0.75.1] — 2026-08-27

### Fixed

- **A comparação atribuía ao Pro uma capacidade que é do Base.** «Pontos fortes e potencialidades» estava numa linha só, marcada apenas para Pro e Institucional — mas `BuildStudentStrengths` é **Base** e está escrito no próprio ficheiro que o é: reúne o domínio mais elevado, a subida já calculada, um registo positivo e um objetivo que um acompanhamento marcou como cumprido, tudo a partir do que já estava guardado. O que o Pro acrescenta não é ter pontos fortes, é **nomeá-los sem lhe perguntarem**. A linha passa a ser duas, e a fronteira da Matriz fica legível na tabela: **«Registos positivos e evidência factual»** (Base · Pro · Institucional) e **«Identificação automática de pontos fortes e potencialidades»** (Pro · Institucional). Os cartões acompanham — o Base diz «com registos positivos», o Pro diz «Identificação automática de…».

- **«IA aplicada à avaliação» era uma funcionalidade que não existe.** O produto tem duas capacidades de IA — «Sugestões pedagógicas (IA)» sobre estratégias e medidas, e «Aperfeiçoar redação» sobre uma secção de relatório — e **nenhuma toca numa classificação**. A linha é removida da tabela em vez de reescrita: nomear uma terceira capacidade porque ela aparece na matriz seria inventá-la na página. Ficam as duas que correspondem a código real, e a nota sob a tabela continua a dizer o que a IA faz e onde para. No cartão Pro, «IA pedagógica, na avaliação e nos relatórios» passa a **«IA pedagógica: sugere estratégias e ajuda a aperfeiçoar relatórios»**.

  **IA sugere, professor decide** — e nada na página passa a poder ser lido de outra maneira.

- **O exemplo do campo de contacto no backoffice deixa de ser um endereço com aspeto de definitivo.** O `placeholder` passa a `Ex.: geral@exemplo.pt`, na linha dos restantes exemplos do formulário. O endereço real continua a ser dado em `/admin/settings`, e o botão «Falar connosco» continua a não ser apresentado enquanto lá não estiver nada.

## [0.75.0] — 2026-08-27

### Added

- **A página pública passa a apresentar a oferta comercial completa: três planos, preços, condição Fundador, comparação e voucher.** A secção «Planos» deixa de dizer «preço por anunciar» e passa a **Um LÁPIS para cada forma de trabalhar** — três cartões com a mensagem principal de cada plano, o que inclui, o preço e a fronteira conceptual que os separa: **Base regista e mostra · Pro cruza, interpreta e ajuda a agir · Institucional coordena, partilha e agrega**. Base **gratuito no ano letivo 2026/27 (0 €)**, Pro **44,90 €/ano**, Institucional **sob consulta**.

  **Continuam a existir exatamente três planos.** A oferta **Membro Fundador · 2026** — **29,90 €/ano em vez de 44,90 €/ano**, para os primeiros 250 professores ou até 31 de dezembro de 2026, o que ocorrer primeiro — é uma **condição comercial do LÁPIS Pro**, e está construída para não poder ser lida como um quarto plano: uma faixa dentro da secção Planos, ancorada no cartão Pro, que nunca repete a lista de funcionalidades e diz explicitamente «não é um plano diferente».

  **A faturação é anual, e só anual.** Não há alternador Mensal/Anual e não existe preço mensal cobrável. Os valores «equivalente a menos de 3,75 €/mês» e «equivalente a menos de 2,50 €/mês» aparecem apenas como equivalência de leitura, em itálico e ao lado da frase «Subscrição anual. Não existe pagamento mensal.» — há um teste que remove essas duas equivalências do texto renderizado e falha se sobrar qualquer `/mês` na página.

- **«Compare os planos» — uma tabela derivada das tabelas de entitlements, não escrita à mão.** Cada linha declara as **chaves de módulo** que exige (`advanced_analytics`, `institution_admin`, …) e o plano responde se as tem, a partir do `moduleKeys` que o `HomeController` agora envia. Mover um módulo entre planos no `EntitlementsSeeder` muda a tabela no pedido seguinte, sem ninguém se lembrar de a editar — que é a razão de `plan_module` ser dados (§4.3).

  **Três estados, não dois.** Uma linha que a matriz comercial coloca num plano mas que o produto ainda não implementa é apresentada como **«Em preparação»**, nunca com ✓: omiti-la deturparia a oferta, e assinalá-la como disponível deturparia o produto. Hoje são três — Gestão de licenças, Configuração partilhada e Calendário institucional.

  **Desktop lê-se como tabela; no telemóvel é um acordeão por plano** (`<details>` nativo, como as Perguntas), com as mesmas linhas e os mesmos sinais, lido para baixo em vez de ao lado. Nenhum dos dois provoca scroll horizontal na página.

- **Bloco de voucher, honesto por construção.** Um campo discreto que aceita um código e **nunca finge validá-lo**: não existe backend de vouchers, por isso a página não responde «voucher aplicado» nem «código inválido» — responde que a confirmação é feita ao criar a conta, e o ponto de integração futuro é uma única função (`submit()`).

- **Endereço de contacto público, editável no backoffice.** `platform_settings.contact_email` — distinto do remetente do sistema — dá destino ao botão **«Falar connosco»** do plano Institucional. **Enquanto não estiver preenchido, o botão não é apresentado**, em vez de apontar para um endereço que ninguém lê ou para o no-reply do sistema.

### Changed

- **O último bloco da página passa a ser «Menos trabalho sobre os dados. Mais tempo para trabalhar com os alunos.»**, com a frase que sustenta o produto inteiro dita **uma só vez**, no fim: **«O professor decide. O LÁPIS simplifica o caminho.»** As três mensagens-chave ficam distribuídas por secções diferentes — a carga administrativa nos Planos, a autonomia do professor no fecho, e «o LÁPIS organiza, calcula e acompanha; o professor observa, decide e ensina» sob a comparação, junto à nota que diz o que a IA faz e o que nunca faz.

- **A IA é descrita pelo que realmente faz.** Sob a tabela: sugere estratégias e ajuda a aperfeiçoar a redação de um relatório; **não atribui nem decide classificações**. Nada na página sugere que a IA decide pelo professor.

### Fixed

- **Duas respostas das Perguntas tinham deixado de ser verdade.** «Os planos atuais não definem limites de turmas, de alunos ou de elementos de avaliação» contradizia as quotas que `App\Support\Limits\Limits` e o `EntitlementsSeeder` já impõem no servidor (`{"active_classes":8,"active_students":300}` no Base) — passa a dizer o que o Base realmente inclui (**até 8 turmas e 300 alunos ativos**, arquivados não contam, e chegar ao limite impede criar mais, nunca apaga). «Os preços dos planos Pro e Institucional ainda não foram anunciados» deixou de ser verdade com esta versão, e passa a nomear o **período experimental Pro de 30 dias** que a [0.74.0] introduziu.

## [0.74.2] — 2026-08-27

### Fixed

- **Ativar o período experimental Pro deixa de poder substituir silenciosamente um plano administrativo em vigor.** `ActivateProTrial` só verificava o TIPO da organização (Pessoal) e quem era o seu responsável — nunca QUAL plano estava realmente em vigor. Isso permitia a uma organização Pessoal já colocada manualmente no plano Institucional (o caso real que expôs o problema: duas organizações Pessoais atribuídas administrativamente ao plano Institucional) ativar um Trial na mesma, substituindo o Institucional por um Trial Pro e agendando um regresso automático ao Base — destruindo sem aviso uma atribuição administrativa. O único ciclo autorizado passa a estar explícito e a ser reforçado: **Base (em vigor) → Trial Pro → Base**. `TrialEligibility` ganha a regra canónica — a organização tem de ter exactamente uma subscrição em vigor, e essa subscrição tem de ser o plano Base — e `ChangeOrganizationPlan::startProTrial()` volta a verificá-la, já com a linha da organização bloqueada, imediatamente antes de qualquer escrita. Uma organização Pessoal em Pro, em Institucional, suspensa ou sem nenhuma subscrição em vigor deixa de poder alguma vez iniciar um Trial.

- **O ecrã de Plano deixa de oferecer o Trial a uma organização Pessoal já no plano Institucional.** O mesmo engano existia na apresentação: `PlanController::edit()` decidia o estado `'institucional'` a partir do TIPO da organização, não do plano em vigor — pelo que uma organização Pessoal no plano Institucional não caía em nenhum ramo e acabava, por omissão, classificada como `'eligible'`, mostrando o botão de ativação por cima de um plano atribuído por um operador. O estado passa a ler-se do plano realmente em vigor, e ganha ainda um estado por omissão novo para quando nada está em vigor (uma organização suspensa, ou sem qualquer subscrição) e o Trial nunca foi usado — deixando de ser lido, por engano, como "elegível".

## [0.74.1] — 2026-08-26

### Fixed

- **O backoffice da plataforma deixa de ser acessível apenas escrevendo o endereço à mão.** O `/admin` existe desde a Fatia 1, mas nenhuma parte da aplicação lá levava: o menu lateral é construído a partir dos módulos que a organização tem, e o operador do SaaS não é um módulo de organização nenhuma — pelo que a única forma de lá entrar era escrever o URL. Passa a haver uma entrada explícita, **«Administração da plataforma»**, no dropdown da conta, a abrir a rota canónica `admin.accounts.index`.

  **Só o operador a vê, e é coisa distinta de «Administração Institucional».** O que decide a entrada é `is_platform_admin`, uma propriedade da pessoa — não `organization.is_owner`, não o módulo `institution_admin`. Um administrador institucional continua a ver «Administração Institucional» na sidebar, que administra o *tenant* dele, e nunca vê esta entrada. As duas ficam separadas no sítio, no ícone e na palavra: uma diz «da plataforma», a outra «Institucional».

  **Esconder o link não é o controlo de acesso, e continua a não ser.** Para o ecrã, o cliente recebe **um único booleano**, `auth.is_platform_admin` — nenhuma lista de contas, nenhuma contagem, nada que o payload de um professor comum não pudesse também transportar. Quem force esse valor no cliente compra um link para um 403: `EnsurePlatformAdmin` permanece a autoridade, e a flag é lida da base de dados a cada pedido. Por isso também **não é preciso terminar sessão** depois de `lapis:make-admin` — a navegação seguinte já mostra a entrada.

- **Sair do backoffice passa a dizer para onde se vai.** «← Voltar à app» dizia «a app» dentro de uma aplicação com duas áreas. Passa a **«Voltar ao LÁPIS»**, para o `/dashboard` da aplicação normal. A navegação própria do backoffice — Contas, Nova conta, Email (SMTP) — mantém-se intacta.

## [0.74.0] — 2026-08-26

### Added

- **«Acompanhamento → Aluno» passa a ser um painel de decisão, e não apenas uma leitura do ano.** O ecrã que já existia — resultados, domínios, classificações, autoavaliações, registos, intervenções — ganha três leituras que antes o professor tinha de montar sozinho a percorrer tabelas: **Atenção**, factos que pedem alguma coisa (TPC por realizar, ocorrências nomeadas uma a uma pela sua gravidade real, uma intervenção cuja data de revisão já passou, um domínio sem registos de acompanhamento, uma autoavaliação submetida por analisar); **Pontos fortes**, a metade que faltava, com o domínio mais alto e o de maior subida nomeados e distinguidos entre si; e a ligação a Relatórios pré-preenchida com aluno, turma, ano e período, em vez de uma lista filtrada onde tudo era escolhido outra vez.

  A filosofia é deliberada e está escrita no código: **atenção + progresso + potencial**, nunca só a primeira. Um painel que só soubesse listar problemas seria um retrato incompleto do aluno.

- **A camada interpretativa é Pro, e é gated no servidor.** Estado 360º (rendimento, tendência, regularidade, empenho académico, atitudes e comportamento, acompanhamento, pontos fortes, margem de progressão), alertas analíticos, sinais positivos, «o que mudou», evolução observada após uma estratégia, potencialidades, próximo passo e «preparar conversa» exigem `advanced_analytics` — e uma organização Base não recebe a chave no payload, em vez de a receber e o ecrã escondê-la. Esconder um cartão é apresentação; não o calcular é a resposta.

  Cada dimensão tem um estado neutro explícito e usa-o: sem elementos suficientes, o LÁPIS assinala a limitação em vez de forçar uma leitura. Nenhuma frase prevê uma nota futura, e nenhuma atribui a uma estratégia a causa de um resultado — «foi observada evolução positiva após o início da estratégia» é o registo, e é correlação no tempo, nunca causa.

- **Estratégias e medidas ganham finalidade: Recuperação, Consolidação e Melhoria.** «Melhoria» é uma finalidade de primeira classe, não uma nota de rodapé da recuperação: um ponto forte também merece um passo seguinte. Junto dela, frequência e indicador de acompanhamento, todos opcionais — uma intervenção anterior a esta versão não tem finalidade, e é mostrada como «não especificada», nunca arrumada à força numa das três.

- **«Sugestões pedagógicas (IA)» — o professor escolhe a finalidade e o domínio, e decide sempre.** O LÁPIS propõe uma combinação inicial por finalidade, justificada com o número real que a sustenta, e o professor aceita-a ou escolhe outra. A resposta é uma proposta estruturada (nome, objetivo, aplicação, frequência, indicador, revisão) que **nunca cria uma intervenção sozinha**: adicionar, adaptar, gerar outra ou ignorar são quatro gestos do professor, e o registo só nasce no formulário que já existia, submetido por ele.

  O que sai para o motor é o mínimo: ano, disciplina, domínio, finalidade, o padrão factual observado e as estratégias já aplicadas. Nome, email e número de processo do aluno nunca acompanham o pedido — e um objetivo escrito pelo professor que os contenha é recusado antes de qualquer chamada.

### Fixed

- **A barra de contexto deixa de contradizer a página que está por baixo dela.** Disciplina, ano e turma estavam fixos a vazio em toda a aplicação; num ecrã que já sabia de que turma se tratava, o cabeçalho continuava a dizer «—». Passam a refletir a turma que a própria rota já resolveu, com a mesma verificação de acesso que o resto da página faz. O período mantém-se por preencher: nada na aplicação estabelece hoje um «período atual» canónico como estabelece uma turma, e inventar um seria pior do que deixá-lo vazio.

- **Um número deixa de chegar ao ecrã com a precisão interna do motor de cálculo.** `Phrase::number()` — a única porta por onde uma figura canónica se torna texto, e partilhada com os Relatórios — relocalizava o valor mas nunca o arredondava: quem lhe entregasse «77.766927» lia «77,766927%» numa frase, ainda que todos os sítios que a chamavam estivessem corretos. Passa a arredondar à casa decimal que a aplicação mostra, sobre a própria string decimal, sem nunca converter para vírgula flutuante. Um inteiro continua a ler-se «71%», e não «71,0%».

## [0.73.0] — 2026-08-25

### Added

- **Editar ou remover um horário recorrente (`RecurringLessonSlot`) já em vigor deixa de reescrever o que já aconteceu — passa a criar uma nova versão.** Até agora uma alteração ao dia, à hora ou ao período de um horário reescrevia-o sempre no próprio lugar, mesmo depois de já ter produzido aulas — arrastando consigo qualquer `Lesson` já materializada a partir dele. Um horário que ainda não começou continua a ser editado ou apagado no próprio lugar, porque não há nada a proteger; um horário já em vigor passa em vez disso a fechar-se na véspera da data a partir de quando a alteração deve valer (`effective_from`, pedido explicitamente ao professor) e a abrir uma linha nova a partir dessa data. A linha antiga fica exactamente como estava — mesmo dia, mesma hora, mesmo período —, com as suas próprias `Lesson` a continuar a apontar para ela; se já tinha um fim marcado antes dessa véspera, esse fim nunca é esticado, abrindo de propósito um intervalo em que nenhuma das duas versões está activa. Remover um horário já em vigor deixa igualmente de ser um apagar: a linha fecha-se em vez de ser destruída, preservando as aulas que aponta para ela. `MaterializeLessonsForRange` não precisou de mudar nada — já lia cada horário pelos seus próprios `starts_on`/`ends_on`, pelo que a fronteira entre a versão antiga e a nova é respeitada automaticamente, incluindo a meio de uma semana.

  **O caso do horário cujo próprio início é hoje.** Um horário que só entrou em vigor hoje e ainda não produziu nenhuma aula não tem histórico nenhum a proteger — comporta-se exactamente como um horário futuro: editável e apagável no próprio lugar, sem pedir `effective_from`. Assim que existe pelo menos uma aula de hoje sob esse horário, a alteração passa a exigir `effective_from`, que só pode entrar em vigor a partir de amanhã — nunca hoje, o que reescreveria a aula já criada —, com uma mensagem própria a explicar porquê em vez da mensagem genérica de "início da versão atual". Remover esse horário deixa de ser recusado: a linha fecha-se exactamente em hoje (e não em "hoje menos um dia", que produziria uma data de fim anterior à de início), preservando a aula de hoje e impedindo qualquer nova ocorrência a partir de amanhã.

## [0.72.1] — 2026-08-25

### Fixed

- **O tipo de acontecimento "Outro" passa a aparecer como "Data relevante" em todo o Calendário do Ano Letivo.** "Outro" descrevia mal um acontecimento deliberadamente amplo — uma data que importa para a organização do professor sem ser reunião, atividade ou visita de estudo, e sem implicar ausência de aulas. O valor interno (`other`) mantém-se, pelo que os acontecimentos já existentes deste tipo continuam a funcionar e passam automaticamente a mostrar a nova designação, sem qualquer migração.

- **Os marcos de fim das atividades letivas importados do calendário escolar ganham títulos completos.** "Fim 9.º ano" passa a "Fim das atividades letivas — 9.º ano", e o mesmo para os restantes agrupamentos de anos de escolaridade e ciclos identificados no documento — nunca inventando um nível de ensino que o ficheiro não mencione.

## [0.72.0] — 2026-08-25

### Added

- **"Sugerir feriados nacionais" propõe os treze feriados nacionais portugueses do ano letivo selecionado — nunca os grava sem confirmação.** Dez em data fixa e três presos à Páscoa (Sexta-Feira Santa, Domingo de Páscoa, Corpo de Deus, calculados por aritmética própria e verificados contra o calendário escolar real já usado nesta aplicação), filtrados às datas que realmente caem dentro do ano letivo. Nunca inclui Carnaval, feriados municipais ou regionais — dependem da escola ou da região, e não são um facto nacional. Cada sugestão mostra o seu estado antes de o professor decidir: novo, já existente, designação diferente (a mesma data, um nome diferente do que já lá está — sem nunca criar uma segunda linha), ou conflito.

### Fixed

- **Feriados iguais deixam de poder ser criados duas vezes por vias diferentes.** A regra que decide se um feriado ou uma interrupção "já existe" — pela data e pelo tipo, nunca pelo texto do título — deixou de estar só dentro da importação do calendário escolar e passou a ser a mesma para todos os caminhos: criar à mão, sugerir feriados nacionais, e importar. Um feriado sugerido e confirmado não volta a aparecer como novo ao importar o calendário da escola, e vice-versa; a proveniência de um registo (manual, sugerido, importado) nunca é reescrita só porque uma segunda via reconheceu a mesma data.

## [0.71.1] — 2026-08-25

### Fixed

- **Feriados e interrupções ganham edição explícita, própria e independente do resto do ano letivo.** Até agora as linhas viviam sempre abertas em campos de texto dentro do formulário grande do ano, uma exceção nova caía no fundo de uma lista comprida, e o único "Guardar" — o do ano letivo — dizia, sem querer, que cada linha se gravava sozinha quando nenhuma se gravava. Cada exceção passa a ter os seus próprios três gestos: em repouso é texto, com "Editar" e "Eliminar"; a editar mostra os campos, com "Cancelar" (repõe o que estava gravado, sem pedir nada ao servidor) e "Guardar" (um pedido a sério, próprio, imediato). "Adicionar" mostra a nova linha no topo da lista, não no fundo, com foco automático na designação. Eliminar continua a pedir confirmação, com o mesmo diálogo das outras ações destrutivas da aplicação.

  **As datas de uma exceção passam a ser mais difíceis de errar.** Os campos limitam-se às datas do próprio ano letivo; a data de fim acompanha a de início enquanto forem o mesmo dia (evitando terminar por engano com um intervalo invertido) e deixa de a acompanhar assim que o professor escolhe deliberadamente um intervalo de vários dias. O servidor continua a validar tudo de novo, e as mensagens de erro passam a nomear o campo em português, nunca uma chave técnica como `exceptions.4.title`.

  Os períodos do ano não mudaram — continuam no mesmo formulário, com o mesmo "Guardar ano letivo" de sempre.

## [0.71.0] — 2026-08-25

### Added

- **O Calendário do Ano Letivo passa a deixar importar o calendário escolar publicado pela escola, em Excel.** "Importar calendário da escola" fica junto de "Novo acontecimento", na vista de Mês — sem menu novo. O ficheiro é lido em memória e esquecido: nada é escrito enquanto o professor não rever, linha a linha, o que foi encontrado e confirmar explicitamente. Cada item aceite vai para a sua fonte canónica de sempre — datas de semestre para "Estrutura do Ano Letivo", feriados e interrupções para o domínio criado nesta mesma fase, o que não é classificável com segurança para um acontecimento genérico — e nada é copiado para um sítio novo só por ter sido importado.

  **Nunca uma escolha silenciosa.** Um calendário real trouxe um caso concreto: o 2.º Semestre termina em datas diferentes consoante o ciclo (4, 11 ou 30 de junho). A aplicação não escolhe por adivinhação — mostra as três datas lado a lado e obriga a uma escolha explícita antes de a linha poder ser confirmada; deixá-la por escolher não bloqueia as restantes linhas do ficheiro.

  **Cada linha tem o seu próprio estado, nunca um ficheiro tudo-ou-nada**: novo, já existente (nada a fazer), alterado (mostra o valor atual e o do documento, lado a lado, nunca substituído em silêncio), em conflito, ou fora do ano letivo selecionado. Reimportar o mesmo ficheiro duas vezes não duplica nada — a segunda pré-visualização mostra tudo como já existente.

  **Nada do que não seja claramente reconhecível é descartado.** Um item datado que o documento não permite classificar com confiança fica proposto como acontecimento genérico, por aceitar ou rejeitar — nunca silenciosamente ignorado, e nunca forçado a uma categoria que diria algo que o documento não diz.

## [0.70.1] — 2026-08-25

### Fixed

- **A materialização de aulas passa a respeitar feriados, interrupções letivas e dias não letivos.** Até agora um `RecurringLessonSlot` gerava sempre uma `Lesson` na sua data, mesmo caindo em cima de um feriado recém-marcado. A regra vive no único sítio por onde toda a materialização passa (`MaterializeLessonsForRange`), pelo que se aplica a todos os caminhos de uma só vez — o de "Aulas e Sumários" incluído — e não distingue as três espécies de exceção: todas impedem igualmente a criação da aula. O `RecurringLessonSlot` em si nunca é tocado — a rotina sobrevive ao feriado intacta, e o dia seguinte volta a criar aula normalmente; e nenhuma `Lesson` já existente é apagada ou alterada por esta regra, mesmo que a exceção que a passou a cobrir só tenha sido criada depois.

## [0.70.0] — 2026-08-25

### Added

- **"Estrutura do Ano Letivo" passa a ter um sítio próprio para feriados, interrupções letivas e dias não letivos — e o Calendário do Ano Letivo passa a mostrá-los.** Até agora estas datas não tinham fonte canónica nenhuma na aplicação: não são uma avaliação, não são a estrutura do ano em si, e marcá-las como um acontecimento pessoal (`CalendarEvent`) confundiria «isto impede uma aula» com «isto é uma reunião às 18h», que nunca impede nada. Passam a ter modelo próprio (`AcademicCalendarException`), editado exactamente onde os semestres já são editados — no mesmo formulário, pela mesma pessoa, sob a mesma política — e nunca num menu novo.

  **Três espécies, e só três: feriado, interrupção letiva, dia não letivo.** Um dia só é `starts_on === ends_on`, a mesma convenção que os acontecimentos já usam; uma interrupção de vários dias é um intervalo, não vários registos soltos.

  **É estrutura do ano, e não de um professor.** Ao contrário de um acontecimento (`user_id`, pessoal), uma exceção letiva é da organização inteira — tal como um semestre — e por isso não tem dono: quem pode alterar os períodos do ano pode alterar isto, e mais ninguém.

  **No Calendário, nunca se confunde com um período nem com um acontecimento.** Um dia não letivo ganha um tom cinzento tracejado — deliberadamente fora da paleta de cor dos semestres — e ícone e etiqueta próprios («FERIADO», «INTERRUPÇÃO», «NÃO LETIVO»); uma interrupção de onze dias é dita uma vez só, onde começa, e não onze vezes repetida. Quando um dia é simultaneamente de um período e de uma exceção — 21 de dezembro é 1.º Semestre e é also Interrupção de Natal — o facto operacionalmente relevante desse dia é que não há aula, e é essa a cor que a célula mostra; o período continua dito, com todas as letras, na faixa acima da grelha.

- **Os tons dos semestres no Calendário passam a ser um por período, e não um só para todos.** Setembro e março deixavam de se distinguir um do outro por cor; cada período (pela sua própria `sequence`, nunca pela ordem em que calhou vir numa lista) passa a ter o seu próprio tom muito pálido — três ao todo, para um ano de trimestres não ficar sem cor no terceiro — reutilizado de forma idêntica na faixa do mês, na grelha por dia e na vista de Ano, para as três nunca poderem descrever o mesmo dia de maneiras diferentes.

### Fixed

- Corrigida uma instabilidade pré-existente e documentada em `AcademicYearFactory`: o rótulo aleatório de um ano letivo gerado por fábrica podia colidir com o literal `"2026/2027"` escrito à mão em dezenas de testes, porque `unique()` só evita colisões fábrica-contra-fábrica e nunca fábrica-contra-literal. O intervalo de anos gerados passou de 2020–2070 para 2040–2090, deixando de poder colidir com qualquer ano escrito à mão neste projeto.

## [0.69.6] — 2026-08-25

### Fixed

- **As turmas do "Horário do Professor" deixam de poder partilhar tom por acaso.** O tom saía de uma dispersão do `ulid` de cada turma sozinho — estável, mas cego às outras turmas da página — e com cinco turmas visíveis chegou a acontecer duas ficarem com exactamente a mesma cor, com quatro tons por usar ao lado. A atribuição passa a olhar para o conjunto inteiro de turmas visíveis de uma vez: enquanto couberem na paleta de seis tons, cada uma leva um tom só seu; só a partir da sétima é que os tons voltam a repetir-se. Continua determinística e independente da ordem em que os blocos aparecem — o mesmo conjunto de turmas dá sempre o mesmo mapa de cores.

## [0.69.5] — 2026-08-25

### Fixed

- **As turmas do "Horário do Professor" ganham um sinal visual discreto, para se distinguirem mais depressa ao correr a semana com os olhos.** Cada bloco de aula passa a ter uma barra fina na margem esquerda e uma pequena cápsula em torno do nome da turma, ambas num tom pastel de baixa saturação — nunca cores fortes, nunca uma turma por cor viva. O tom sai do identificador estável da turma (`ulid`), nunca da posição do bloco na lista: a mesma turma tem sempre o mesmo tom, em qualquer dia da semana, em qualquer semana, e nas três leituras da página (dias úteis, fim de semana, agenda de ecrã estreito). A hora, a disciplina e o fundo do bloco continuam tão neutros como sempre; "Sem aulas" continua sem qualquer acento; o nome da turma continua escrito em texto — a cor é reforço, nunca o único identificador.

## [0.69.4] — 2026-08-25

### Fixed

- **O tom estrutural do Calendário deixa de parecer cinzento e passa a bege de papel quente.** O `stone` da ronda anterior cumpria "não mandar na página", mas deixava de se ver — lia-se como um cinzento sujo, quase invisível. Passa a `amber-50` (papel quente, não o âmbar saturado da "Visita de estudo": aquele é moldura e texto de peso 600/700, este é só um enchimento pálido de peso 50, com moldura neutra). Os dois semestres de um ano continuam a partilhar o mesmo tom — nunca uma cor por período — e distinguem-se sempre pelo nome e pelas datas.

- **A faixa de período da vista Mês deixa de ser uma pastilha solta e passa a uma faixa estrutural a sério.** Uma tira baixa e larga, do tamanho da grelha logo por baixo, com o novo bege — em vez das pequenas pastilhas que antes flutuavam soltas e eram fáceis de não ver. Quando dois períodos tocam o mesmo mês, ambos aparecem dentro da mesma faixa, separados por um traço vertical desenhado (mais pesado do que o "·" que já separava o nome de um período das suas próprias datas).

- **A célula do dia deixa de repetir o nome do período que a faixa já diz.** O dia em que um semestre começa mostrava "1.º Semestre" também dentro da própria célula — a um passo de uma faixa que já diz "1.º Semestre · desde 11/09" com muito mais peso, sobre a grelha inteira. Essa repetição foi removida; a grelha do mês fica só com os dias, as avaliações e os acontecimentos.

## [0.69.3] — 2026-08-25

### Fixed

- **O mês que se está a ver passa a ser a coisa mais forte do cabeçalho do Calendário.** "Outubro de 2026" vivia a meio de uma linha esbatida — "Outubro de 2026 · 2026/2027 · 3 avaliações · 4 acontecimentos" — e a primeira pergunta de quem abre um calendário ("em que mês estou?") era a mais difícil de responder da página. O mês passa a título grande do cabeçalho, com a navegação entre meses agrupada ao seu lado numa moldura própria — visualmente distinta do seletor Mês/Ano, que é outra pergunta — e o resto do contexto (ano letivo, contagens) fica pequeno e esbatido, a seguir. `?month=`, navegação anterior/seguinte/início do ano e o regresso ao mesmo mês a partir do Ano ou de uma avaliação continuam exatamente como estavam.

- **A faixa estrutural da vista Mês deixa de ficar presa ao primeiro período do ano.** Mostrava sempre o intervalo completo do período que tocasse a grelha, incluindo os dias de preenchimento dos meses vizinhos — o que nunca chegou a mostrar o semestre errado, mas também nunca dizia a verdade sobre o mês em si: Setembro mostrava "1.º Semestre · 11/09 – 29/01" como se o mês inteiro lhe pertencesse, quando o semestre só abre a 11. A faixa passa a responder pelos limites reais do mês visível — e não da grelha — com a mesma frase "desde"/"até" que a vista Ano já usava para o mesmo problema, através de uma única função partilhada entre as duas vistas (`calendar.ts`), para nunca mais poderem descrever o mesmo mês de duas maneiras diferentes. Dois períodos a tocar o mesmo mês aparecem os dois; um mês que nenhum período toca não mostra faixa nenhuma.

- **O fundo azul que cobria a grelha do mês e os cartões da vista Ano dá lugar a um tom bege muito discreto, e um só.** Cada célula do dia levava um fundo cheio com a cor do período em que caía — um arco-íris de seis cores repartidas por índice — e o mês inteiro ficava pintado só por se estar a meio de um semestre: a estrutura do ano a mandar na página em vez de a servir de contexto. A grelha do mês fica neutra (o período continua nomeado por texto onde começa); os cartões da vista Ano só recebem tom quando um período os cobre de uma ponta à outra, e nunca uma cor por período — um só tom `stone`, discreto e de baixa saturação, propositadamente longe do âmbar já usado pela "Visita de estudo" e pelo acento da marca. A identidade do período continua a nunca depender só da cor: o nome, e agora o "desde"/"até", estão sempre escritos ao lado.

## [0.69.2] — 2026-08-25

### Fixed

- **Dez pontos de polimento no "Calendário do Ano Letivo" e no elemento de avaliação, encontrados na validação manual real das Fases 5.1–5.3.** Nenhum toca na arquitetura, nos dados ou no comportamento já aprovados — só na forma como são apresentados.

  **Um acontecimento com hora passa a mostrar essa hora no cartão do mês** — "REUNIÃO · 16:30" em vez de só "REUNIÃO" — e não só no painel de detalhe. Um acontecimento de dia inteiro continua sem hora nenhuma, verdadeira ou inventada.

  **Eliminar um acontecimento deixa de abrir a caixa nativa do navegador** ("lapis.test diz…") **e passa a perguntar dentro do próprio painel**, com o mesmo desenho das outras confirmações destrutivas da aplicação: "Eliminar acontecimento? «Reunião de Departamento» será eliminada. As avaliações e a estrutura do ano letivo não serão afetadas." — Cancelar volta ao formulário sem pedir nada ao servidor; só Eliminar o faz.

  **A faixa de um período deixa de repetir a sua própria espécie** — "1.º Semestre · Semestre · 11/09 – 29/01" passa a "1.º Semestre · 11/09 – 29/01" — nas vistas de Mês e de Ano.

  **A vista de Ano deixa de chamar "períodos" a um ano de semestres.** O resumo passa a nomear a estrutura real do ano — "2 semestres", "3 períodos", "1 trimestre" — lida do tipo canónico de cada período (`AcademicPeriodKind`) e não adivinhada; um ano com tipos misturados usa o nome neutro "período", nunca inventado.

  **Um mês deixa de ser pintado inteiro com a cor de um período que só o toca em parte.** Setembro, quando um semestre só começa a 11, já não aparece com a cor desse semestre do dia 1 ao 30 — só um mês inteiramente coberto por um único período recebe a sua cor; um mês de transição fica neutro e diz por escrito onde o período realmente começa ou acaba ("1.º Semestre desde 11/09", "1.º Semestre até 29/01"), e um intervalo sem período nenhum deixa de ser atribuído a um vizinho por engano.

  **Abrir uma avaliação a partir do Calendário passa a oferecer "← Voltar ao Calendário", para o mesmo mês de onde se partiu** — em vez de sempre "← Voltar à turma" — pelo mesmo mecanismo de endereço (`?from=…`) que "Avaliações" já usa, sem qualquer novo destino aceite do lado do servidor.

  **A data de uma avaliação passa a aparecer em português — "13/09/2026"** — em vez do formato técnico "2026-09-13", nos dois sítios da página em que aparecia.

  **A descrição do formulário de acontecimentos deixa de prometer uma regra de privacidade em texto de utilizador**, e passa a dizer simplesmente o que se pode lá registar.

  **O formulário de acontecimentos ganha um "Cancelar" explícito**, ao lado do botão principal, em criação e em edição — o X do painel continua a funcionar exactamente como antes.

  **Os tracinhos junto de "3 avaliações", na vista de Ano, foram retirados**: repetiam em desenho, com um tecto de seis, o número que já estava escrito ao lado — não distinguiam período nem espécie, e não diziam nada que o número não dissesse melhor.

  A checklist de turmas do formulário de acontecimentos mantém-se como está: não existe no projeto nenhum componente de seleção múltipla pesquisável para reutilizar, e construir um de propósito para este polimento ficaria fora do âmbito — regista-se como dívida de UX para quando o número de turmas por professor o justificar.

## [0.69.1] — 2026-08-25

### Fixed

- **"Horário do Professor" deixa de deslocar a sexta-feira para uma linha à parte só porque a quinta está vazia.** A grelha desta página, em ecrã largo, distribuía apenas os dias com aulas por um número de colunas que acompanhava a largura do ecrã — e num professor com aulas a segunda, terça, quarta e sexta (sem quinta), a sexta-feira caía na linha seguinte assim que a grelha só tinha espaço para três colunas na mesma linha, quebrando o modelo mental de uma semana lida de uma vez.

  **A semana passa a ser sempre cinco colunas fixas — segunda a sexta —, por esta ordem e nesta posição.** Um dia sem aulas mostra "Sem aulas" em vez de desaparecer, e nada é criado por trás desse rótulo: continua a ser uma página que só lê, tal como já era. Sábado e domingo, quando têm mesmo aulas, aparecem numa linha à parte por baixo — não têm coluna garantida, porque a teriam de ter vazia em todas as semanas de todos os professores para servir os poucos que lá lecionam. Em ecrã estreito mantém-se a leitura anterior: só os dias que têm mesmo aulas, em lista.

## [0.69.0] — 2026-08-25

### Added

- **O "Calendário do Ano Letivo" passa a deixar marcar os acontecimentos que não têm sítio nenhum onde viver.** Até agora o calendário mostrava a estrutura do ano e as avaliações — duas coisas que já existiam noutras páginas e que ele apenas juntava — e não havia forma de lá pôr uma reunião de conselho de turma, a semana da leitura ou uma visita de estudo. Não havia porque essas coisas não existiam em lado nenhum da aplicação: não são um elemento de avaliação, não são um período do ano letivo e não são uma aula. Passam a existir, e a nascer no único sítio onde fazem sentido, que é o próprio calendário.

  **Quatro espécies, e só quatro: reunião, atividade, visita de estudo e "outro".** O conjunto é fechado de propósito. Cada uma destas quatro é uma coisa marcada numa data que não tem casa noutro sítio; o que já tem casa — uma avaliação, um período, uma aula — continua a ser criado e alterado na sua própria página, e não é duplicado aqui. Um tipo fora destes quatro é recusado, e não aceite "por agora".

  **Cada acontecimento pode ter uma data só ou várias, uma hora marcada ou o dia inteiro, e nenhuma, uma ou várias turmas.** A data de fim é opcional: em branco, o acontecimento fica no mesmo dia. Sem hora nenhuma, é um dia inteiro — a ausência de hora é informação, e não uma hora que ficou por escrever. Um acontecimento de vários dias aparece em cada um dos dias que atravessa, e não só no primeiro. As turmas que se podem associar são as que o próprio professor leciona, pela mesma regra que as Turmas e o "Horário do Professor" já usam: a turma de um colega é recusada com uma mensagem, e nunca aceite em silêncio e deixada de fora sem se dizer nada.

  **Cria-se por um botão que está sempre à vista.** "Novo acontecimento" está na vista de Mês, fora da grelha, e funciona sozinho — não é preciso descobrir que se pode carregar num dia. Carregar no número de um dia é um atalho por cima disso, que abre o mesmo formulário já com aquela data. Cada acontecimento marcado no calendário abre, ao ser carregado, o mesmo formulário preenchido, com "Guardar alterações" e "Eliminar"; eliminar pede sempre confirmação primeiro.

  **Um acontecimento é pessoal de quem o criou.** Só o professor que o marcou o vê, e só ele o altera ou elimina — o de um colega não é apenas não-editável: não aparece de todo. Não é um calendário institucional partilhado, e não é um esquecimento: é o que esta primeira versão faz, pela mesma regra que as sequências de aulas já seguem.

  **Eliminar um acontecimento elimina um acontecimento, e mais nada.** As avaliações, a estrutura do ano letivo e o horário têm ciclos de vida inteiramente próprios e não são tocados — a única outra coisa que desaparece são as ligações às turmas daquele acontecimento, que sem ele não querem dizer nada. As turmas, essas, ficam. Isto está coberto por teste que conta os elementos de avaliação, os períodos, as aulas, as aulas recorrentes e as turmas antes e depois da eliminação: agora que estas coisas se veem todas na mesma página, é precisamente aí que seria fácil passar a tratá-las como se fossem a mesma coisa.

  **Uma avaliação continua a ser o mais forte da página, e a diferença nunca é só de cor.** A escada é deliberada: uma avaliação tem o tratamento mais carregado; uma reunião fica num peso intermédio; uma atividade e uma visita de estudo leem-se bem sem competir com as avaliações; e o "outro" é o mais discreto de todos. Nenhuma das quatro se distingue das outras — nem de uma avaliação — apenas pela cor: cada uma traz sempre o seu ícone e a sua etiqueta escrita ("REUNIÃO", "ATIVIDADE", "VISITA", "OUTRO"), pelo que a página continua legível num ecrã monocromático, num ecrã de fraco contraste e para quem não distingue as cores. As faixas dos períodos continuam a ser estrutura — sem moldura, sem ícone, sem peso — exatamente como estavam. Não há aqui escolha de cores nem personalização, e isso não é um esquecimento: uma cor à escolha de cada um tornaria impossível garantir justamente isto.

  **Num dia cheio, o limite passou a contar as duas coisas juntas.** Um dia com duas avaliações e três acontecimentos está exatamente tão cheio como um dia com cinco avaliações: a célula mostra os três primeiros itens e um "+N mais" que abre o resto — o mesmo mecanismo de sempre, agora a contar tudo, e não um segundo mecanismo ao lado do primeiro. Em ecrã estreito, a agenda do mês lista também os acontecimentos, com a hora ou "dia inteiro" e as turmas associadas.

  **A vista de Ano conta-os, e continua a não os enumerar.** Cada mês mostra quantos acontecimentos o atravessam, ao lado da contagem de avaliações que já mostrava e distinguida dela por ícone e por palavra. É uma vista sinóptica, e lê-los um a um é o que a vista de Mês faz, a um clique de cada mês. Um acontecimento que atravessa a fronteira entre dois meses conta nos dois, porque é uma coisa que acontece nos dois.

  **Abrir o calendário continua a não escrever absolutamente nada.** O calendário passou a ter tabela própria, e por isso passou a ser possível escrever nela sem querer: um acontecimento só nasce de um pedido explícito do professor, nunca de se olhar para a página, e o teste que já contava as aulas, as aulas recorrentes e os elementos de avaliação antes e depois de cada pedido passou a contar também os acontecimentos. Uma sessão de suporte pode ver o calendário — olhar não muda nada —, mas não pode criar, alterar nem eliminar um acontecimento em nome de quem está a ser ajudado.

## [0.68.0] — 2026-08-25

### Added

- **"Calendário do Ano Letivo" deixa de ser um marcador e passa a ser uma página a sério, com vista de Mês e vista de Ano.** Até agora a entrada existia no menu mas não levava a lado nenhum: a estrutura do ano — os períodos e os semestres — só se via na página onde é definida, e as avaliações marcadas só se viam numa lista ordenada por data. Faltava o sítio onde as duas coisas se leem juntas, que é como um professor pensa o ano: "em que período estou, e o que está marcado à minha frente".

  **A vista de Mês** mostra o mês inteiro em grelha, de segunda a domingo, com os dias dos meses vizinhos que completam a primeira e a última semana. Cada dia mostra o período do ano letivo em que cai — como faixa discreta, com o nome escrito onde a faixa começa e onde muda, e não repetido em trinta células — e as avaliações marcadas nesse dia, cada uma com o título, a turma e o tipo, e cada uma a ligar à sua própria página. Num dia com muitas avaliações a célula não cresce sem fim: mostra as três primeiras e um "+N mais" que abre o resto e volta a fechar. Navega-se para o mês anterior e para o seguinte, e há sempre a forma de voltar ao ponto de partida. Em ecrã estreito a grelha dá lugar a uma agenda do mês, dia a dia, como a vista semanal de "Aulas e Sumários" já resolve o mesmo problema.

  **A vista de Ano** mostra o ano letivo todo de uma vez: os períodos como faixas, com o seu tipo, as suas datas e quantas avaliações caem em cada um, e os meses do ano com a contagem de avaliações de cada um. É uma vista sinóptica e por isso não enumera as avaliações uma a uma — essa lista já existe em "Elementos de Avaliação", e cem linhas aqui enterrariam justamente aquilo que só esta vista mostra, que é a forma do ano. Cada mês liga à sua própria vista de Mês. As duas vistas são dois endereços, e não dois estados de uma só página: cada uma pode ser guardada nos favoritos e o botão de voltar do navegador funciona entre elas.

  **Uma avaliação nunca se confunde com a estrutura do ano, e a diferença não é só de cor.** Uma faixa de período não tem moldura nem ícone e escreve-se em texto discreto; uma avaliação tem moldura, ícone e peso de texto. A distinção mantém-se num ecrã monocromático e para quem não distingue as cores — e é uma só, deliberada, igual nas duas vistas, sem qualquer opção de personalização.

  **O mês em que o calendário abre segue uma regra simples.** Se hoje cai dentro do ano letivo selecionado, abre no mês de hoje; se não cai — um ano já terminado, ou ainda por começar — abre no primeiro mês desse ano, em vez de mostrar um mês de hoje corretamente vazio.

  **Nada é criado nem alterado por se olhar para o calendário.** Ao contrário da vista semanal de "Aulas e Sumários", que materializa deliberadamente as aulas da semana que mostra, aqui abrir a página, mudar de mês ou atualizar não escreve absolutamente nada — nem uma aula, nem uma aula recorrente, nem um elemento de avaliação — e isso está coberto por teste que conta as três tabelas antes e depois de cada pedido. O calendário não tem tabela própria: lê os períodos onde eles já vivem e as avaliações pela data em que já estão marcadas, sem duplicar nem uma coisa nem outra. Só aparecem as avaliações das turmas que o próprio professor leciona, pela mesma regra que as Turmas e o "Horário do Professor" já usam.

  **As aulas não aparecem aqui, de propósito.** Essa pergunta — "que aulas tenho, quando e onde" — é a do "Horário do Professor", que a responde desde a versão anterior. O calendário responde a outra: "o que é relevante no meu ano". Mostrar as aulas nos dois sítios faria do calendário uma cópia do horário, e por isso não há aqui nem uma aula, nem uma contagem de aulas, nem um indicador de carga. Também não há feriados, interrupções nem reuniões: esses dados ainda não existem na aplicação, e inventar espaço vazio para eles seria prometer o que não está feito.

  **Um ano sem períodos ou sem avaliações continua a dar um calendário correto**, com os seus dias reais e uma indicação de onde se criam os períodos — nunca uma página em branco, e nunca conteúdo inventado para a encher. Uma organização acabada de criar, ainda sem ano letivo nenhum, também não vê um erro: vê o que lhe falta fazer.

### Changed

- **A entrada "Calendário do Ano Letivo" no menu passa a levar à página real.** Era o último marcador do grupo "Organização do Ano Letivo" e apontava para a página "em construção"; passa a apontar para a vista de Mês, no mesmo endereço em que o marcador respondia — quem tenha guardado essa morada nos favoritos continua a chegar ao sítio certo, agora com a página verdadeira. A chave, o módulo, a legenda, a descrição e a posição da entrada ficam exatamente como estavam, e nada muda quanto a quem a vê: a capacidade comercial que a protege existia desde muito antes de haver página por trás dela. "Estrutura do Ano Letivo" e "Horário do Professor" ficaram intocados no mesmo grupo.

## [0.67.0] — 2026-08-25

### Added

- **"Horário do Professor" passa a ser um sítio a sério, e não um botão escondido dentro das Turmas.** Até agora o horário de um professor só existia turma a turma: para saber como era a sua semana inteira era preciso abrir as turmas uma a uma e juntar os blocos de cabeça. Passa a haver uma página própria — "Organização do Ano Letivo → Horário do Professor" — que mostra de uma vez todas as aulas recorrentes de todas as turmas que leciona, agrupadas por dia da semana, com hora de início e de fim, turma e disciplina. Em ecrã largo lê-se como uma semana, uma coluna por dia; em ecrã estreito as colunas empilham-se numa lista agrupada por dia, como a vista semanal de "Aulas e Sumários" já resolve o mesmo problema. Só aparecem os dias em que há mesmo aulas: um sábado vazio não é informação. Cada bloco liga à página da sua turma, no sítio onde esse horário se edita.

  **Os dois caminhos de configuração passam a estar onde o horário se lê.** A mesma página oferece, por baixo da semana, as duas formas que já existiam de preencher um horário: importar o PDF do horário exportado pela escola, e configurar à mão, turma a turma. Nenhuma das duas mudou — são as mesmas rotas, o mesmo importador e o mesmo editor de aulas recorrentes de sempre. Quem ainda não tem horário nenhum não vê uma grelha vazia nem um erro: vê uma explicação clara de que pode importar o PDF ou definir os blocos à mão, com os dois caminhos ali mesmo.

  **A página não escreve absolutamente nada.** Ao contrário da vista semanal de "Aulas e Sumários", que cria deliberadamente as aulas da semana que mostra, um horário é a regra e não as suas ocorrências: abrir esta página não cria nem uma aula recorrente nem uma aula, e isso está coberto por teste que conta as duas tabelas antes e depois. O mecanismo de horários, o importador de PDF e o editor manual ficaram byte a byte como estavam. As Turmas mantêm o seu próprio atalho "Configurar horários", por decisão de produto: quem está dentro de uma turma continua a configurar o horário dali.

### Changed

- **O menu ganha "Organização do Ano Letivo", onde o ano letivo passa a estar todo junto.** O grupo "Organização do ano" passa a chamar-se "Organização do Ano Letivo" e reúne, por esta ordem, o "Calendário do Ano Letivo", a "Estrutura do Ano Letivo" e o "Horário do Professor" — o calendário do ano, a sua estrutura, e a semana do professor dentro dele. "Aulas e Sumários" mantém-se no mesmo grupo, por baixo.

  **"Estrutura do Ano Letivo" mudou de grupo e mais nada.** Vinha de "Configuração", onde estava apenas porque esse grupo existiu primeiro: definir anos letivos, disciplinas e períodos é organizar o ano. A rota (`academic-years.index`), o controlador, as políticas e a disponibilidade em todos os planos ficam exatamente como estavam — um professor do plano Base alcança as mesmas entradas, pela mesma ordem, debaixo de um título diferente. Como esta entrada está disponível em qualquer plano, o grupo deixa de desaparecer para quem não tem o plano Pro: passa a mostrar-se com as entradas a que esse plano tem direito.

  **"Agenda do Ano Letivo" passa a ler-se "Calendário do Ano Letivo", e continua a ser um marcador.** Só a legenda mudou; a chave, o módulo e a capacidade comercial mantêm-se intocados, e nenhuma página foi inventada por trás dela — a página real é construída numa fase seguinte.

## [0.66.9] — 2026-08-25

### Fixed

- **A pontuação já não fica cortada na caixa da grelha de correção.** Um valor escrito à mão como "10,25" cabia mal na caixa anterior (64px, a maior parte tomada pelo espaço reservado às setas) e, alinhado à direita, o excesso ficava simplesmente invisível em vez de forçar a caixa a crescer — o professor via "10,2" mesmo tendo escrito "10,25". A caixa passa de 64 para 80px; as setas continuam a saltar de 0,5 em 0,5, a escrita manual continua livre, e o alinhamento à direita das setas mantém-se exatamente como ficou na correção anterior.

## [0.66.8] — 2026-08-25

### Fixed

- **As setas de cotação, na grelha de correção, passam a avançar de 0,5 em 0,5.** O intervalo herdado da criação do elemento de avaliação era de um quarto de ponto (0,25) — coerente ali, onde as cotações se distribuem por questões, mas estranho ao dar uma nota, onde a prática habitual são meios pontos. As setas passam a percorrer 0 → 0,5 → 1 → 1,5 → ..., sempre travadas na cotação máxima da questão. Escrever a nota diretamente no campo continua a aceitar qualquer valor, incluindo frações menores que 0,5 — só o comportamento das setas mudou.

## [0.66.7] — 2026-08-24

### Fixed

- **As setinhas das cotações, na grelha de correção, deixam de parecer que saltam de sítio.** A caixa de cotação de cada questão tem largura fixa e as setas de incremento do navegador estão encostadas à sua margem direita interior — mas o número estava centrado. Bastava a cotação passar de "5" para "12.5" para os dígitos deslizarem para a esquerda e para a direita dentro da caixa, enquanto as setas ficavam paradas: lido de relance numa grelha inteira, é a posição relativa que muda, e o efeito é o de setas que dançam de célula para célula. O número passa a ser alinhado à direita, com espaço reservado para as setas não ficarem por baixo dos dígitos: o último algarismo fica sempre no mesmo sítio, seja qual for a cotação, e a relação entre número e setas deixa de variar.

  **As setas nativas mantêm-se de propósito.** O pedido era uma posição estável, não a sua remoção — e a grelha já responde às teclas de seta para incrementar, pelo que continuam a ser um recurso a sério e não decoração. A largura da caixa não mudou, e as aparências condicionais — a moldura vermelha de cotação acima do máximo, e o estado desativado — continuam a compor-se corretamente com o novo alinhamento.

### Fixed

- **"Criação simples" deixa de ficar inacessível num elemento de avaliação acabado de abrir.** O relato existia desde a 0.62.1, onde uma auditoria não o conseguiu reproduzir e o deu como não confirmado. Reproduz-se, e a receita é mais simples do que a que então se tentou: abrir um novo elemento de avaliação, **não** marcar nenhum domínio, carregar em "Criação avançada" e tentar voltar logo a seguir a "Criação simples" — sem ter tocado em mais nada. O botão de regresso está desativado. Foi por isso que os testes de regressão de então não apanharam nada: todos marcavam um domínio antes de mudar de modo, e essa marcação preenche os domínios de cada questão pelo caminho, contornando sem querer exatamente a condição que falha na prática.

  **A causa: a verificação de regresso misturava duas perguntas diferentes.** Uma é "a estrutura ainda cabe no formulário curto?" — um único grupo sem nome, questões Q1, Q2, Q3… por ordem, sem títulos próprios e sem bónus. Essa é a pergunta certa, porque é ela que diz se voltar atrás esconderia algo que o professor construiu no modo avançado. A outra é "o formulário já está completo?" — nomeadamente, já foi escolhido algum domínio. Essa é uma questão do momento de submeter, não do momento de mudar de modo; e como um elemento novo começa **sempre** sem domínios, a segunda pergunta reprovava logo à partida um formulário onde nada tinha ainda sido construído. As duas condições relativas a domínios saíram da verificação de modo; todas as verificações estruturais ficaram exatamente como estavam.

  **Nada passou a poder ser submetido incompleto.** A exigência de pelo menos um domínio por questão continua onde sempre esteve e não depende desta verificação: no cliente, o botão "Preparar grelha de correção" continua desativado enquanto faltar um domínio; no servidor, `InstrumentRequest` continua a exigir `min:1` domínios por questão numa submissão real. Só o movimento entre os dois modos, com o formulário ainda por completar, deixou de estar bloqueado. Uma estrutura genuinamente incompatível — um segundo grupo, uma questão com título próprio — continua a impedir o regresso, com a mesma mensagem e sem perder dados, agora coberta por testes nos dois sentidos.

### Fixed

- **As datas em português deixam de aparecer com Maiúsculas A Meio Da Frase.** "Segunda-Feira, 14 De Setembro De 2026" não é português: escreve-se "Segunda-feira, 14 de setembro de 2026", com maiúscula apenas na primeira letra da frase. O texto produzido pela aplicação já estava correto — `Intl.DateTimeFormat('pt-PT', …)` devolve tudo em minúsculas, como deve ser; quem o estragava era a classe CSS `capitalize` aplicada por cima, que põe em maiúscula a inicial de **cada palavra**, incluindo as duas ocorrências de "de" e a metade do dia da semana a seguir ao hífen.

  A classe foi retirada da vista semanal e da página de uma aula, e a primeira letra passa a ser posta em maiúscula sobre o próprio texto, através de um utilitário partilhado (`capitalizeFirst`) em vez de a lógica ser repetida em cada sítio. O editor de aulas recorrentes tinha exatamente o mesmo defeito — dias da semana escritos à mão em minúsculas, apresentados com a mesma classe, tanto na lista de horários como no seletor de dia — e foi corrigido ao mesmo tempo, por ser o mesmo problema mesmo ao lado. Nenhum formato de data mudou: só a forma como é apresentado.

### Fixed

- **Sair de uma aula com o sumário por guardar passa a avisar, em vez de perder o texto em silêncio.** Nada nesta página se guarda sozinho: um sumário escrito a meio, umas notas do professor, um TPC — fechar o separador, atualizar a página ou carregar em qualquer ligação fazia desaparecer tudo sem uma palavra. Passa a haver aviso nos dois caminhos por onde se sai: o do navegador (fechar, atualizar, sair da aplicação) e o de dentro da própria aplicação, que nunca chega a passar pelo primeiro — carregar em "Voltar às aulas da semana", ou em qualquer outra ligação, pede confirmação antes de deixar o texto para trás, com o mesmo `confirm()` do navegador que o resto da aplicação já usa nas suas confirmações.

  **O aviso só aparece quando há mesmo algo por guardar.** É o próprio estado do formulário (`isDirty`) que decide — comparação com os valores com que a página abriu, e não uma bandeira própria a ser mantida à mão. Abrir uma aula e sair sem tocar em nada não pergunta nada. "Basear no sumário anterior" continua a ser só texto colocado no formulário, por guardar, e mantém-se exatamente como estava.

  **Guardar não é sair.** As submissões da própria página — "Guardar" e "Marcar como lecionada" — estão explicitamente isentas: seria absurdo interrogar o professor precisamente sobre o pedido que grava o trabalho. Depois de uma gravação bem sucedida o formulário volta a estar limpo por si só, pelo que o aviso não volta a aparecer logo a seguir a guardar; esse comportamento fica coberto por teste contra a biblioteca real, e não contra uma imitação dela, porque é dele que o aviso inteiro depende. Ambos os ouvintes são removidos quando se sai da página.

### Fixed

- **A ligação de saída de uma aula volta à semana dessa aula, não à página da turma.** Quem entra numa aula vem quase sempre da vista semanal e quer voltar exatamente para lá — continuar a preencher a aula seguinte, ver o que falta na semana. O botão "Voltar à turma" levava à página da turma, um sítio diferente daquele de onde se veio, obrigando a navegar de novo até à semana certa. Passa a ser "Voltar às aulas da semana" e aponta para `/lessons?week=` da segunda-feira da semana da própria aula.

  **A semana é calculada a partir da data da aula, não do caminho percorrido.** Nenhum parâmetro é passado a partir da vista semanal: essa solução só funcionaria para quem chegasse por aí, e cairia silenciosamente numa semana errada para quem entrasse por uma ligação direta, pelo histórico do navegador ou por qualquer outro caminho. A data da aula já está na página, por isso é dela que a semana sai — a segunda-feira da semana ISO correspondente, no calendário de Lisboa e não no de UTC, para que uma aula ao fim da tarde de domingo não seja empurrada para a semana seguinte. O formato produzido é exatamente o `YYYY-MM-DD` que a vista semanal já lê hoje.

### Fixed

- **Abrir a semana passa a mostrar logo as aulas do horário, sem "Preparar aulas desta semana" primeiro.** Um professor com o horário recorrente inteiramente configurado abria "Aulas e Sumários" e via uma semana vazia, com uma mensagem a dizer-lhe que carregasse num botão — o horário existia, as aulas é que ainda não tinham sido criadas. A vista semanal lia apenas aulas já materializadas e nunca olhava para as aulas recorrentes; só o botão manual as criava. Abrir uma semana passa agora a criar as aulas dessa semana automaticamente, antes de a lista ser lida, com a mesma ação idempotente de sempre (`MaterializeLessonsForWeek`) — as aulas que aparecem continuam a ser registos reais de aula, exatamente como já eram, e não uma pré-visualização por gravar.

  **A escrita está estritamente limitada à semana que está a ser vista.** Nunca ao ano letivo inteiro, nunca a uma semana vizinha: abrir a semana de 7 de setembro cria as aulas dessa semana e mais nenhuma, e só ao navegar para a semana seguinte é que as aulas dessa semana passam a existir. Como a criação assenta num `firstOrCreate` sobre um índice único real, reabrir a mesma semana as vezes que forem precisas não duplica nada nem toca no que já lá está — um sumário já escrito, uma aula já marcada como lecionada. Uma sessão de suporte (impersonação) continua a não escrever nada em nome do professor: vê a semana, mas não cria aulas.

  **Isto inverte deliberadamente um princípio anterior desta base de código** — "ler o horário nunca materializa aulas" — que estava explicitamente coberto por um teste. A decisão de produto mudou: a preparação da semana deixa de ser um passo burocrático que o professor tem de se lembrar de fazer. O teste foi reescrito para provar o comportamento novo (ler cria, limitado à semana, idempotente) em vez de ser apagado.

  **O botão manual não desaparece, passa a secundário.** Deixa de ser o passo obrigatório para as aulas existirem e passa a "Atualizar aulas desta semana", com peso visual menor: serve para quem acabou de editar o horário recorrente a meio da semana e quer vê-lo refletido de imediato, sem esperar por um novo carregamento da página. Continua a ser a mesma ação idempotente, pelo que não fazer nada é um resultado legítimo. O estado vazio deixa de dizer "carrega no botão" e passa a descrever o que de facto se passa: a semana não tem aulas no horário recorrente das turmas, ou ainda não há horário configurado.

### Fixed

- **"Importar horário" passa a "Configurar horários" — um único ponto de entrada para as duas formas de preencher o horário de uma turma.** O botão que a versão anterior deixou em Turmas só entrava na importação de PDF, dando a entender que essa seria a única forma de configurar aulas recorrentes — a edição manual, já existente na página de cada turma, continuava sem qualquer ligação direta a partir de Turmas. O botão passa agora a abrir um pequeno passo intermédio, "Configurar horários", com as duas opções lado a lado: "Importar PDF", que continua a levar exatamente à mesma importação de sempre, e "Configurar manualmente", com a lista das turmas do professor e uma ligação direta à secção de horário de cada uma. Nem o mecanismo de importação (leitura do PDF, correspondência de turmas, deteção de conflitos, confirmação) nem o editor manual de aulas recorrentes foram tocados — ambos continuam byte a byte os mesmos; só a forma de lá chegar mudou. Um horário importado continua totalmente editável à mão depois, exatamente como já acontecia.

## [0.66.0] — 2026-08-24

### Added

- **Importar o horário do professor a partir do PDF exportado pela escola.** Configurar à mão, bloco a bloco, as aulas recorrentes de meia dúzia de turmas é meia hora de trabalho no início de cada ano letivo — e o horário já existe, em PDF, exportado pelo sistema da escola (INOVAR). Turmas ganha agora um "Importar horário": carrega-se o ficheiro, o LÁPIS lê a grelha e mostra, turma a turma, exatamente que aulas recorrentes seriam criadas; só depois de confirmar é que alguma coisa é escrita. Cada bloco criado é uma aula recorrente igual a qualquer outra — editável e removível no mesmo sítio de sempre, na página da turma.

  **A grelha é lida pela posição de cada célula na página, nunca pela ordem do texto.** Num horário real, um dia sem aulas não deixa qualquer rasto no texto do ficheiro: uma linha com aula à 2ª e à 4ª feira lê-se, em texto corrido, exatamente como uma linha com aula à 2ª e à 3ª. Contar separadores poria metade do horário no dia errado — com toda a confiança e sem qualquer aviso. Por isso cada célula é colocada na coluna onde está realmente desenhada; um bloco cujo texto passa para uma segunda linha é reconstruído inteiro; e um dia da semana que não apareça em lado nenhum do ficheiro (um professor sem aulas à 5ª feira) continua a não deslocar os restantes. Dois blocos seguidos da mesma turma — 08:30–09:20 e 09:20–10:10 — continuam a ser duas aulas, nunca uma só de 08:30 às 10:10.

  **Nada é adivinhado.** Cada turma do ficheiro ("7º C") é comparada com as turmas que o professor realmente leciona — só as suas, só as da sua organização, só as do ano letivo selecionado — e a correspondência tem de ser exata depois de ignorar acentuação, maiúsculas e o ponto ordinal ("7º C", "7.º C" e "7C" são a mesma turma). Uma turma que não se encontra, ou duas que respondem ao mesmo nome, nunca são resolvidas por palpite: aparecem em "Não associadas", para o professor decidir. Entradas que a grelha usa para outra coisa que não uma aula de turma — apoios, coadjuvações, códigos como "REE - Sem sala" ou "AE_3C_Port" — são mostradas mas nunca importadas como aulas normais.

  **Importar o mesmo ficheiro duas vezes não duplica nada.** Um bloco que já existe no horário da turma é identificado como tal e ignorado; um bloco que se sobrepõe a uma aula já marcada sem ser igual a ela é assinalado como conflito e deixado ao critério do professor — nunca fundido nem substituído automaticamente. Estas verificações são refeitas no momento de confirmar, contra o horário como está nesse instante, e não contra o que a pré-visualização dizia minutos antes. No fim, a mensagem diz quantas aulas foram adicionadas, em quantas turmas, quantas já existiam e quantas ficaram por associar — nunca um "importação concluída" que esconda o que ainda falta resolver.

  **A vigência é pedida uma vez para toda a importação**, e fica em branco por omissão: cada aula fica então limitada pelo ano letivo da própria turma, exatamente como já acontece quando se deixa esse campo vazio no formulário manual. Se o ficheiro for de outro ano letivo, isso é dito de forma bem visível na pré-visualização — sem bloquear a importação, porque pode ser mesmo essa a intenção, e sem nunca mudar o ano letivo selecionado.

  **Criar e editar horários à mão continua exatamente como estava.** Esta importação é um atalho, não uma substituição: o formulário da página da turma, as suas regras de validação e as suas permissões não foram tocados. O PDF carregado é lido em memória e esquecido — nunca é guardado, e não passa a ser fonte de verdade depois da importação. Horários digitalizados (imagem, sem texto) ficam fora do âmbito desta versão e são recusados com uma frase clara em vez de um erro.

## [0.65.1] — 2026-08-24

### Fixed

- **Guardar "Estrutura do ano letivo" deixa de apagar e recriar os períodos existentes.** Abrir a estrutura de um ano letivo já em uso, não mudar nada e carregar em Guardar dava, até agora, um erro interno do servidor sempre que qualquer período já tivesse dados associados — um instrumento, uma classificação, um snapshot de cálculo, uma autoavaliação, um registo, uma intervenção, uma avaliação intercalar ou um relatório. A causa: cada gravação apagava todos os períodos do ano e recriava-os do zero, e a base de dados recusa (com razão) apagar um período com dados dependentes. Cada período passa agora a ter uma identidade própria que atravessa gravações — um período existente é atualizado no lugar, mantendo o mesmo identificador interno, e tudo o que já apontava para ele (notas, classificações, snapshots, instrumentos, ...) continua a apontar corretamente. Guardar sem qualquer alteração deixa de dar erro. Remover um período que ainda tem dados associados continua a não ser permitido, mas passa a mostrar uma mensagem clara em vez de um erro de servidor; um período sem dados associados continua a poder ser removido normalmente. Reordenar períodos (trocar a ordem entre dois) continua a funcionar sem colisões. Editar datas, designação ou tipo de um período que já tem dados continua tão livre como sempre — só a estrutura de gravação mudou, nenhuma restrição pedagógica nova foi introduzida.

## [0.65.0] — 2026-08-24

### Added

- **Criar um Elemento de Avaliação deixa de exigir passar primeiro por uma Turma.** Até agora o único botão "Novo elemento de avaliação" vivia na página de uma Turma — a área global "Avaliação → Elementos de Avaliação" só listava o que já existia, sem qualquer forma de começar a criar um (o texto do estado vazio dizia mesmo "Crie o primeiro a partir de uma turma"). Essa área ganha agora o seu próprio "+ Novo elemento de avaliação", que entra por `/instruments/create`: quando a turma já é conhecida pelo contexto (a partir de uma Turma concreta, como sempre foi), o comportamento mantém-se exatamente o de sempre; quando não é, um passo leve de escolha de turma antecede a criação — só as turmas do próprio professor, nunca as de um colega nem as de outra organização, e um estado vazio claro para quem ainda não tem nenhuma — e escolher uma leva, por navegação normal (não uma submissão), à mesma rota `classes/{turma}/instruments/create` que já existia. O formulário, a validação (`InstrumentRequest`), a associação da turma (`InstrumentBuilder::create`) e a autorização (`SchoolClassPolicy::update`) permanecem byte a byte os mesmos — nada foi duplicado, só a navegação de entrada mudou. Ainda assim entra como funcionalidade nova (não como correção): antes desta fatia não havia, em lado nenhum, uma forma de iniciar esta criação sem já se estar dentro de uma Turma.

  **Turma deixa de duplicar essa gestão.** A secção "Elementos de avaliação" saiu da página de uma Turma — criar e gerir elementos de avaliação passa a viver só na sua área própria; o resto da página de uma Turma continua exatamente como estava.

  **Grelhas de correção não foi tocado.** Corrigir, lançar notas, anular ou reabrir uma correção continuam exatamente como eram — esta fatia mexeu apenas em como se chega ao início da criação.

## [0.64.1] — 2026-08-24

### Fixed

- **Aplicar uma sequência de aulas deixa de apagar sumário, recursos, TPC ou notas que o professor já tivesse escrito à mão.** Dois problemas na Fatia 4: primeiro, desligar explicitamente a opção "Sumário" não impedia o conteúdo de ser copiado sempre que a aula de destino ainda não tinha nenhum sumário — a opção era ignorada exatamente no caso em que mais importava respeitá-la. Segundo, mesmo com uma opção ligada, o valor da sequência substituía sempre o que já estava escrito na aula, em qualquer um dos quatro campos — Sumário, Recursos, TPC, Notas do professor — em vez de só preencher o que estava em branco. Agora uma sequência só escreve num campo quando a opção respetiva está ligada, o item da sequência tem texto nesse campo, e a aula de destino está em branco nesse mesmo campo; falhando qualquer uma destas três condições, o campo fica completamente intocado. Uma aula sem sumário ainda pode passar a existir com conteúdo vazio (nunca com o texto da sequência, se a opção Sumário estava desligada) quando pelo menos outro campo tinha algo genuíno para escrever; se nada havia mesmo para aplicar, a aula é simplesmente ignorada, sem criar um registo vazio. A mensagem apresentada depois de aplicar passa a distinguir aulas efetivamente preenchidas, aulas que já tinham conteúdo próprio e foram preservadas, e itens sem aula disponível — nunca mais uma única contagem de "aplicadas" a esconder o facto de metade da sequência não ter mudado nada.

## [0.64.0] — 2026-08-24

### Added

- **Módulo Aulas e Sumários — Fatia 4: sequências de aulas reutilizáveis.** Um professor com várias turmas da mesma disciplina e ano (ex.: 7ºA, 7ºB, 7ºC) pode agora definir uma vez um plano ordenado de conteúdo de aulas — `LessonSequence` e os seus `LessonSequenceItem` — e aplicá-lo a cada turma sem reescrever o mesmo sumário três vezes. Uma sequência pertence a uma disciplina e a um ano letivo, é opcionalmente limitada a um ano de escolaridade (em branco aplica-se a qualquer turma dessa disciplina, o que a torna utilizável no ensino superior, onde ano de escolaridade não faz sentido), e é pessoal de quem a criou — nunca partilhada com o resto da organização nesta fatia. Deliberadamente secundária: `/lessons/sequences` é uma página própria, com uma ligação discreta a partir da vista semanal, que continua a ser o centro operacional do módulo.

  **Cópia no momento da aplicação, nunca uma ligação viva.** Aplicar uma sequência a uma turma (`ApplyLessonSequence`) escreve o texto de cada item na próxima aula elegível dessa turma — por ordem, a partir de agora, nunca uma aula já lecionada — através do mesmo `SaveLessonSummary` que já deriva Preparado e nunca mexe em Lecionado. A partir desse momento cada sumário é independente: editar a sequência de origem depois de já ter sido aplicada nunca altera as aulas que já a usaram, exatamente a mesma disciplina que já protegia os modelos de relatório desta aplicação.

  **Quatro campos à escolha, notas do professor desligadas por omissão.** Sumário, Recursos, TPC e Notas do professor podem ser copiados independentemente; as Notas do professor começam sempre desligadas e só são copiadas se o professor as pedir explicitamente nessa aplicação em concreto — uma nota escrita a pensar no ritmo de uma turma raramente serve tal e qual noutra. Numa aula que já tem sumário, um campo não selecionado fica completamente intocado; o Sumário em si é sempre escrito quando a aula ainda não tem nenhum, porque a coluna não permite vazio — nunca por acaso, sempre pela mesma regra. Se houver menos aulas elegíveis do que itens na sequência, aplica-se às que existem e o resultado diz exatamente quantas aulas foram preenchidas e quantos itens ficaram sem aula disponível, em vez de falhar.

  **"Basear no sumário anterior", uma conveniência à parte, deliberadamente pequena.** Na página de uma aula sem sumário ainda escrito, um botão oferece copiar o sumário, notas, recursos e TPC da aula anterior mais recente da mesma turma que já tenha sumário — para dentro do formulário atual, ainda por guardar e totalmente editável. Não é uma segunda sequência nem uma ligação entre aulas: é só texto, escrito uma vez, que o professor pode alterar ou substituir como qualquer coisa que tivesse escrito de raiz; nada fica gravado enquanto não for o próprio a carregar em Guardar.

## [0.63.0] — 2026-08-24

### Added

- **Módulo Aulas e Sumários — Fatia 3: um único centro de registo por aula.** O Sumário passa a ser o centro sempre visível da página e a única gravação de conteúdo. A burocracia de planeamento da Fatia 2 — formulário, botões e transições manuais de preparação — foi retirada da experiência; preencher o Sumário deriva automaticamente o estado Preparado, sem ação manual e sem qualquer reabertura artificial.

  Três campos opcionais e recolhíveis completam o registo: **Notas do professor**, privadas e nunca mostradas aos alunos; **Recursos**, como referência ou URL em texto simples nesta primeira versão; e **TPC**, como nota do trabalho atribuído à aula. Estes campos pertencem ao mesmo `LessonSummary` e são guardados em conjunto, sem criar armazenamento paralelo. Deliberadamente não reutilizam `EvidenceRecord` nem o módulo Registos: esse sistema acompanha cumprimento por aluno, enquanto o TPC desta fatia descreve o que foi atribuído numa aula, sem dimensão de aluno — são preocupações diferentes.

  **“Marcar como lecionada” continua explícito e separado.** A transição para Lecionado tem uma ação própria, idempotente e auditada; nunca é inferida ao guardar o Sumário, pela presença de notas, recursos ou TPC, nem pela passagem do tempo. Rever qualquer texto depois de a aula estar lecionada mantém a rastreabilidade sem copiar conteúdo pedagógico ou privado para a auditoria.

## [0.62.1] — 2026-08-24

### Fixed

- **"Registo de Avaliações" passa a "Grelhas de correção" na navegação, no menu da landing e na mock da sidebar.** O nome anterior lia-se como um passo burocrático de arquivo; o novo nome diz o que o professor de facto abre — a grelha onde a correção acontece. Só nomenclatura: a rota, o controlador e a capability continuam `assessments`.

  **Auditado em paralelo, sem alteração de código**: o relato de que "Criação simples" ficava inacessível depois de passar por "Criação avançada" não se reproduz na HEAD atual — o ciclo simples → avançada → simples já preserva os campos comuns e já bloqueia corretamente (sem perder dados) uma estrutura avançada incompatível. Adicionada cobertura de regressão (`InstrumentForm.test.ts`) para os dois casos, sem alterar comportamento.

## [0.62.0] — 2026-08-24

### Added

- **Proteção contra perda de classificações na grelha de correção.** Um professor que edita a mesma grelha em dois separadores, dois browsers ou dois dispositivos deixa de arriscar perder trabalho silenciosamente.

  **Rascunho local imediato.** Cada célula alterada mas ainda não guardada é escrita no `localStorage` do browser (com um pequeno atraso técnico, nunca a cada tecla), isolada por organização, utilizador e elemento de avaliação — um rascunho de outra conta, outro professor ou outro elemento nunca é lido por engano. Ao reabrir a grelha (reload, fecho acidental, falha de rede), um aviso explícito oferece "Recuperar alterações" ou "Ignorar rascunho" — nunca aplicado silenciosamente. Um rascunho cuja estrutura mudou entretanto (questões, cotações ou domínios diferentes) é assinalado como incompatível e nunca aplicado célula a célula por posição ou nome. O rascunho só é apagado depois de o servidor confirmar a gravação; uma gravação falhada ou uma ligação perdida preservam-no.

  **Proteção real no servidor.** Auditado o incidente que motivou esta fatia — duas abas do mesmo professor a guardar alterações diferentes na mesma grelha — confirmou-se por código que o servidor aceitava escritas cegas, sem qualquer verificação de concorrência. Cada célula passa a transportar a sua `lock_version` (coluna já existente na tabela, nunca antes usada); o servidor bloqueia e compara a linha antes de escrever, incrementa a versão quando aceita, e rejeita apenas as células que outro pedido já alterou — as restantes do mesmo lote continuam a gravar normalmente. A grelha substitui as células rejeitadas pelo valor real do servidor e avisa o professor, sem tocar nas restantes edições locais. Cada rejeição fica registada em auditoria (`scores.stale_write_rejected`, só com identificadores técnicos). Autosave automático para o servidor foi deliberadamente **não implementado**: sem uma forma segura e provada de evitar sobrescrever uma edição feita noutro separador, ficou decidido manter o "Guardar" explícito como o único caminho até ao servidor.

  **Indicadores discretos, sem se misturarem com a avaliação em si.** Estado offline ("Sem ligação — N alterações protegidas neste dispositivo") e aviso de edição concorrente noutro separador (via `BroadcastChannel`, só informativo) aparecem em blocos visuais distintos do painel amarelo de resultados por resolver — nunca confundidos com regras pedagógicas.

  **Semântica da avaliação inalterada.** Vazio continua diferente de zero, ausência continua uma decisão explícita nunca inferida, "Guardar" continua a nunca concluir a correção, e nenhuma fórmula ou agregação foi tocada.

## [0.61.2] — 2026-08-23

### Fixed

- **Copy da secção de privacidade e do CTA final da landing, transversal a todos os níveis de ensino.** Duas afinações à correção anterior: "Cada organização acede apenas ao que lhe pertence" passa a "Cada conta acede apenas ao contexto autorizado" (a conta, não a organização, é a unidade que faz sentido tanto no Pro individual como no Institucional); e o parágrafo de RGPD deixa de dizer "o que o LÁPIS garante" — uma afirmação absoluta demasiado forte para uma secção legal — passando a "a conformidade não depende apenas da tecnologia", que distingue corretamente medidas técnicas de responsabilidades do utilizador/organização. O CTA final ("O próximo período pode correr melhor do que o último... Monte um perfil, traga uma turma... Meia hora chega") presumia calendário escolar por períodos e um tom informal — passa a "A próxima etapa pode correr melhor do que a última... Crie o seu perfil de avaliação, importe uma turma..." sem promessa temporal arbitrária. "Não é pedido cartão" clarificado para "Não é necessário cartão de crédito" (ambíguo antes). Só copy — sem alterações de layout, lógica, planos/preços ou segurança real.

## [0.61.1] — 2026-08-23

### Fixed

- **Copy da secção de segurança/privacidade da landing deixa de posicionar o LÁPIS como produto só para menores.** "Isto são dados de menores. A proteção é estrutural, não um acabamento." e "quando uma escola o liga" presumiam sempre uma escola como única decisora e alunos sempre menores — o que não representa o Pro individual (autonomia do titular da conta) nem o ensino superior. A referência a menores mantém-se como caso especialmente sensível, já não como definição universal do produto; o exemplo lateral passa de "turma 9.º B / n.º de processo" para "contexto académico: Turma / Unidade curricular" e "identificador institucional", transversal a básico, secundário e superior. Só copy — sem alterações de layout, lógica ou segurança real.

## [0.61.0] — 2026-08-23

### Added

- **Módulo Aulas e Sumários — Fatia 2 de 6: planeamento antecipado e centro semanal.** `/lessons` deixa de ser o placeholder "em construção" e passa a ser a página operacional real do módulo. O professor pode agora escrever um planeamento (`LessonPlan.planned_summary`) antes da aula acontecer, distinto e nunca copiado silenciosamente para o sumário oficial, e transitar o estado da aula entre Por preparar/Preparado/Lecionado — Preparado exige planeamento ou sumário já preenchido; voltar de Lecionado a um estado anterior é recusado. A vista semanal mostra as aulas de todas as turmas do professor no ano letivo selecionado, com navegação Semana anterior/atual/seguinte, e um botão "Preparar aulas desta semana" que materializa ocorrências em todas as turmas com horário configurado, reutilizando a mesma `MaterializeLessonsForRange` da Fatia 1. O horário recorrente de cada turma (`RecurringLessonSlot`) passa a ser configurável a partir da própria página da turma.

  **Âmbito reduzido face ao plano faseado original**, por pedido explícito: sem notas privadas, recursos, TPC, cumprimento, sequências, continuidade ou faltas/atrasos — ficam para fatias futuras. O ContextBar ainda não tem seletor funcional de turma/disciplina/período (só o ano letivo é real), por isso a vista semanal soma todas as turmas do professor em vez de filtrar por uma só.

## [0.60.0] — 2026-08-23

### Added

- **Módulo Aulas e Sumários — Fatia 1 de 6: ocorrência concreta e sumário simples.** Primeira fatia vertical de um módulo novo (desenho completo em `docs/superpowers/specs/2026-08-23-lessons-summaries-planning-sequences-design.md`, plano faseado em `docs/superpowers/plans/2026-08-23-lessons-summaries-planning-sequences.md`). O professor configura o horário recorrente de uma turma (`RecurringLessonSlot`), materializa ocorrências concretas (`Lesson`) num intervalo dentro do ano letivo — de forma idempotente e nunca como efeito lateral de uma leitura — e escreve/revê o sumário oficial da aula (`LessonSummary`). A primeira gravação nunca marca a aula como lecionada; editar o sumário depois de já lecionada atualiza `reviewed_at`/`reviewed_by` e regista um evento de auditoria sem copiar o texto do sumário.

  **Ainda não incluído nesta fatia** — planeamento antecipado, notas privadas, recursos, TPC, cumprimento, sequências reutilizáveis, continuidade de conteúdo pendente, faltas/atrasos e a vista semanal (chegam nas Fatias 2–6). Todos os modelos são tenant-owned (`BelongsToOrganization`), autorizados por `LessonPolicy`/`RecurringLessonSlotPolicy` através da relação professor↔turma já existente, recusam mutações durante impersonation e usam ULID nas rotas.

## [0.59.0] — 2026-08-23

### Added

- **A grelha de correção passa a poder ser guardada "Em preparação" e retomada mais tarde.** A validação manual da Fatia H mostrou uma confusão conceptual: o formulário de criação não constrói o Elemento de Avaliação (o teste, a ficha, o trabalho) — constrói a respetiva grelha de correção (domínios, questões, cotações, alocações). Duas ações distintas na criação e na edição de uma grelha ainda em preparação — "Guardar e continuar depois" e "Preparar grelha de correção" — tornam isto explícito: guardar persiste a estrutura tal como está, mesmo incompleta (zero questões, cotações por preencher, domínios por definir, soma diferente de 100); preparar aplica todas as validações que já existiam e transita a grelha para "Preparado", o estado que sempre existiu para poder lançar resultados.

  **Reutiliza o estado técnico `draft` que já existia** (`InstrumentStatus::Draft`, agora rotulado "Em preparação" em vez de "Rascunho") — não foi criado nenhum estado novo. O cliente deixa de poder escolher o estado diretamente: envia apenas a intenção (`submission_intent: save|prepare`), e é o servidor que deriva o estado daí.

  **Uma grelha "Em preparação" fica isolada do lançamento de resultados.** Auditados e fechados os pontos que hoje assumiam "existe um Instrument = está pronto": a página de lançamento redireciona para a edição em vez de mostrar uma grelha de alunos incompleta; guardar uma pontuação passa a exigir "Preparado" ou "Em correção" (antes só recusava "Concluído"); a importação de resultados e o descarregamento da grelha Excel deixam de aceitar um elemento em preparação. Nas listagens (Elementos de Avaliação, página da turma, Registo de Avaliações), "Em preparação" aparece com a ação "Continuar preparação", nunca "Abrir"/"Ver resultados".

  **Uma migration reversível** torna `instrument_items.points_possible` opcional — para representar "questão ainda sem cotação" sem usar 0 (que seria indistinguível de uma cotação real de zero). Elementos já existentes em Preparado, Em correção, Concluído ou Anulado não são afetados nem reclassificados. Funciona nos dois modos de criação (simples e avançada), sem sacrificar nenhuma capacidade existente da criação avançada. `ClassResultsCalculator` e `BuildResultsProgression` não foram alterados.

## [0.58.1] — 2026-08-23

### Fixed

- **Precisão e uniformidade da distribuição automática de cotações, na Criação simples.** O smoke manual da v0.58.0 mostrou 100 pontos divididos por 14 questões a gerar 7,1428 — um valor que o próprio input de cotação (`step="0.25"`) rejeita — e o resumo a mostrar "99.99999999999999 / 100". A distribuição automática passa a trabalhar em unidades inteiras de 0,25 (não em pontos brutos arredondados a 4 casas decimais arbitrárias), o que elimina o problema de raiz. O resto de uma divisão não exata deixa de ser todo despejado numa única questão (100/14 já não dá 13×7,00 + 1×9,00) — passa a ser espalhado um passo de cada vez pelas últimas questões, tão uniformemente quanto a grelha de 0,25 permite (100/14 → 6×7,00 + 8×7,25). Os totais apresentados (por questão e por domínio) passam a ser arredondados no próprio valor, não só na apresentação, o que evita o artefacto de vírgula flutuante. A cotação total e a de cada questão passam também a exigir `multiple_of:0.25` no servidor — usando aritmética decimal exata, não comparação de floats — como proteção real, não só uma dica visual do formulário.

## [0.58.0] — 2026-08-23

### Added

- **Vários domínios e várias questões na Criação simples de Elementos de Avaliação.** A validação manual da Fatia H mostrou o modelo anterior demasiado restritivo — 1 domínio, 1 questão. "Criação simples" passa a significar "forma simples de construir a estrutura", não "elemento necessariamente simples": o professor escolhe os domínios avaliados (um ou vários), define quantas questões tem cada domínio (1 por omissão, visível e editável), e ajusta a cotação de cada questão — o peso de cada domínio é sempre **derivado** da soma dessas cotações, nunca pedido diretamente. Cada domínio nasce com uma questão; reduzir a última remove o domínio explicitamente. Excecionalmente, "Editar domínios" numa questão permite dividi-la por mais do que um domínio (ex.: 70%/30%), reutilizando o mesmo `InstrumentDomainAllocations.vue` do modo avançado — agora com um segundo modo de edição em percentagem, fechado por omissão, ao lado do modo em pontos que a Criação avançada já usava. `canUseQuickMode` foi generalizado para múltiplos domínios/questões deixarem de obrigar à Criação avançada; grupos explícitos, bónus e outras estruturas especiais continuam a fazê-lo.

  **Sem tabela nova, sem modelo paralelo.** Cada questão continua a ser um `InstrumentItem` real, com códigos globais Q1…Qn (não reiniciados por domínio) no único grupo implícito de sempre. Nenhum `StudentItemScore` é criado por omissão; guardar continua a nunca concluir o elemento.

  **Grelha Excel redesenhada para refletir a estrutura real.** Cada cabeçalho de coluna passa a mostrar domínio, código, título e cotação máxima, agrupado visualmente por domínio; uma questão multi-domínio continua numa única coluna, assinalada como tal, nunca duplicada. A identidade inequívoca de cada coluna — pensada para uma futura importação — reutiliza o mecanismo de nomes definidos (`LAPIS_ITEM_<coluna>` → ULID) que a grelha de correção já tinha; não foi criada nenhuma folha ou contrato novo. Cabeçalho na linha 1 e alunos a partir da linha 2, como sempre.

## [0.57.1] — 2026-08-23

### Changed

- **Nomenclatura e copy dos modos de criação de Elementos de Avaliação.** "Criação rápida"/"Criação detalhada" passam a "Criação simples"/"Criação avançada" — depois de validação visual, "detalhada" sugeria um fluxo lento, e o modo simples já produz um elemento perfeitamente completo. Novo subtítulo no modo simples ("Crie o elemento com os dados essenciais. O LÁPIS prepara automaticamente a estrutura base.") e descrição do modo avançado reescrita para explicar o motivo de o escolher. A ligação entre modos passa a uma pergunta seguida de uma ação claramente clicável, com indicador visual. Só linguagem — lógica, payloads, validações e testes de domínio inalterados.

## [0.57.0] — 2026-08-23

### Added

- **Criação rápida de Elementos de Avaliação (Fatia H).** A criação passa a abrir num modo curto — turma, designação, tipo, data, período e domínio principal — em vez do formulário completo logo à partida, pensado sobretudo para tablet em contexto de sala de aula (sem modais, sem scroll longo, sem campos avançados visíveis). Escolher um único domínio aloca-lhe automaticamente 100%, reutilizando o mesmo mecanismo do modo detalhado (`InstrumentBuilder`); escolher mais do que um passa para a configuração detalhada de pesos. É possível alternar [Criação rápida]/[Criação detalhada] sem perder o que já estiver preenchido, sempre que a mudança for segura — a alternância bloqueia-se assim que existir estrutura detalhada que ficaria escondida. Um elemento criado no modo rápido é editado depois pelo fluxo detalhado normal, sem distinção.

  **Sem tabela nova, sem modelo paralelo, sem endpoint próprio.** O modo rápido produz exatamente a mesma entidade canónica que o modo detalhado sempre produziu — um `Instrument`, um grupo implícito sem nome, um `InstrumentItem` `Q1` de 100 pontos, uma alocação de 100% — validada no servidor (`quick=true` não pode transportar múltiplos grupos/itens, bónus, estado concluído ou totais diferentes de 100). Nenhuma pontuação ou resultado é criado por omissão; guardar nunca conclui o elemento, exatamente como no modo detalhado. Reforçada a mesma validação de tenancy do fluxo detalhado (período pertence ao ano letivo da turma, domínio pertence ao perfil ativo da turma) para ambos os modos.

## [0.56.0] — 2026-08-23

Release consolidada: reúne tudo o que foi concluído e testado desde a v0.45.9 — a versão confirmada ao vivo em produção (`74e8ff2`, 2026-08-22) — até este commit. Dez releases formais (v0.46.0 a v0.51.0) e cinco bumps informais desde então (v0.52.0 a v0.55.3), já individualmente documentados abaixo, mais as duas correções desta entrada. Zero migrations, dependências, variáveis de ambiente ou alterações de scheduler novas em todo o intervalo.

**Não incluído nesta release** — trabalho auditado, especificado ou iniciado na mesma janela mas deliberadamente deixado de fora: criação rápida de elementos de avaliação em modo simples/detalhado (Fatia H, só auditada), importação Excel "Grelha LÁPIS" para elementos de avaliação (Fatia I, por especificar), perfis de avaliação persistentes entre anos letivos (Fatia C, adiada por decisão de design), refinamento do checklist do painel (Fatia B, nunca retomada) e os itens menores de auditoria da Fatia J — incluindo a origem por explicar do ano letivo "9.º A Teste" no seletor.

### Fixed

- **Último resíduo de inglês visível.** O título por omissão de `AlertError` ("Something went wrong.") só aparecia nos dois ecrãs de 2FA que não o substituem por um próprio; passa a "Ocorreu um erro.". Auditados ambos os fluxos por inteiro — não fica nenhum outro texto em inglês.
- **`docs/deployment.md` apontava para a versão errada em produção.** Dizia 0.37.0/2026-08-20; uma verificação ao vivo por SSH (só leitura) confirmou produção em 0.45.9 @ `74e8ff2`, construída 2026-08-22. Documento corrigido; processo de deploy inalterado.

## [0.55.3] — 2026-08-22

### Changed

- **Copy da secção "O modelo da sua escola" na landing.** Reescrita para deixar claro que domínios, ponderações, escala e organização do ano letivo são estruturas que a escola/instituição define, não decisões individuais do professor. Removida a linguagem técnica "versionado". Layout, ícones e responsividade inalterados.

## [0.55.2] — 2026-08-22

### Changed

- **Ajuste à secção "Porquê LÁPIS?".** Corrigido o acento ("Porque" → "Porquê"). A secção passa a ter fundo próprio — o tom quente de papel já usado no cabeçalho/rodapé (`CHROME_SURFACE`, novo prop `warm` em `LandingSection`), criando uma pausa visual entre as secções brancas envolventes. Restante copy, destaque do acrónimo e layout inalterados.

## [0.55.1] — 2026-08-22

### Added

- **Secção "Porque LÁPIS?" na landing.** Nova nota editorial de marca entre "O Problema" e "Como funciona", explicando o acrónimo (Laboratório de Apoio ao Professor, Informação e Simplificação) com as iniciais destacadas por cor de destaque existente e peso tipográfico — nunca só cor. Compacta, sem cards nem ícones, reutilizando o `LandingSection` e o `RevealOnScroll` já usados no resto da página.

## [0.55.0] — 2026-08-22

### Changed

- **Nomenclatura visível "Instrumento" → "Elemento de Avaliação".** A revisão manual ainda encontrava "Novo instrumento", "Editar instrumento", "Anular instrumento" e mensagens equivalentes espalhadas pela UI e por mensagens de validação/erro do backend — a maior parte já dizia "Elementos de Avaliação" (navegação, listagens), mas não em todo o lado. Corrigidos 17 pontos no frontend (botões, títulos, breadcrumbs, tooltips, diálogos de confirmação) e 14 no backend (exceções de validação, do fluxo de correção, de importação, mensagens do controlador, dois enums de adaptações de avaliação). Só linguagem visível — `Instrument`, `InstrumentController`, rotas técnicas, tabelas e testes mantêm o nome técnico. Deliberadamente não tocado: o texto gerado das secções de relatórios (`app/Services/Reporting/`), porque esse texto passa por um guarda de reescrita (`RewriteGuard`/`WritingGlossary`) que protege a precisão da prosa de documentos oficiais e cuja sincronização exige uma revisão própria, cuidadosa — registado para decisão futura.

## [0.54.3] — 2026-08-22

### Changed

- **Copy da secção "O Problema" na landing.** Eyebrow, título, texto introdutório, os 4 blocos e a frase final reescritos, sem travessões. Layout, grelha, tipografia e numeração 01-04 inalterados.

## [0.54.2] — 2026-08-22

### Changed

- **Dois parágrafos do hero da landing.** Texto sobre avaliação/acompanhamento e sobre turmas/critérios/elementos de avaliação reescritos. Só estes dois parágrafos.

## [0.54.1] — 2026-08-22

### Changed

- **Faixa de níveis de ensino na landing.** "Superior" passa a "Universitário" na hero. Só esta palavra.

## [0.54.0] — 2026-08-22

### Added

- **Editar turma.** Uma turma criada com a designação errada obrigava a apagar e recriar — não havia forma de a corrigir. Ação "Editar turma" nova, descoberta a partir do cartão em Turmas (ícone com aria-label) e da própria página da turma (botão). O formulário só permite alterar a Designação, seguindo a convenção "Ex.: 7.º A" da Fatia E; ano letivo, disciplina e ano de escolaridade ficam bloqueados e explicados como tal — alteram-nos e desalinhariam elementos de avaliação e relatórios já associados. O `FormRequest` já previa este caso (a verificação de unicidade já sabia excluir-se a si próprio); reutilizado tal e qual, sem endpoint paralelo. Mesmo que um pedido tente enviar outros campos, o controlador só lê e grava a designação — o bloqueio é aplicado no servidor, não só escondido na UI.

## [0.53.2] — 2026-08-22

### Changed

- **Bloco de marca no cabeçalho da landing.** Tagline atualizada para "Mais simples. Mais tempo para o que realmente importa." e o conjunto "LÁPIS" + tagline ligeiramente maior (15px→17px / 11px→12px), mantendo "LÁPIS" visualmente dominante. Só este bloco — ícone, cores, resto do cabeçalho e restantes secções inalterados.

## [0.53.1] — 2026-08-22

### Changed

- **Copy do bloco "Três regras" na landing.** Título e os três cartões junto de `#como-funciona` reescritos para descrever vazio/zero, "não aplicável" e entrada tardia com mais precisão. Só texto — layout, tipografia e responsividade inalterados.

## [0.53.0] — 2026-08-22

### Added

- **Convenção transversal de exemplos nos campos.** Vários formulários mostravam um exemplo ("7.º A", "Q1", "Compreensão do texto"...) que parecia um valor já introduzido. Todo o texto de exemplo passa a seguir o padrão "Ex.: …" — 24 campos corrigidos em 17 páginas (turmas, disciplinas, perfis de avaliação, elementos de avaliação, ano letivo, equipa, admin, autenticação, relatórios, importação). Uma regra `::placeholder` global e centralizada (`resources/css/app.css`, reutilizando o token `--muted-foreground` já existente) garante que todo o placeholder da aplicação — incluindo campos nativos fora do componente `Input` — usa a mesma cor ténue e acessível, sem depender de repetir a classe campo a campo. Valores por omissão genuinamente funcionais (cotação total = 100, código de questão auto-numerado "Q1"/"Q2"...) foram auditados e mantidos como valores reais, não convertidos em exemplos. Campos instrutivos ("Nome completo", "Palavra-passe", "Procurar por nome ou email…") mantidos como estão — não são exemplos, são instruções.

## [0.52.0] — 2026-08-22

### Added

- **Ano letivo passa a ser um seletor real na barra de contexto.** "Ano letivo" era uma ligação de navegação para a página de gestão; passa a ser um menu suspenso com o ano atual e até três anos anteriores, cada um selecionável — a escolha grava-se na sessão (mesmo padrão já usado para trocar de organização) e passa a determinar o contexto de toda a app. A lista mostra sempre os anos mais recentes da organização, incluindo um ano ainda em preparação (rascunho) que ainda não seja o "atual" resolvido pela heurística existente — para que o professor consiga entrar nele e continuar a prepará-lo. Selecionar um ano mais antigo não esconde os mais recentes da lista. O acesso à gestão de anos letivos mantém-se, agora como opção separada dentro do menu. Corrigida também a mensagem da Disciplina na barra de contexto, que dizia sempre "Sem disciplinas configuradas" mesmo quando já existiam disciplinas só por não haver nenhuma selecionada — agora distingue os dois casos. Responsividade da barra de contexto ajustada para telemóvel (os seletores ainda não interativos ficam ocultos abaixo do desktop; Ano letivo e Disciplina mantêm-se sempre visíveis).

## [0.51.0] — 2026-08-22

### Added

- **Ligação e autoria em Estratégias e Medidas.** Depois de auditar o módulo, a maior parte do que se pedia já estava construída — o acompanhamento como ação de primeira classe (histórico preservado, nunca sobrescrito), o enquadramento pedagógico/legal, a biblioteca partilhada com Relatórios. Ficavam por fazer duas ligações pequenas: o nome de um aluno numa intervenção individual passa a ligar à sua própria página de Evolução (a ligação inversa já existia); e cada intervenção passa a indicar quem a registou, o que só importa quando uma turma tem mais do que um professor.

## [0.50.0] — 2026-08-22

### Added

- **Revisão de estratégia pendente, na Evolução do Aluno.** `Intervention::needsReview()` já existia — a data que o professor escolheu, já passada, numa estratégia ainda em curso — mas não aparecia em lado nenhum. A secção Estratégias e Medidas do aluno passa a mostrar "Revisão pendente" em cada linha nessa situação, e uma contagem no cabeçalho da secção. Composto inteiramente a partir do método já existente — sem regra nova, sem inferência, sem linguagem de "aluno em risco".

### Fixed

- **`docs/status.md` estava desatualizado.** Continuava a listar "Análise da Turma" como o último placeholder da Fase 3 e como o próximo candidato — já está feito desde a 0.35.0, com o nome de "Estatística".

## [0.49.0] — 2026-08-22

### Added

- **Preparação do ano letivo, no Painel do Professor.** Enquanto a organização não estiver totalmente pronta para o ano, o painel mostra uma checklist de cinco passos — ano letivo ativo, disciplinas configuradas, um perfil de avaliação para este ano, uma turma criada, um perfil associado a essa turma — em vez do antigo ecrã "ainda não tem turmas", que passa a ser só um desses passos. Só o primeiro passo por concluir recebe um botão de ação; os seguintes ficam visíveis mas sem ação até chegar a sua vez. Assim que os cinco estiverem concluídos, a checklist desaparece e o painel volta ao normal (turmas e pendências). Nenhuma rota, página ou ação nova — reutiliza inteiramente a criação de anos letivos, disciplinas, perfis (incluindo a reutilização entre anos já existente) e turmas; a heurística de "ano atual" é a mesma já usada pela barra de contexto. Zero dados de alunos lidos ou escritos.

## [0.48.4] — 2026-08-22

### Changed

- **Bloco de polimento UX: reutilização de perfis, TPC e landing.** A ação de reutilizar um perfil de avaliação passa a mostrar "Reutilizar" por extenso na listagem (não só um ícone), e a própria página lidera com a instrução ("Crie uma cópia deste perfil para outro ano letivo.") em vez do contexto do perfil. Na grelha rápida de trabalho de casa, os três estados ganham símbolos distintos (✓ ◐ ✕) — no desktop mostravam todos o mesmo "✓", distinguidos só pela posição e pela cor — e os contadores passam a badges compactas com símbolo, rótulo curto e número. A landing ganha a copy final da hero ("Mais simples. Mais tempo para o que realmente importa.") e o CTA para visitantes não autenticados passa de "Criar conta" para "Experimentar LÁPIS" em todos os botões de ação (mantido "Criar conta" onde é apenas uma ligação de navegação no rodapé, e a secção de preços ficou fora de âmbito). Sem alterações de domínio em nenhum dos três.

## [0.48.3] — 2026-08-22

### Changed

- **Polimento visual de "Importar configuração" e "Pré-visualizar importação".** Sem alterações ao domínio: mesmo parser, schema, business keys, plano de importação, idempotência, conflitos, escritas, tenancy, autorização, entitlement, limite de 20 MB. O seletor nativo de ficheiro dá lugar a uma zona de arrastar-e-largar (clicável, navegável por teclado, sem esconder o input nativo), com confirmação imediata do ficheiro escolhido — nome, tamanho, "Alterar ficheiro"/"Remover" — sem analisar automaticamente. O aviso de privacidade fica igual em ambas as páginas e na de exportação. A pré-visualização mostra agora a data e a versão em que o ficheiro foi criado, cada elemento da lista com o seu tipo (Ano letivo/Disciplina/Perfil de avaliação), nome e contexto separados, e um resumo do que falta importar. O botão "Importar novos elementos" continua desativado quando não há nada novo.

## [0.48.2] — 2026-08-22

### Changed

- **Polimento visual da página "Partilhar configuração".** Sem alterações ao domínio: mesma whitelist, mesmo preview, mesma idempotência, mesma tenancy. A ação "Importar configuração" passa a estar junto ao título, em vez de solta por baixo; as opções de exportação passam de checkboxes soltas a linhas clicáveis com contexto; um grupo sem opções (por exemplo "Escala" quando a organização não tem escalas próprias) mostra sempre uma frase discreta em vez de ficar vazio; o botão "Gerar e descarregar" começa desativado e só ativa com pelo menos um elemento selecionado, com um resumo em tempo real ("1 elemento selecionado" / "N elementos selecionados"); o aviso de privacidade passa a incluir "registos pedagógicos" na lista do que nunca é exportado.

## [0.48.1] — 2026-08-22

### Fixed

- **A exportação de configuração não descarregava num browser real.** `Export.vue` lia o token CSRF de uma meta tag que o layout nunca renderizava; corrigido a adicionar `<meta name="csrf-token">` a `app.blade.php`. Só afetava o download direto por `fetch()` — todos os outros pedidos da aplicação passam pelo router do Inertia, que já resolve o CSRF sozinho.

## [0.48.0] — 2026-08-22

### Added

- **Reutilização de perfis de avaliação entre anos letivos.** O owner pode escolher um ano letivo já existente, pré-visualizar o mesmo plano New/Existente/Conflito/Inválido da partilha seletiva e criar uma cópia estrutural independente do perfil nesse ano. A cópia recebe uma versão própria em rascunho, nunca é ativada automaticamente e nunca substitui um perfil equivalente; a origem fica intacta. O fluxo reutiliza integralmente as actions de exportação, planeamento e escrita já existentes, corre sob `module:assessment_profiles`, reconfirma o pacote devolvido pelo browser contra a origem e o destino atuais e audita a operação sem dados de alunos.

## [0.47.0] — 2026-08-22

### Added

- **Partilha seletiva de configurações entre professores.** Em Configuração → "Partilhar configuração", um professor escolhe o que quer entregar a um colega — identidade da escola, anos letivos, disciplinas, escalas próprias, perfis de avaliação — e recebe um ficheiro JSON. Nunca inclui alunos, matrículas, classificações, autoavaliações, registos de evidência, intervenções, relatórios, utilizadores, associações, palavras-passe, segredos ou qualquer dado derivado: a lista de campos exportáveis é uma whitelist fechada, e cada perfil de avaliação arrasta consigo apenas o seu ano letivo, disciplina, escala (referenciada por nome quando é uma escala de sistema, incluída quando é própria) e domínios — nunca os resultados de nenhum aluno. "Importar configuração" faz sempre uma pré-visualização primeiro (New/Existente/Conflito/Inválido, por chave de negócio — ano letivo por rótulo, disciplina por código, escala por nome, perfil por ano+disciplina+ano de escolaridade+nome), nunca escreve nada na fase de análise, nunca sobrepõe uma linha existente ou em conflito, e é idempotente (reimportar o mesmo ficheiro não duplica nada). Um perfil importado entra sempre como rascunho — a versão nunca fica ativa sozinha; o professor decide quando a publicar. Atrás do módulo `template_sharing` já existente nos planos Pro e Institucional (Base fica de fora); a sessão de pré-visualização fica amarrada ao organization_id de quem a criou, por isso não pode ser confirmada contra outra organização.

  **Sem tabela nova, sem coluna nova, sem alteração ao modelo de avaliação.** Reutiliza inteiramente o modelo de domínio já existente (`AcademicYear`, `Subject`, `Scale`, `AssessmentProfile`/`AssessmentProfileVersion`/`Domain`) através de duas ações novas e isoladas em `App\Actions\ConfigSharing` — deliberadamente não ramificadas do importador de cópia de segurança (Fatia 6), para a whitelist ficar estruturalmente incapaz de incluir dados pedagógicos, nunca dependente de alguém se lembrar de a manter assim.

## [0.46.0] — 2026-08-22

### Added

- **Registo rápido de Trabalho de casa, em grelha.** Em Ação Pedagógica → Registos, escolher o tipo "Trabalho de casa" numa turma e data já não abre o formulário genérico, aluno a aluno — mostra a turma inteira (só matrículas ativas) numa grelha com três estados por aluno (Realizado / Parcialmente realizado / Não realizado, mutuamente exclusivos, nenhum por defeito — em branco significa sempre "sem informação", nunca "não realizado") e uma "Observação" opcional e recolhida por omissão. Ações em massa ("Marcar todos como…", "Limpar") afetam só os alunos apresentados e continuam editáveis individualmente depois. Uma "Descrição comum" opcional preenche a Observação de cada aluno como ponto de partida, sem apagar o que já tiver sido personalizado numa linha. Contagens ao vivo, um único pedido para a turma inteira, uma só transação (com bloqueio da turma para serializar duas primeiras gravações em simultâneo). Reabrir a mesma turma/data recarrega exatamente o que foi gravado; limpar o estado de um aluno remove (soft-delete) o registo desse aluno para essa data, nunca fica por defeito como "não realizado". Uma eliminação explícita e confirmada apaga o conjunto todo de uma turma/data.

  **Sem tabela nova, sem módulo novo, sem migration.** Reutiliza inteiramente `EvidenceRecord`/`EvidenceKind::Homework`/`HomeworkStatus` (já existentes desde o módulo Registos original, com os três estados já corretos). A identidade de "o mesmo Trabalho de Casa" é deliberadamente simples — turma + tipo + data — e está documentada como tal: nunca existiu unicidade nessa combinação nesta tabela, e o formulário lento já não a impunha; duas tarefas diferentes escritas na mesma turma no mesmo dia através desta grelha continuam, hoje, a ser o mesmo conjunto de registos por aluno. Outros tipos de registo e a edição individual de um registo já existente mantêm-se exatamente como estavam. Nunca entra em `ClassResultsCalculator`/`BuildResultsProgression`/propostas/classificações — é só acompanhamento pedagógico, como qualquer outro registo.

## [0.45.9] — 2026-08-22

### Added

- **Reposição segura de palavra-passe no backoffice.** O detalhe de uma conta em `/admin` ganha "Repor palavra-passe" — envia o link de redefinição normal do Laravel (`Password::sendResetLink`, mesmo broker, mesmo token de utilização única, 60 minutos) para o email da conta, com diálogo de confirmação. Nunca mostra a palavra-passe atual, nunca a altera diretamente, nunca reativa uma conta desativada (essa continua bloqueada no login pela guarda já existente, mesmo depois de repor). Uma segunda ação, "Gerar palavra-passe temporária", reutiliza o mesmo padrão já usado na criação de contas (`Str::password(14)`, nunca persistida em claro) e mostra o valor uma única vez, com botão "Copiar" — nunca recuperável depois de sair da página. Disponível só para platform admin (herda a autorização do grupo de rotas já existente); bloqueado durante impersonação; ambas as ações auditadas sem nunca gravar a palavra-passe ou o token.

### Fixed

- **Uma falha de entrega SMTP ao repor a palavra-passe a partir do backoffice também escapava sem tratamento.** `Password::sendResetLink()` chama o envio da notificação diretamente, sem guarda própria (`Illuminate\Auth\Passwords\PasswordBroker`) — o mesmo padrão de falha corrigido em 0.45.8 para o registo. Corrigido a apanhar a exceção no controlador (o operador está a olhar diretamente para a página da conta, por isso é aí, e não no modelo, que faz sentido avisar de imediato) e a mostrar o mesmo erro já usado para outros estados falhados do broker, em vez de um 500.

## [0.45.8] — 2026-08-22

### Fixed

- **Uma falha de entrega SMTP no email de verificação derrubava o registo inteiro com um 500.** Diagnosticado em produção: `smoke2.local@lapis.test` (domínio local, sem entrega real possível) foi rejeitado pelo relé com um `550`, e a exceção do Symfony Mailer propagava-se por todo o pedido HTTP sem nada a apanhar — apesar de a conta, a organização pessoal, a membership e a subscrição inicial já terem sido criadas com sucesso antes desse ponto (confirmado em produção: zero dados órfãos). O mesmo caminho síncrono (`Registered` → `SendEmailVerificationNotification` → `MustVerifyEmail::sendEmailVerificationNotification()`) é partilhado pelo envio automático do registo e pelo botão "Enviar novamente" — corrigido uma única vez, sobrepondo o método em `App\Models\User` (nunca em código vendor) para nunca deixar uma falha de transporte escapar: `report()` regista o erro para alguém tratar, a conta nunca é marcada como verificada, e a pessoa continua o fluxo normal, vendo "A conta foi criada, mas não foi possível enviar o email de verificação neste momento. Pode tentar reenviá-lo dentro de instantes." em vez de um ecrã de erro. Sem filas nem workers novos — a decisão de não os introduzir nesta correção está registada, dado que produção não tem atualmente nenhum queue worker a correr.

## [0.45.7] — 2026-08-22

### Fixed

- **O campo de email do registo público ficava impossível de editar.** `Register.vue` vinculava `:value="prefillEmail ?? undefined"` ao componente `Input` partilhado — um prop que este não declara (só `defaultValue`/`modelValue`), por isso o Vue reencaminhava-o como atributo bruto direto para o `<input>` nativo, entrando em conflito com o `v-model` interno do componente (`useVModel` do `@vueuse/core`). O resultado: a cada nova renderização do formulário — o que acontece a cada tecla — o valor do campo era reposto a vazio, tornando impossível escrever, colar, editar parte do endereço, Ctrl+A, ou usar Backspace/Delete. Reproduzido em browser real (escrever, colar, editar a meio, substituir tudo, apagar a meio): todas as interações resultavam em campo vazio. Corrigido trocando para `:default-value`, o prop que o componente já declara e que só semeia o valor uma vez — o mesmo padrão já usado corretamente noutros campos da aplicação (ex. Nome em Configurações → Perfil). Auditado todo o `resources/js` por outros usos de `:value` num `Input` editável: nenhum outro encontrado — os restantes são `<option>`, campos `readonly`, ou um componente de apresentação distinto, por isso a correção fica isolada a este campo.

## [0.45.6] — 2026-08-21

### Added

- **Fusão do ramo remoto (`origin/main`) com os 41 commits locais acumulados desde a v0.42.6.** Dois ramos tinham avançado em paralelo sem se cruzar durante quase duas semanas: `origin/main` recebeu diretamente a nova landing page comercial (bullets abaixo); o local recebeu as Fatias 5 (encerramento recuperável de conta e de organização, `0.43.0` mais abaixo), 6 e 6.1 (backup/restauro pedagógico completo, incluindo clonagem entre organizações), a UI de Administração Institucional (`0.45.4`) e a consistência pt-PT (`0.45.5`). Os dois ramos usaram, cada um e de forma independente, o número `0.43.0` para conteúdo diferente; como o CHANGELOG não permite versões repetidas (`ReleaseVersionTest`), a entrega da landing page — que nunca chegou a ser publicada com esse número — entra diretamente aqui em vez de manter uma entrada `0.43.x` própria.
- **Nova página pública em `/`.** A homepage deixa de ser o cartão de visita de quatro pilares e passa a ser a página comercial do produto: problema, como funciona, personalização da avaliação, funcionalidades, o produto em ecrã, o que se ganha, confiança e proteção de dados, planos, perguntas frequentes e chamada final. Cabeçalho público fixo com âncoras e menu para telemóvel, e rodapé com produto e conta.
- **Os cartões de planos são lidos das tabelas de entitlements, não escritos no componente.** `HomeController` devolve cada plano com os módulos que carrega e, do segundo em diante, só o que ACRESCENTA ao anterior — por isso «tudo do LÁPIS Base, mais…» nunca pode divergir de `module_plan`. A tabela «Comparar os três planos» é gerada da mesma fonte. Mover um módulo entre planos no `EntitlementsSeeder` muda a página sem tocar em código de frontend.
- **Sete maquetas fiéis de ecrãs reais** — a proposta de classificação, o perfil de avaliação versionado, a pauta importada, a grelha de correção com os estados por célula, a análise da turma, as estratégias e medidas, e o relatório por secções — construídas com os componentes e os tokens da própria aplicação, e não com imagens. A análise da turma reutiliza o `OutcomeDonut` do dashboard de Estatística: uma página que redesenha um gráfico que o produto já tem está a mostrar algo que o produto não faz. Os dados são representativos; todos os estados e colunas mostrados existem.
- **As funcionalidades são apresentadas como as cinco etapas do ano** — organizar, avaliar, acompanhar, intervir, documentar — em separadores com um ecrã real por etapa, na mesma ordem por que `config/navigation.php` constrói o menu lateral. Uma grelha de quinze cartões iguais era a maneira óbvia de mostrar isto e a errada: quinze cartões dizem «aqui está uma lista», e ninguém lê uma lista. O `tablist` responde às setas do teclado.
- **Uma banda curta com as três regras de cálculo** que uma folha de cálculo não tem — vazio não é zero, «não aplicável» sai da conta, chegar tarde não custa zeros (§13.3, §11.4). Um argumento vale mais numa linha do que explicado.
- **SEO servido pelo servidor.** `description`, `canonical`, Open Graph, Twitter card e JSON-LD `SoftwareApplication` são escritos em `resources/views/app.blade.php` para a componente `Welcome`. Não podem viver no `<Head>` do Inertia: o SSR está desligado, e um robô lê a resposta antes de o bundle correr. Há um teste que fixa isto.

### Changed

- **A página responde ao ponteiro e ao scroll.** As maquetas levantam-se e os três pontos do cabeçalho ganham cor; os cartões de planos levantam-se com a sombra a acompanhar; as linhas das perguntas aquecem e o «+» roda; os separadores das etapas, os cartões do problema, as ligações do menu e do rodapé têm todos estado. Ao entrar em ecrã, cada secção revela-se — as maquetas em escala, o texto a subir — e o traço de acento do sobretítulo, a linha que liga os quatro passos e o traço de cada regra desenham-se da esquerda para a direita. Tudo isto é `motion-safe`: com `prefers-reduced-motion` o conteúdo aparece já colocado e nada se move.
- **Cada maqueta abre «O LÁPIS por dentro».** Clicar numa das janelas abre um diálogo com a estrutura da aplicação — o menu lateral pela ordem real, os seletores de contexto no cabeçalho, as ações da turma e a tabela de proposta e decisão — com cinco explicações que acendem a zona a que dizem respeito. Funciona nos dois sentidos: apontar para uma zona da imagem acende a explicação, e percorrer a lista acende a zona. A lista é o caminho acessível, feita de botões reais — e é por isso que a imagem não tem interatividade própria: um ponto sensível sobre a tabela teria de ser um botão a envolver uma tabela, o que não é válido nem utilizável.
- **O cabeçalho e o rodapé passam a ter tom próprio** — papel frio em claro, carvão azulado em escuro — em vez de serem o fundo da página com um contorno. Num ecrã escuro isso eram três pretos empilhados, e a moldura desaparecia dentro do conteúdo.
- **Barra de progresso de leitura** no limite inferior do cabeçalho, a partir do momento em que a página desce. Decorativa — o número que codifica é o da barra de scroll, que a tecnologia de apoio já expõe.
- **Os cartões de planos mostram no máximo seis módulos** e contam o resto. O Base carrega catorze, o que fazia um cartão mais alto do que um ecrã de portátil — e uma secção de preços que obriga a percorrer duas vezes é uma secção de preços que se salta. A tabela completa continua a um clique.
- **As Perguntas passam a duas colunas** — título e chamada para ação à esquerda, perguntas a ocupar a largura que antes ficava vazia.
- **Copy encurtada** em quase todas as secções. O texto anterior era exato e demasiado longo para uma página que se lê a percorrer.
- A rota `/` passa de `Route::inertia` para `HomeController`, por precisar de ler os planos. Continua a chamar-se `home` e continua a servir a mesma componente `Welcome`.
- Quem visita `/` já autenticado continua a ver a página — o cabeçalho e as duas chamadas para ação trocam para «Ir para o painel» em vez de redirecionar, que é o que se espera de quem chega por um link partilhado e a única forma que não pode entrar em ciclo com o painel.
- **O tour «O LÁPIS por dentro» deixa de existir abaixo de `lg`.** É uma vista de duas colunas cujo objetivo é apontar para uma zona e acender a explicação; num telemóvel colapsa num scroll longo sem nada que o conduza, que é pior do que a maqueta que o visitante já estava a ver. `display: none` tira também o botão da ordem de tabulação, para não ficar nada invisível alcançável. (Os efeitos de hover já não disparavam ao toque — o Tailwind v4 envolve todos os `hover:` em `@media (hover:hover)`.)
- **O tom do cabeçalho e do rodapé passa de azul para o âmbar da marca**, e mais saturado do que a primeira tentativa: `hsl(36 75% 93%)` em claro e `hsl(28 55% 13%)` em escuro, contra os `hsl(38 88% 65%)` do lápis do logótipo. Mesma família, um à luminosidade de papel e outro à de tinta.
- **O tom do texto teve de acompanhar o fundo.** O `text-muted-foreground` é um cinzento escolhido contra branco; sobre o creme novo mede 4,21:1, abaixo dos 4,5:1 que texto normal exige. Saturar o fundo sem mexer no texto teria tornado o rodapé discretamente ilegível. As duas cores de texto do chrome são quentes e medidas — 4,90:1 em claro, 6,77:1 em escuro. O botão «Entrar» ganhou hover próprio pela mesma razão: o token `accent` partilhado é um âmbar pálido escolhido contra branco e, neste fundo, o hover lia-se como não ter acontecido nada.

### Fixed

- **As âncoras do menu pareciam não funcionar em telemóvel, e a culpa era do scroll suave.** A página tem cerca de 13000px num ecrã pequeno; com `scroll-behavior: smooth`, um salto do hero para «Perguntas» animava durante mais de três segundos. Medido com toque emulado, aos 2,5s a página ainda ia a meio caminho (7430 de 8326) — o que quem toca no menu lê como «está partido», não como elegância. Tocar outra vez cancelava a animação a meio, o que explica aterrar «no meio das secções». O scroll suave foi removido: um salto instantâneo é o que uma âncora deve fazer, é igual em todos os browsers incluindo o Safari do iOS, e são menos vinte linhas de código.
- **A gaveta do menu fecha antes de a página rolar.** Enquanto está aberta, o reka-ui põe `overflow: hidden` no body e um scroll pedido nesse momento é simplesmente ignorado. Esperar um número fixo de milissegundos era adivinhação — a animação de fecho não dura o mesmo em todo o lado. Passa a esperar que o painel saia mesmo do DOM, com desistência ao fim de ~1,5s para nenhuma ligação ficar morta.
- **Ao saltar para uma secção, o título ficava a 190px do topo** de um ecrã de 844 — quase um quarto vazio antes de se ler alguma coisa. Ritmo vertical mais apertado no telemóvel e margem de scroll colada ao cabeçalho: passa a 150px, com o topo da secção a 72px.

### Não incluído

- **Preços.** Não existe nenhum preço no produto — nem em configuração, nem em base de dados — e a composição comercial dos planos está na lista do §31. Os cartões mostram o que cada plano CARREGA e dizem «Preço por anunciar» no Pro e no Institucional. O Base diz «Incluído ao criar conta», que é literalmente o que acontece hoje: o registo cria a organização já subscrita ao Base e nada pede cartão.
- Páginas de Termos, Privacidade, Cookies, Sobre e Contacto — não existem, por isso o rodapé não as inventa como links mortos.
- Imagem Open Graph (`og:image`) — sem asset, o cartão grande do Twitter/X renderiza uma caixa vazia, por isso ficou `summary` e não `summary_large_image`.

## [0.45.5] — 2026-08-21

### Fixed

- **Inglês residual na interface, sobretudo em Configurações.** Auditoria global ao `resources/js` confirmou que `lang/pt_PT/{auth,passwords,validation}.php` já estavam completos e ativos (`.env` já define `APP_LOCALE=pt_PT`) — o inglês encontrado era sempre copy fixa nos componentes Vue, nunca mensagens do Fortify/Laravel. Corrigidos Configurações → Perfil, Segurança, Aparência; gestão de 2FA e chaves de acesso (passkeys); páginas de autenticação (login, confirmação de password, etc.); menu do utilizador; exportação de dados; ligações de autoavaliação; e rótulos de acessibilidade (`sr-only`/`aria-label`) em diálogos, painéis laterais, breadcrumb e indicador de carregamento reutilizados em toda a aplicação. Inclui dois toasts que citavam `__('Password updated.')`/"Password temporária" sem qualquer chave `pt_PT` correspondente — ficavam literalmente em inglês em produção. Sem alterar nomes técnicos, enums, rotas, chaves de API ou colunas de base de dados; os dois erros devolvidos pelo pacote externo de passkeys (`@laravel/passkeys/vue`) continuam em inglês por não haver, no projeto, uma forma segura e já usada de lhes definir o idioma.
- **`routes/web.php` com um import fora de ordem** (`InstitutionAdminController` depois de `InstrumentController`, introduzido em 0.45.4), apanhado só agora por `pint --test`.

## [0.45.4] — 2026-08-21

### Fixed

- **O encerramento da organização institucional (Fatia 5) não tinha caminho de UI próprio.** A ação, a janela de recuperação de 90 dias, a reativação e toda a proteção de autorização já existiam e estavam testadas desde a Fatia 5 — mas a única forma de lhes aceder era a secção "Encerrar organização" no fundo de `team/Index.vue` (Equipa), enquanto o item de menu "Administração Institucional" mostrava um placeholder genérico da Fase 7, sem qualquer ligação ao encerramento. Sem alterar a ação, as rotas, a política ou a janela de retenção, a secção passou para uma página própria e mínima em Instituição → Administração Institucional (`institution.index`, `InstitutionAdminController`, componente reutilizável `ClosureCard.vue`), com uma frase explícita a distinguir esta ação — que afeta só a organização institucional — do encerramento da conta pessoal do próprio responsável (Configurações, 60 dias). "Equipa" mantém-se dedicada à gestão de membros e convites.

## [0.45.3] — 2026-08-21

### Changed

- **Clarificado, por auditoria, o comportamento de autoria na clonagem entre organizações (não é um bug).** Registos pedagógicos, estratégias/medidas e relatórios têm autor obrigatório na base de dados — não podem, por regra já existente, ser atribuídos a ninguém que não seja quem os escreveu. Quando o mesmo professor restaura a própria conta para uma organização nova ou vazia, a autoria mapeia-se corretamente, sem qualquer alteração de código — já funcionava. Quando o destino é uma conta genuinamente diferente, esses três domínios ficam corretamente `invalid`; a estrutura e a avaliação sem exigência de autoria pessoal continuam a clonar-se normalmente. A mensagem mostrada nesses casos passa a explicar isto diretamente — "a autoria de X é obrigatória e só pode ser confirmada com a conta que a criou" — em vez de um genérico "não pode ser confirmada com segurança". Ver `docs/backup-schema.md`.

## [0.45.2] — 2026-08-21

### Added

- **Anos letivos e disciplinas restauráveis (`schema_version = 5`).** O backup passa a transportar os dados reais completos dos anos letivos e das disciplinas referenciados pelo grafo pedagógico. Um restauro autorizado para uma organização vazia pode agora criá-los antes das turmas, períodos, domínios, perfis e relatórios que os referenciam, sem inventar datas, estados, país ou código e sem reutilizar um `ulid` pertencente à organização de origem.

### Fixed

- **A clonagem entre organizações (0.45.1) ficava parcial sempre que a organização de destino estivesse genuinamente vazia.** Turmas, inscrições, períodos letivos e domínios continuavam classificados como inválidos porque dependiam do ano letivo e da disciplina resolverem primeiro — e esses dois, até agora, nunca eram criados, só correspondidos por nome. A pré-visualização mostrava "nada novo para importar" mesmo com a clonagem em si a funcionar corretamente para os domínios que não dependiam deles. Resolvido pela mesma correção acima: turmas, inscrições, períodos e domínios já não precisaram de nenhuma alteração própria — resolvem-se corretamente assim que o ano letivo e a disciplina resolvem.

## [0.45.1] — 2026-08-21

### Added

- **Restauro pedagógico para outra organização, com a origem intacta.** Até agora, restaurar um backup para uma organização diferente da que o gerou classificava tudo como não restaurável assim que a organização de origem ainda existisse — mesmo sendo esse exatamente o cenário de copiar dados pedagógicos autorizados para outra conta. Passa a funcionar: quando o `ulid` de uma linha pertence a outra organização, o plano procura agora, só dentro do destino, uma correspondência pela identidade real dessa linha (a mesma chave que a base de dados já usa para a distinguir — ano+sequência de um período, nome de uma escala, rótulo de uma turma, código de um aluno, e por aí em diante; para o punhado de domínios sem essa chave — elementos de avaliação, registos, estratégias — usa-se a combinação de conteúdo que os identifica de forma equivalente, incluindo o hash já guardado num relatório finalizado ou numa avaliação intercalar). Havendo correspondência, é `existente` ou `conflito`, exatamente como uma linha do mesmo destino; não havendo, é `nova`, restaurada com uma identidade própria gerada de raiz — nunca a da origem. A organização de origem nunca é escrita, nunca é lida senão para esta comparação, e nunca perde nenhum dado. Reimportar o mesmo backup para o mesmo destino continua sem criar duplicados, pela mesma correspondência.

### Fixed

- **A verificação de "este registo já existe noutra organização" não via escalas nem tipos de elemento de outras organizações.** `Scale` e `InstrumentType` protegem a visibilidade dos seus registos de sistema com um âmbito de consulta próprio (`scaleVisibility`, `typeVisibility`), não o âmbito genérico `organization` que a verificação removia explicitamente. O resultado: uma escala ou tipo de elemento próprio de outra organização parecia não existir em lado nenhum, caía no caminho de "genuinamente novo" e tentava gravar-se com o `ulid` da origem — falhando com um erro de base de dados em vez de ser tratado como uma clonagem. Corrigido para remover todos os âmbitos da consulta, não só um nome fixo.
- **Um domínio sem correspondência dentro da mesma organização rebentava a classificação.** A verificação da chave de negócio de um domínio (`BuildAssessmentStructurePlan::classifyDomains()`) referenciava a variável que a construía sem a capturar no closure — um erro de PHP a meio da pré-visualização sempre que havia pelo menos um domínio nessas condições.



### Added

- **Restauro completo do grafo pedagógico (schema v2, `schema_version = 4`).** O backup gerado pelo LÁPIS deixa de se limitar a turmas, alunos e inscrições — passa a transportar também períodos letivos, escalas (e os seus níveis), tipos de elemento, domínios, perfis de avaliação e as suas versões (com os pesos de domínio e de período), elementos de avaliação e itens (com as alocações por domínio), pontuações registadas, o registo completo de classificações, modelos e respostas de autoavaliação, avaliações intercalares, registos pedagógicos, estratégias e medidas (com as suas revisões), e relatórios finalizados. Um professor que perca os dados de uma turma pode agora restaurá-la por inteiro a partir de um backup próprio e continuar a trabalhar exatamente onde ficou — o motor de cálculo (`BuildResultsProgression`, `ClassResultsCalculator`) recalcula os resultados a partir dos factos restaurados, nunca de um valor guardado no backup. Ver `docs/backup-schema.md`.
- **O backup transporta factos, nunca resultados.** Nenhuma média, evolução ou estatística de turma é alguma vez exportada ou importada — só o que um professor ou o sistema já escreveu como facto (uma pontuação, uma decisão de classificação já confirmada, uma resposta de autoavaliação). Isto é um princípio estrutural desta fatia, não um detalhe de implementação: duplicar a lógica de cálculo no formato de backup criaria um segundo sítio para essa lógica divergir do primeiro.
- **Escalas e tipos de elemento de sistema, restaurados só por correspondência.** Cada instalação do LÁPIS semeia a sua própria cópia das escalas e tipos de elemento de sistema, cada uma com o seu próprio `ulid` gerado independentemente — por isso estas nunca são criadas por um restauro, só correspondidas por nome/código à instalação de destino. Escalas e tipos de elemento próprios de uma organização continuam a restaurar-se por `ulid`, como qualquer outro registo.
- **Autoria mapeada só quando inequívoca, nunca inventada.** Cada campo de autoria do backup (quem avaliou, quem confirmou, quem criou um registo) é um email — na importação, só é atribuído ao utilizador que está a confirmar essa mesma importação, e só se o email corresponder. Em qualquer outro caso, o campo fica vazio quando a coluna o permite, ou bloqueia a linha inteira quando não permite — nunca é atribuído a outra pessoa da organização de destino.
- **Pré-visualização de importação, agrupada por Estrutura/Avaliação/Acompanhamento/Documentos.** A página de pré-visualização de um restauro passa a mostrar as novas dezenas de domínios organizadas em quatro grupos pedagogicamente reconhecíveis, com contagens de novos/existentes/conflitos/inválidos/não suportados — em vez de uma lista técnica plana. Pontos a rever (conflitos, dados inválidos, linhas não suportadas) continuam sempre visíveis, nunca escondidos, mesmo quando o domínio em causa não tem linha própria na tabela resumo.

### Fixed

- **Nenhum perfil de avaliação estava a ser restaurado.** `AssessmentProfile` não tinha conversão booleana declarada para `is_institutional_template`; o valor viajava pelo JSON do backup como um inteiro (`0`/`1`) em vez de um booleano, e a validação — que exige estritamente um booleano — rejeitava a linha inteira em silêncio. Como todas as versões de perfil dependem do respetivo perfil já ter sido restaurado, o efeito prático era que nenhuma turma recuperava o seu perfil de avaliação, e por isso nenhum resultado se recalculava depois de um restauro. Corrigido com a conversão em falta.
- **A segunda confirmação de um mesmo backup falhava sempre que havia autoavaliações.** As perguntas de um modelo de autoavaliação já existente no destino perdiam a referência ao modelo antes de chegar à escrita, porque a classificação `existing` desse domínio não a transportava — só a `new` o fazia. Corrigido para a incluir também nas linhas já existentes, que é o caminho que uma segunda importação do mesmo backup sempre segue.
- **Tipos de elemento de sistema rebentavam a escrita.** A construção da chave de correspondência de um tipo de elemento de sistema (`instrument_types`) omitia o código nas linhas devolvidas pelo plano, mas a escrita precisa desse código para reconstruir a mesma chave — todo tipo de elemento de sistema (o caso comum, usado por omissão em qualquer elemento de avaliação) fazia a importação falhar.
- **`applied_on` de um elemento de avaliação nunca era restaurado.** Esta coluna é obrigatória na base de dados e é a data comparada com a inscrição de cada aluno na regra de inscrição tardia (§11.4) — sem ela, nenhum elemento de avaliação com pontuações associadas conseguia sequer ser escrito. Adicionada a toda a cadeia: exportação, validação, plano e escrita.



### Added

- **Restauro e importação segura de backup.** Um professor ou responsável pode carregar um backup gerado pelo próprio LÁPIS (o ZIP completo de uma exportação, ou apenas `backup-lapis.json`) em Configurações → Importar dados, rever uma pré-visualização de tudo o que seria criado, existente, em conflito ou não suportado, e só então confirmar — nada é escrito antes da confirmação explícita. Restaura turmas, alunos e inscrições com a identidade original (`ulid`) preservada, o que torna importar o mesmo backup duas vezes seguro (a segunda vez não cria duplicados). Nunca sobrescreve um registo existente que tenha divergido localmente — fica marcado como conflito, nunca em merge silencioso. Anos letivos e disciplinas em falta no destino bloqueiam apenas a turma que os referencia, com uma mensagem clara em vez de inventar dados; elementos de avaliação e classificações são sempre marcados como não suportados nesta versão, por o formato de backup ainda não transportar os dados necessários para os recriar em segurança. Ver `docs/data-import.md`.
- **Segurança de ficheiro dedicada ao restauro.** O ZIP nunca é extraído para disco — só a entrada `backup-lapis.json` é lida diretamente, depois de validar todas as entradas uma a uma (limite de 500 entradas, 20 MB por entrada, 50 MB no total, e rejeição de qualquer nome de entrada com travessia de diretório ou caminho absoluto). Um leitor separado varre o conteúdo à procura de qualquer chave com forma de segredo (password, token, 2FA, passkey, SMTP, etc.) a qualquer profundidade e rejeita o ficheiro inteiro se encontrar alguma — antes mesmo de qualquer campo ser lido para a base de dados.
- **Limitação documentada: identidade global de `ulid`.** `classes.ulid`, `students.ulid` e `enrollments.ulid` são únicos em toda a base de dados, não por organização — restaurar para uma organização diferente da de origem só cria registos novos se a origem já não os tiver. O plano de importação deteta isto na pré-visualização (classificando a linha como inválida, com uma mensagem explícita) em vez de deixar a escrita falhar com um erro SQL bruto.
- `php artisan data-imports:prune`, agendado de hora a hora, fecha restauros por confirmar passado o prazo de expiração e limpa o ficheiro carregado e quaisquer ficheiros órfãos — nunca os dados já restaurados.
- `GenerateDataExport` passa a `schema_version = 3`: cada linha de `enrollments` no backup transporta agora `enrolled_on`, `left_on` e `class_number`, o mínimo necessário para recriar uma inscrição sem inventar uma data. Backups `schema_version = 2` já emitidos continuam legíveis; linhas que precisariam de `enrolled_on` para serem criadas ficam marcadas como não suportadas em vez de inventarem a data em falta.
## [0.43.0] — 2026-08-21

### Added

- **Encerramento recuperável de conta pessoal (60 dias).** Um utilizador pode pedir o encerramento da própria conta em Configurações — a conta fica disponível para recuperação e exportação durante 60 dias; pode reativá-la a qualquer momento dentro desse prazo. Ao fim do prazo, fica apenas **elegível** para eliminação — nada é apagado automaticamente. Bloqueado se a pessoa for responsável por alguma organização institucional ativa, até transferir a responsabilidade primeiro. Ver `docs/account-closure.md`.
- **Encerramento recuperável de organização institucional (90 dias).** O responsável (owner) pode pedir o encerramento da organização — membros, responsável e todos os dados pedagógicos permanecem intactos; só fica bloqueada nova atividade de escrita durante a janela. Qualquer membro vê um aviso, mesmo sem acesso à página de Equipa; só o responsável pode pedir ou cancelar.
- **Bloqueio de atividade normal durante o encerramento.** Novo middleware que permite sempre leitura, e durante uma janela de encerramento ativa restringe escrita a um conjunto reduzido de ações seguras (cancelar o encerramento, exportar dados, terminar sessão, mudar de organização, gerir password/2FA/passkeys). Nunca afeta outras organizações da mesma pessoa nem a organização pessoal.
- **Pré-visualização de elegibilidade para retenção, só de leitura.** `php artisan retention:status` (com `--json`) lista contas e organizações em encerramento com dias restantes, exportações expiradas por limpar, anos letivos fora da janela pedagógica por organização, e a contagem de eventos de auditoria fora da janela de segurança — nunca apaga nada. Visibilidade equivalente, também só de leitura, na ficha de conta do backoffice.
- **Estrutura do Ano Letivo, centralizada em Configuração.** Nova entrada na barra lateral que reúne a gestão de Anos letivos e Disciplinas (já existente, sem alteração de rotas, controllers ou policies) sob um único separador. O seletor de contexto no topo continua a permitir escolher ano letivo/disciplina, mas deixa de ser o único caminho até à sua gestão — e mostra "Sem ano letivo configurado" / "Sem disciplinas configuradas" em vez de um traço vazio quando não existe nenhum. Ver `docs/academic-structure.md`.

### Fixed

- **`data-exports:prune` falhava sempre a partir do scheduler.** Agendado de hora a hora desde a Fatia 4, este comando lançava `TenantNotResolvedException` sempre que corria fora de um pedido HTTP — ou seja, sempre que o scheduler o disparava — porque a sua query de limpeza estava scoped à organização, mas nenhuma organização é resolvida num contexto de consola. O teste existente não apanhou isto por correr logo a seguir a um pedido HTTP simulado, que deixava um tenant "preso" na mesma execução. Corrigido para operar explicitamente entre organizações (`withoutGlobalScope('organization')`), com um novo teste que reproduz a condição real do scheduler.
- **Eliminar a própria conta ressuscitava-a.** `ProfileController::destroy()` invalidava a sessão antes de terminar; ao mover essa ordem para acomodar a nova guarda de encerramento institucional, `Auth::logout()` — chamado sobre a mesma instância que acabara de ser apagada — voltava a inserir a linha ao ciclar o remember token num modelo já marcado como não existente. Corrigido operando sobre uma cópia distinta.
- **Apagar a própria conta sendo responsável por uma instituição só falhava com um erro SQL cru.** `DeleteUserAccount` deixa agora uma mensagem explícita — "transfira a responsabilidade primeiro" — em vez de deixar a restrição de chave estrangeira ser a primeira UX.

### Changed

- O prop partilhado `scope.academicYear` (seletor de contexto no topo) deixou de estar permanentemente vazio — reflete agora o ano letivo atual da organização, pela mesma heurística já testada que os relatórios de retenção usam.

## [0.42.6] — 2026-08-20

### Fixed

- **Criação de turma pouco descoberta na validação manual.** A página Turmas já tinha "Nova turma" como botão principal no topo — mas o estado vazio ("ainda sem turmas") mostrava só texto, sem ação clicável. Passa a ter também um botão "Criar a primeira turma", reutilizando exatamente a mesma rota/formulário/validação/action que "Nova turma" já usa — nenhum fluxo novo. Auditado o seletor de contexto no topo (Ano letivo/Disciplina/Ano/Turma/Período): não tem nenhuma ação de criação — os seletores "Ano", "Turma" e "Período" estão simplesmente desativados (fase futura), nada a remover aí.

## [0.42.5] — 2026-08-20

### Changed

- **A exportação de dados passa a produzir um workbook Excel legível, em vez de CSVs técnicos.** Validação manual encontrou os CSV difíceis de interpretar no Excel — colunas com ids técnicos, sem formatação. `Exportacao-LAPIS.xlsx` substitui os CSV: uma folha "Resumo" (sempre presente) mais uma folha por domínio com dados (Turmas, Alunos, Elementos de Avaliação, Avaliações, Classificações, Autoavaliações, Estratégias e Medidas, Registos, Relatórios) — só as que fizerem sentido para os dados existentes. Cabeçalhos em PT-PT, primeira linha fixa, filtro automático, larguras ajustadas, datas como datas Excel, números como números.
- **Nomes em vez de identificadores técnicos.** Nenhuma folha mostra `organization_id`, `class_id`, `student_id` ou equivalente como coluna principal — os dados são resolvidos para Organização, Turma, Aluno, Disciplina, Ano letivo, etc.
- **Terminologia igual à do resto do LÁPIS.** "Elementos de Avaliação", "Estratégias e Medidas", "Média Ponderada", "Proposta", "Classificação atribuída" — os mesmos termos já usados nas páginas de Classificações e Estratégias, nunca "Instrumentos", "Intervenções" ou "Resultado" genérico. A Média Ponderada e a Proposta são lidas dos mesmos serviços que a página de Classificações já usa (`BuildResultsProgression`, `ScaleProposalResolver`), chamados uma vez por turma — nunca recalculadas nem uma fórmula paralela.
- **`backup-lapis.json` substitui `manifest.json`** como o backup técnico estruturado (ids estáveis, relações, pronto para uma futura importação) — não se destina a leitura direta; o XLSX é o documento para isso.

### Security

- Sem alteração de scope: o XLSX só contém o que o utilizador já podia exportar — as próprias turmas (`class_teachers`), nunca o trabalho pedagógico de colegas, mesmo para o responsável institucional.

## [0.42.4] — 2026-08-20

### Fixed

- **Ações da página Equipa eram só ícones, sem explicação.** Na validação manual foi preciso adivinhar o que "Transferir responsabilidade" e "Remover da organização" faziam. Os dois botões passam a mostrar o texto completo junto do ícone (com `aria-label` e `title` PT-PT), em vez de depender só do ícone — não fica dependente de hover, funciona em ecrãs táteis, e não obriga a adivinhar.
- Copy do diálogo de confirmação de transferência ajustada ao texto sugerido: "Vai transferir a responsabilidade da organização para {Nome}. Deixará de ser responsável e continuará como membro da organização."

## [0.42.3] — 2026-08-20

### Fixed

- **"Transferir responsabilidade" na página Equipa não tinha confirmação forte.** Substituído o `confirm()` genérico do browser pelo componente `Dialog` já usado em "Sair da organização" e "Delete account" — indica o nome de quem vai passar a responsável, que o utilizador atual deixa de o ser e continua como membro. Clicar no ícone só abre o diálogo; só o botão "Transferir responsabilidade" dentro dele envia o pedido. Proteção contra duplo-clique preservada.

## [0.42.2] — 2026-08-20

### Fixed

- **Transferência de responsabilidade redirecionava o antigo responsável para uma página que já não podia ver.** A transferência em si sempre funcionou corretamente (confirmado pelo próprio registo de auditoria de produção) — o bug estava no destino do redirecionamento: `TeamController::transferOwnership` enviava sempre para `/team`, uma página só para o responsável (`OrganizationInvitationPolicy::viewAny`). Quem acabou de transferir a responsabilidade deixa de o ser nesse preciso instante, por isso a página seguinte devolvia sempre 403 — uma transferência bem-sucedida parecia ter falhado. Corrigido para redirecionar para `dashboard`, o mesmo destino já usado por `OrganizationController::switch()` e `OrganizationMembershipController::leave()` exatamente por esta razão.
- Botões "Transferir responsabilidade" e "Remover da organização" na página Equipa ganham proteção contra duplo clique.

## [0.42.1] — 2026-08-20

### Added

- **Fatia 4 — UI de governação de membros e exportação.** Página Equipa ganha "Remover da organização" e "Transferir responsabilidade" por membro, com confirmação forte; página de definições de perfil ganha "Sair da organização" (ou a instrução para transferir primeiro, se for o responsável) e a ligação para "Exportar os meus dados"; nova página "Turmas a Reatribuir" no grupo Instituição, com seleção de um membro atual por turma órfã.

## [0.42.0] — 2026-08-20

### Added

- **Fatia 4 — saída e governação de membros.** Um membro pode sair de uma organização institucional, o responsável pode remover outro membro e pode transferir a responsabilidade para um membro atual. As três mutações de governação recusam sessões de suporte impersonadas e deixam rasto de auditoria.
- **Reatribuição de turmas sem professor.** Turmas em preparação ou ativas sem qualquer linha em `class_teachers` são detetadas como estado derivado, sem coluna nem migração, e podem ser atribuídas pelo responsável a um membro atual da mesma organização.
- **Exportar os meus dados.** Qualquer conta, em qualquer plano, pode pedir uma cópia ZIP dos dados a que tem acesso — as próprias turmas e o que lhes está associado, nunca o trabalho pedagógico de colegas. Um responsável institucional recebe adicionalmente a lista da equipa e o registo de auditoria a que já tinha acesso, nunca dados pedagógicos nominais extra. Gerado de forma síncrona para `storage/app/private`, nunca público; fica disponível 24 horas e é depois removido por `data-exports:prune` (agendado a cada hora). Inclui `manifest.json` e um `README.txt` que enumera o que nunca é incluído (password, 2FA, passkeys, tokens de sessão/convite, segredos de configuração).
- **Arquitetura de política de conservação de dados.** `config/retention.php` fixa os alvos de retenção (dados pedagógicos: ano letivo atual + 3 anteriores; conta pessoal encerrada: 60 dias; organização institucional encerrada: 90 dias; logs técnicos: 90 dias; auditoria: 3 anos; backups técnicos: 30–60 dias; exportações: 24 horas), lidos por `App\Support\Retention\RetentionPolicy`. `AcademicYearRetentionClassifier` conta sempre em anos letivos (ordenação por `starts_on`), nunca por `created_at`, e nunca marca nada como "elegível para eliminação" — só `within_retention`. `ClosureRetention` implementa a fronteira de recuperabilidade (estritamente "menor que"), pronta a ligar a um futuro fluxo real de encerramento — nenhuma coluna nova foi criada para isso.
- Cobertura feature explícita para isolamento entre organizações, manutenção de contas e memberships não relacionadas, transferência imediata de autoridade, reatribuição com e sem co-docente, preservação de alunos/inscrições/evidências/autoria histórica, segurança da exportação (ficheiro privado, sem segredos, negado a quem não pediu) e fronteiras exatas da política de retenção.

### Security

- Saída e remoção retiram apenas as atribuições atuais em `class_teachers` da organização afetada. Nenhum aluno, inscrição ou registo pedagógico é apagado e nenhum campo histórico `*_by`/`causer_id` é reescrito.
- `DataExportPolicy` nega o download a qualquer pessoa que não seja quem pediu a exportação, mesmo dentro da mesma organização — o responsável institucional não pode descarregar a exportação de outro membro.

### Não incluído nesta fatia

- Nenhum job de purga, anonimização ou eliminação de dados reais além da limpeza do próprio ZIP de exportação expirado. Nenhuma coluna de encerramento de conta/organização. Nenhuma role intermédia, resolução de duplicados de alunos, ou alteração a fórmulas/resultados/planos.

## [0.41.1] — 2026-08-20

### Investigado

- **Suposta regressão no fluxo público de convites (Fatia 3).** Reportado um convite válido a ser recusado num `GET /invitations/{token}` sem sessão. Auditados a rota, o controller, `AcceptOrganizationInvitation::findByToken()` e o scope de tenancy em `OrganizationInvitation` — sem defeito encontrado: o lookup por `token_hash` já ignora corretamente o scope de organização antes de qualquer tenant estar resolvido, e um `GET` simples nunca aceita o convite (confirmado ao vivo: o token efetivamente enviado resolveu corretamente e o registo ficou por aceitar). A causa foi um erro de transcrição do token de 64 carateres no relato (`0`↔`O`, `M`↔`m`), não um bug de código.

### Added

- Teste de regressão explícito (`a_bare_get_with_no_session_never_accepts_the_invitation`) que fixa em código o comportamento verificado: um `GET` sem sessão a um convite válido nunca marca `accepted_at`/`cancelled_at` nem cria conta ou membership.

## [0.41.0] — 2026-08-20

### Added

- **Fatia 3 — convites e equipa institucional.** O responsável de uma organização institucional passa a convidar pessoas por email para a organização, em vez de precisar do backoffice do platform admin para cada membro.
- **Página Equipa**, só para o responsável — lista os membros atuais e os convites pendentes, e tem o formulário para convidar. Convidar o mesmo email outra vez renova o convite existente em vez de duplicar; o link anterior deixa de funcionar. Cancelar um convite pendente marca-o, não o apaga — fica no histórico.
- **O link do convite funciona com conta existente ou nova, sem duplicar o registo.** Alguém já com conta é levado a entrar; alguém sem conta é levado a criá-la — reutilizando o registo do Fortify tal como está, com a password sempre escolhida pela própria pessoa e nunca enviada por email. Nos dois casos, ao terminar, a pessoa junta-se automaticamente à organização e é levada ao Painel do Professor já nesse contexto.
- **A prova de acesso ao email não se pede duas vezes.** Quem chega pelo link do convite já demonstrou controlar essa caixa de correio; a verificação de email do Fortify é dada como cumprida nesse preciso caso, em vez de pedida de novo.
- Um utilizador autenticado com um email diferente do convidado nunca aceita em silêncio — vê uma página clara a explicar a situação, com a opção de terminar sessão para abrir o link com a conta certa.

### Changed

- A organização institucional passa a ter membros para além do responsável através deste caminho — o backoffice da Fatia 2 mantém-se disponível para o platform admin, mas deixa de ser o único.

### Não incluído nesta fatia

Remover membro, sair da organização, transferir propriedade, coordenador/direção ou qualquer papel intermédio — ficam para a Fatia 4.

## [0.40.0] — 2026-08-20

### Added

- **Fatia 2 — organização institucional e multiutilizador base.** Infraestrutura mínima para uma organização ter mais do que um utilizador, sem ainda construir convites, página Equipa ou papéis intermédios.
- **O backoffice cria organizações institucionais.** A mesma página «Nova conta» ganha a escolha entre Pessoal e Institucional. O responsável pode ser um utilizador novo (que fica também com a sua própria organização pessoal, como qualquer conta nova) ou um utilizador já existente (a sua organização pessoal mantém-se intocada). Criação atómica: organização, dono no `owner_id` **e** no pivot de membership, e subscrição — nunca um dono sem membership, que é o que impedia a Fatia 1 de ter algo real para gerir.
- **Gestão mínima de membros no backoffice.** A ficha de uma organização institucional ganha um bloco «Membros» — nome, email, Responsável/Membro, estado — e um formulário para o platform admin adicionar um utilizador já existente por email. Não é um convite: sem token, sem aceitação. Pertencer à organização pessoal e a uma institucional ao mesmo tempo é o suporte para isto.
- **Seletor de organização.** Quem pertence a mais do que uma organização vê, no menu do utilizador, a lista de organizações com um indicador da atual; quem só tem uma continua sem ver nada de novo. Trocar (`POST /organizations/switch`) exige pertencer à organização alvo — verificado no servidor, nunca só pela UI — e leva sempre ao Painel do Professor, nunca a uma página com um id da organização anterior.

### Changed

- **Módulos e plano passam a acompanhar a organização selecionada, não a sessão de login.** Ao trocar de organização, os `modules` e o menu lateral recalculam-se a partir da subscrição da organização atual — confirmado sem logout entre uma organização Base e uma Pro/Institucional.
- O predicado de subscrição inicial (`CreatePersonalOrganization`) foi extraído para `SubscribeOrganization`, partilhado agora com a criação institucional — a mesma regra escrita uma só vez, não duplicada.

### Não incluído nesta fatia

Convites por email, página Equipa, remoção ou saída de membro, transferência de propriedade, papéis coordenador/direção — ficam para as Fatias 3 e 4.

## [0.39.0] — 2026-08-20

### Added

- **Fatia 1 — segurança multiutilizador.** Prepara o que acontece no dia em que uma organização tiver mais do que um membro, sem ainda construir convites, equipa ou seletor de organização — só as duas regras de fronteira que têm de existir antes de qualquer uma dessas coisas.
- **Registo de atividade: um membro vê só os seus próprios eventos; o responsável da organização vê o registo inteiro.** Até aqui `/activity` mostrava os últimos 100 eventos da organização a qualquer pessoa autenticada, sem filtro. Passa a aplicar `causer_id = utilizador` para um membro normal, e sem filtro para quem é dono (`organizations.owner_id`) — porque o registo de atividade é uma função de segurança institucional, não um diário pessoal. Numa organização pessoal o resultado não muda: há sempre uma só pessoa, por isso as duas regras devolvem os mesmos eventos.
- **Configuração partilhada passa a ter dono.** Anos letivos, disciplinas, perfis de avaliação e escalas não têm variante "pessoal" — pertencem sempre à organização inteira. Um membro continua a lê-los e a usá-los (uma turma precisa de um perfil de avaliação para ser avaliada); só o responsável da organização os cria, edita ou apaga. Numa organização pessoal, o dono é a única pessoa que lá está — nada muda para quem usa o LÁPIS sozinho hoje.
- Nas páginas afetadas, os controlos de criar/editar/apagar deixam de aparecer a um membro sem essa permissão, em vez de aparecerem e falharem com 403 ao serem usados. A autorização em si continua só no servidor — a UI só evita o percurso morto.

### Changed

- `OrganizationPolicy`, `ReportPolicy` e `ReportTemplatePolicy` já implementavam exatamente esta regra (ler é de todos, escrever é do dono); o predicado que cada uma duplicava à sua maneira foi extraído para `User::owns()`/`ownsCurrentOrganization()`, para as quatro policies novas apontarem ao mesmo sítio.

## [0.38.4] — 2026-08-20

### Changed

- **A secção «Apagar definitivamente» deixa de falar em desativar "a conta".** Depois de separar utilizador, subscrição e eliminação no resto da ficha (0.38.3), esta frase continuava a misturar os três sob um único "conta". Passa a nomear as duas peças certas: desativar um **utilizador** bloqueia a pessoa; a eliminação definitiva é a exceção, só possível sem dados associados. Sem alteração à regra de bloqueio, ao endpoint `DELETE` ou ao que conta como dependência.

## [0.38.3] — 2026-08-20

### Changed

- **A ficha da conta no backoffice separa visualmente o que é da pessoa, o que é administrativo e o que é da organização.** No bloco «Dono», os campos de nome/email passam a ter o seu próprio subtítulo («Dados do utilizador»), e as ações de suporte — verificar email, promover a admin, aceder como utilizador, desativar — ficam num bloco «Ações administrativas» separado, em vez de tudo misturado sob um único título. Sem alteração a rotas, validação, `email_verified_at`, `deactivated_at`, guardas ou lógica de subscrição — só layout e texto.
- **Nomes de botão mais precisos sobre o que cada ação afeta.** «Guardar dados» → «Guardar alterações»; «Impersonar (suporte)» → «Aceder como utilizador» (com explicação em tooltip); «Desativar conta» / «Reativar conta» → «Desativar utilizador» / «Reativar utilizador»; «Suspender» / «Reativar» da subscrição → «Suspender subscrição» / «Reativar subscrição». A distinção que já existia — desativar tira o acesso a uma pessoa, suspender tira o produto à organização — fica explícita nos próprios rótulos, não só no texto de apoio por baixo.

## [0.38.2] — 2026-08-20

### Fixed

- **O formulário de editar utilizador ganha um rótulo próprio e feedback ao guardar.** O código já existia desde a Fatia 0, mas os assets de produção em `public/build` tinham sido compilados antes desse commit — quem validasse em `http://lapis.test` via build estático via a versão anterior, sem nenhuma das alterações da Fatia 0. Corrigido reconstruindo os assets. Aproveitado para tornar o controlo explícito («Editar utilizador», acima dos campos, em vez de inputs soltos) e para o guardar deixar de ser mudo: passa a mostrar a mesma notificação de sucesso que criar ou apagar uma conta já mostram.

## [0.38.1] — 2026-08-20

### Fixed

- **A lista de contas não tinha botão para criar uma conta.** A rota `/admin/accounts/create` sempre existiu, mas só se lá chegava a escrever o URL à mão — a página nunca teve o link. `/admin` ganha "Nova conta".

## [0.38.0] — 2026-08-20

### Added

- **O backoffice passa a gerir contas, não só a criá-las.** Até aqui o Superadmin provisionava uma conta e ficava sem forma de a editar. `/admin/accounts/{organização}` ganha um formulário para corrigir nome e email — mudar o email repõe a verificação a zero, e a conta segue o fluxo normal a partir daí. Fica registado em auditoria (`admin.user_updated`).
- **Desativar uma conta é diferente de suspender uma subscrição, e agora ambos existem.** Suspender (já existia) tira o plano à organização inteira; desativar (novo) tira a uma pessoa a capacidade de entrar, sem tocar no plano — a distinção que passa a fazer sentido no dia em que uma organização tiver mais do que um membro. Nova coluna `users.deactivated_at`. O bloqueio corre por middleware (`EnsureUserIsActive`) e por `Fortify::authenticateUsing`, para apanhar as três portas de entrada: o formulário de login, uma sessão já aberta no momento da desativação, e passkeys/"lembrar-me". Um administrador não se consegue desativar a si próprio nem ao último admin da plataforma ainda ativo.
- **Remoção definitiva, excecional e protegida.** `DELETE /admin/accounts/{organização}` só avança numa organização pessoal, sem dados pedagógicos associados (turmas, alunos, inscrições, elementos de avaliação, registos, intervenções, relatórios, perfis de avaliação) e sem rasto de auditoria — o que, na prática, só acontece numa conta que nunca chegou a ser usada. Havendo qualquer coisa, recusa com a lista do que bloqueia; nunca apaga histórico para o delete passar. Uma organização institucional nunca é apagada por aqui.
- A lista de contas (`/admin`) e a ficha de cada uma passam a mostrar o estado de acesso da pessoa — separado do estado da subscrição, que já lá estava.

## [0.37.1] — 2026-08-20

### Fixed

- **As mensagens de erro das palavras-passe deixam de ser códigos.** Quem definia uma palavra-passe via «validation.password.mixed» ou «validation.password.uncompromised» — o identificador interno da mensagem, não a mensagem. Faltavam ao ficheiro de português **47 mensagens de validação**, entre elas todas as das regras de palavra-passe. Agora dizem o que corrigir: «A palavra-passe tem de conter pelo menos uma letra maiúscula e uma minúscula», ou «Esta palavra-passe já apareceu numa fuga de dados pública e não pode ser usada. Escolha outra — de preferência várias palavras sem relação entre si.»
- **Os requisitos aparecem antes de falhar, não depois.** As páginas de criar conta e de definir nova palavra-passe passam a listar o que é exigido — comprimento, maiúsculas e minúsculas, algarismo, símbolo — em vez de os revelar uma recusa de cada vez. A lista é gerada a partir das regras reais do servidor, por isso não pode divergir delas.
- **As sete páginas de autenticação passam a estar em português.** Entrar, criar conta, recuperar e definir palavra-passe, confirmar palavra-passe, autenticação em dois passos e verificação de e-mail estavam em inglês, como vieram do template original.

## [0.37.0] — 2026-08-20

Três módulos novos — Relatórios, Acompanhamento do Aluno e a área de ação
pedagógica reescrita — mais a reorganização da navegação que os passou a
arrumar. Nenhuma fórmula de avaliação mudou.

### Added

#### Relatórios

- **Um relatório passa a ser um objeto da aplicação, não um download.** É criado, editado, finalizado, exportado, listado e reutilizado. Um relatório derivado de outro herda o que já foi escrito e mantém a referência ao original.
- **Relatório de turma**, com as secções que descrevem os dados: quem é a turma, a leitura principal, a distribuição das classificações atribuídas, os domínios, a evolução, o diário de bordo, o que o professor declarou sobre a planificação, as intervenções registadas e um fecho. Cada frase nomeia a leitura de que fala — uma percentagem sem a sua base não é um facto.
- **Relatório individual**, construído a partir da mesma leitura da turma. O número do aluno e o da turma saem do mesmo cálculo, por isso «72,1%, acima da média da turma (66,4%)» são dois números do mesmo momento e não podem discordar entre si.
- **Relatório por registo de diário**, com a unidade nunca convertida. «10 verificações de trabalho de casa, realizadas em 8 registos» é uma afirmação sobre verificações; transformá-la numa percentagem de alunos seria dizer outra coisa. Cada contagem leva o seu denominador em texto.
- **Relatório institucional** (plano Institucional), que se recusa a juntar escalas incompatíveis. «1 a 5» e «0 a 20» não são um eixo comum: as classificações são agrupadas por escala, cada grupo tem o seu parágrafo e a sua taxa, e não há total nenhum na página. As «Notas de comparabilidade» dizem sempre o que não pôde ser comparado.
- **Camada pedagógica — perguntada, nunca inferida.** O LÁPIS guarda ocorrências disciplinares e registos de mérito; não guarda comportamento nem atitude. As secções que caracterizam comportamento, nomeiam dificuldades ou propõem medidas escrevem a partir das respostas do professor, e de nenhum dado.
- **Finalizar congela o documento.** Ao finalizar, texto, estrutura, números, timbre, âmbito temporal e as respostas do professor são copiados para dentro do relatório e selados. Uma nota corrigida em maio, um logótipo trocado ou o nome oficial do agrupamento reescrito não alteram o relatório assinado em fevereiro — reimprimi-lo não corre uma única consulta.
- **Exportação em PDF e Word**, as duas a partir da mesma estrutura final. Um rascunho sai carimbado RASCUNHO em ambos os formatos.
- **Modelos de relatório.** Um modelo guarda a organização de um documento — que secções entram, por que ordem, com que tom — e nunca o seu conteúdo. Há modelos do sistema, da escola e pessoais; um relatório pode começar a partir de um, e um rascunho pode ser guardado como modelo.
- **Reordenar as secções de um rascunho**, com o rato ou pelo teclado.
- **Biblioteca pedagógica** de dificuldades, estratégias e objetivos, que sugere sem preencher: o que o professor escolhe é copiado no momento da escolha, por isso reescrever uma entrada em setembro não muda o que um relatório de fevereiro diz.
- **Análise Pedagógica nos Relatórios** é uma capacidade nova dos planos Pro e Institucional. As secções descritivas continuam no plano Base — o que distingue os planos é o relatório poder *interpretar*.

#### Acompanhamento do Aluno

- **O ano de um aluno contado como uma história, não como um painel.** Onde está → como evoluiu → em quê → o que aconteceu, que é a ordem das perguntas que um professor faz. Quatro indicadores no topo e nem mais um, cada um a dizer a que leitura pertence.
- **Nada é recalculado.** A página lê os números canónicos que já existem, junta o percurso por período e as autoavaliações, e escolhe — não há motor de cálculo novo, logo não há forma de discordar do resto da aplicação.
- **A ficha do aluno mostra as suas intervenções** e permite abrir uma nova sobre o aluno que está a ser lido, sem procurar a turma.

#### Estratégias e Medidas

- **Uma intervenção passa a registar o raciocínio, não só a ação:** porquê, o quê, para quê, e o que se observou depois. Nada disto é obrigatório — registar algo pequeno continua tão rápido como era, e uma intervenção antiga mostra «sem objetivo registado» em vez de um objetivo inventado.
- **Acompanhar uma intervenção ao longo do tempo.** «+ Acompanhamento» acrescenta ao histórico sem editar a intervenção: cada entrada é datada e lida para a frente — iniciada, acompanhada, avaliada, concluída. Funciona no telemóvel.
- **«Rever em»**, a data do próprio professor, e a única coisa que torna uma intervenção pendente. Nenhuma regra a inventa a partir do tempo decorrido.
- **Dois estados novos:** «suspensa» (pausada, pode retomar) é diferente de «cancelada» (abandonada), e uma apreciação pode dizer «necessita de reformulação».

#### Apoio à redação (opcional, por configurar)

- **Um botão que oferece dizer melhor uma secção, e não escreve nada de novo.** Diz «Aperfeiçoar redação» e não «Gerar com IA», porque as frases foram escritas a partir de dados verificados antes de alguém clicar. Uma secção de cada vez, a proposta aparece ao lado do parágrafo que substituiria, e «Manter atual» é um botão a sério.
- **Sem fornecedor escolhido, deliberadamente.** O LÁPIS é instalado com esta camada desligada e sem nenhuma empresa nomeada: não há motor, endereço nem modelo por omissão. Enquanto não for configurada por quem administra a instalação, a funcionalidade anuncia-se como indisponível — e os Relatórios funcionam inteiramente sem ela. Os factos nunca vêm daqui: só a redação.

### Changed

- **O menu passou a estar ordenado pelo trabalho do professor** — organizar, avaliar, acompanhar, intervir, documentar — e não pela ordem em que foi construído. As áreas transversais ficam em baixo.
- **«Instrumentos» é agora «Elementos de Avaliação»** e **«Intervenções» é agora «Estratégias e Medidas»**: a segunda área é mais larga do que as medidas formais — abrange diferenciação, apoio ao estudo, apoio à interpretação de enunciados e estratégias de autorregulação. Os endereços não mudaram.
- **Cada entrada do menu diz para que serve**, em tooltip e no nome lido por um leitor de ecrã, antes de o professor clicar.
- **«Resultados» deixou de ser um sítio para onde se navega.** A pergunta é «como está esta turma?»: o seletor abre agora a leitura da turma, e a grelha operacional onde se decide uma classificação está a um clique dela. Nenhuma rota foi removida.
- **Os títulos das secções da barra lateral usam o amarelo da marca**, discreto e mais legível do que o cinzento que substituem.

### Fixed

- **Intervenções importadas antes de o módulo ter tipos deixam de imprimir o que a importação deixou para trás.** Um título «Legado sem dominio» e uma descrição «x» estão genuinamente guardados, mas numa lista leem-se como uma categoria e uma observação pedagógicas, que não são.
- **Um relatório só explica a diferença entre registos e alunos quando ela existe.** Dizer «8 registos sobre 8 alunos» é ruído; dizer «10 verificações em 8 registos» é informação.
- **A pré-visualização da identidade da escola deixa de ser espremida** para uma coluna estreita nas Definições.
- Os relatórios deixaram de fazer perguntas cujas respostas nunca chegariam a ser impressas.

## [0.36.0] — 2026-08-19

### Definições → Identidade da escola

- **A escola passa a poder identificar-se uma vez.** Nome oficial, nome curto, morada, código postal, localidade, telefone, email e website, mais os dados administrativos opcionais — código da escola, NIF, departamento, país e um texto de rodapé.
- **Logótipo com carregamento, substituição e remoção.** PNG, JPG ou WebP até 2 MB; o ficheiro fica em armazenamento privado e só é servido a quem pertence à organização. Substituir apaga o anterior.
- **Nada é obrigatório.** Uma escola que hoje só sabe o nome guarda o nome; um campo deixado em branco fica por preencher e não como texto vazio. O que é escrito é guardado tal como foi escrito.
- **Pré-visualização do cabeçalho.** Mostra exatamente o que um documento vai imprimir, sem linhas vazias quando faltam contactos, e diz quando ainda não há nada configurado.
- **Quem administra a organização altera; quem lá trabalha vê.** Uma escola nunca lê nem sobrepõe a identidade de outra.
- **Preparação para os documentos.** A identidade fica disponível num ponto único para que os relatórios e exportações futuros a recebam já pronta, em vez de cada um voltar a pedir estes dados. Esta versão não gera ainda nenhum documento.

## [0.35.0] — 2026-08-18

### Resultados → Estatística: uma visão geral de desempenho

- **A turma lida como um todo.** Uma página nova que agrega o que Resultados e o Quadro Síntese já mostram — médias, distribuição, domínios, evolução — sem recalcular nada: os números são os mesmos, apenas contados e agrupados.
- **Quatro indicadores no topo**: o resultado da turma, a taxa de sucesso, quantos alunos têm resultado e como evoluiu. Cada um diz sobre quantos alunos foi calculado, porque uma percentagem sem a sua base é um número que ninguém pode conferir.
- **Gráficos com linguagem própria.** A forma segue os dados — um momento é um número, dois são duas pontas, três ou mais ganham série — e o Chart.js só é carregado quando é mesmo preciso.
- **Onde a turma se espalha, e o mapa da turma.** O valor está sempre escrito; a cor só o reforça. Escolher um domínio ou uma classificação realça esses alunos em toda a página, sempre com forma de limpar a seleção.

### Avaliação contínua: qual é o resultado do momento

- **A leitura principal deixa de ser sempre a média isolada do período.** No primeiro momento do ano, a Média Ponderada do período é o resultado; a partir daí é a Média Ponderada Acumulada, que é o que traduz a avaliação contínua e aquilo que a classificação acompanha.
- **A regra vem do perfil, não do calendário.** É lida da configuração de continuidade de cada período, por isso funciona com semestres, trimestres ou períodos, e uma escola cujo segundo período não acumule vê a leitura ajustar-se sozinha.
- **A média isolada continua disponível** como leitura suplementar, com um comutador «Avaliação contínua / Só neste período» que muda os resultados apresentados — e nunca as classificações nem a taxa de sucesso.
- **Duas evoluções, com nomes diferentes.** «Evolução do desempenho» compara o trabalho de cada período; «Evolução na avaliação contínua» compara o resultado que respondia em cada momento. Um aluno pode cair vinte pontos como período e cinco como ano: as duas frases são verdadeiras e não se substituem uma à outra.

### A classificação atribuída é a fonte oficial

- **Taxa de sucesso, distribuição e mudanças de nível contam as classificações que o professor atribuiu** — não as menções em que as médias calhem cair. Uma média em «Bom» com nível 2 atribuído conta como negativa.
- **Uma proposta não é uma decisão.** Só uma classificação confirmada ou publicada conta; quem ainda não foi classificado fica à parte e fora dos denominadores, nunca como insucesso.
- **A distribuição mostra o valor da classificação.** «2», «3», «4» em destaque, com a menção da escala por baixo como referência. Numa escala numérica agrupa pelos valores efetivamente atribuídos, sem inventar intervalos.
- **A distribuição estatística das médias mantém-se**, agora recolhida e claramente identificada como leitura secundária.

### Quem progrediu, e quem mudou de nível

- **Duas leituras separadas.** Quantos alunos progrediram, mantiveram-se ou regrediram nos resultados calculados; e quantos passaram para um nível igual ou superior ao limiar da escala, ou abaixo dele.
- **A linguagem vem da escala.** «Passaram para nível igual ou superior a 3» em vez de «passaram a positivo» — e o número vem da configuração, não do código: uma escala 0–20 com o limiar em 10 diz 10.
- **Progredir e ser positivo são coisas diferentes**, e passam a ter visuais distintos: um aluno pode descer e continuar positivo, ou subir e continuar negativo.

### Avaliações intercalares

- **«Dados até»**: ver a turma como estava numa data, sem gravar nada.
- **Guardar esse momento como Avaliação intercalar** — uma fotografia imutável, com nome próprio, listada e reabrível.
- **Comparar a intercalar com o final do período**, aluno a aluno e domínio a domínio, na leitura que o período usa: acumulado contra acumulado quando há continuidade, com o desempenho isolado por baixo.
- **Exportar para INOVAR a partir da própria fotografia**, com os códigos que a escala tinha nessa data.
- **O passado não se reescreve.** Uma fotografia antiga que não registou determinada leitura diz «—» em vez de a ir buscar aos dados de hoje.

### Importação de turmas: a coluna SIT

- **Os cinco códigos passam a ser reconhecidos**: X (matriculado), TR (transferência), MT (mudou de turma), AM (anulou matrícula) e EF (excluído por faltas). Antes só TR era interpretado e os restantes entravam como matriculados.
- **A reimportação corrige o estado.** Um aluno que passa a MT deixa de constar da turma corrente; se voltar a X, regressa — sem duplicar matrícula nem aluno.
- **Um código desconhecido ou vazio não altera nada** e é assinalado na pré-visualização, que passa a mostrar «MT — Mudou de turma» e a indicar a mudança de estado.
- **Nada é apagado.** O aluno, a identidade, a matrícula e todo o histórico mantêm-se; a exclusão por faltas é um estado administrativo e não gera classificação, zero nem falta.

### Alunos da turma e alunos do histórico

- **Os ecrãs correntes mostram apenas quem está na turma hoje**; a página da turma passa a listar à parte, recolhidos, os alunos que já não a integram, com o motivo em palavras.
- **O histórico mantém-se intacto.** Resultados, classificações e avaliações intercalares dos períodos em que estiveram inscritos continuam a incluí-los.
- **Um registo novo de intervenção ou de evidência só nomeia alunos da turma atual**; editar um registo antigo continua a preservar os participantes que ele já tinha.

### Acessibilidade e linguagem

- **Nenhuma leitura depende da cor**: todos os ícones são decorativos e acompanhados de texto, os valores estão sempre escritos e os controlos anunciam o estado.
- **Animações respeitam `prefers-reduced-motion`** e todas as superfícies têm variante para modo escuro.
- **Percentagens e plurais em pt-PT**, e cada figura diz de que base foi calculada.

## [0.34.0] — 2026-08-16

### O ano inteiro numa página — Quadro Síntese

- **Uma turma lê-se ao longo do ano, não um período de cada vez.** O Quadro Síntese põe os períodos lado a lado, com os domínios em linha, para se ver o percurso de cada aluno de uma vez em vez de abrir três ecrãs e comparar de cabeça.
- **A evolução entre períodos aparece assinalada.** Subiu, manteve-se, desceu — indicado junto ao valor, sem transformar a tabela num semáforo.
- **Média Ponderada e resultado acumulado com nome próprio.** Cada número diz o que é. Uma média do período e um acumulado do ano são coisas diferentes e deixam de aparecer como se fossem a mesma.
- **Cada domínio tem a sua menção qualitativa.** Ao lado do valor, a menção que lhe corresponde na escala do perfil — sem limiares fixos escritos no código: quem os define é a escala da turma.
- **Domínios visualmente separados.** Cabeçalho discreto, corpo neutro e um separador ténue, para a tabela se ler por blocos em vez de ser uma parede de números.

### Classificações — a decisão é do professor

- **O Nível ou a Classificação atribuída edita-se onde o aluno se vê.** Em Resultados, sem navegar para outro sítio e sem perder o contexto de quem se está a avaliar.
- **Já não é preciso uma proposta prévia para decidir.** Um professor pode atribuir a classificação mesmo quando o sistema não tinha proposto nada — o sistema propõe, nunca decide.
- **Uma classificação confirmada continua editável até ser publicada.** Confirmar é decidir, não fechar. Depois de publicada fica bloqueada, e o LÁPIS explica porquê em vez de falhar em silêncio.
- **A decisão é registada na escala em que foi tomada**, e não na percentagem que a originou.

### Autoavaliação — o aluno na primeira pessoa

- **O formulário está organizado em três blocos:** «O meu desempenho», «A minha reflexão» e «Sobre o trabalho realizado».
- **Tudo é escrito na primeira pessoa.** É o aluno que fala sobre o seu trabalho, e o texto passou a soar assim.
- **«Nível» ou «Classificação», conforme a escala da turma** — a palavra vem da escala do perfil e não de um pressuposto sobre o ano de escolaridade.
- **A pergunta sobre o que melhorar sabe em que momento do ano está.** Fala do próximo período, do próximo semestre ou do fim do ano letivo consoante o calendário real da turma, em vez de assumir trimestres.
- **O aluno deixou de ver o valor calculado enquanto se autoavalia.** A autoavaliação é a leitura dele, não uma confirmação da do sistema.

### Turmas — Relação de Turma e dados administrativos

- **A importação da Relação de Turma (EB058e) guarda também o N.º de processo e a data de nascimento.** Reimportar preenche o que falta sem apagar o que já lá estava.
- **Nova secção «Dados administrativos» na página da turma**, para corrigir ou preencher à mão o N.º de processo de um aluno.

### Exportar para INOVAR

- **A grelha que o INOVAR dá é a grelha que o LÁPIS devolve.** O ficheiro `.xls` da escola é carregado, preenchido e devolvido — nada é gerado de raiz e mais nada no ficheiro é alterado.
- **Os alunos são identificados pelo N.º de processo, nunca pelo nome.** Dois alunos podem partilhar um nome, e uma nota escrita na pessoa errada não é um erro que alguém apanhe a ler.
- **As menções são escritas como F, I, S, B e MB**, a partir da correspondência declarada em cada nível da escala de sistema.
- **Uma pré-validação antes de gerar seja o que for:** que alunos e domínios foram correspondidos, quantas menções ficam prontas, e o que está a impedir o resto.
- **Uma célula sem menção fica por preencher.** Nunca é preenchida com a menção mais baixa.
- **Os resultados calculados com informação parcial são explicados por nome:** que aluno, que domínio, que elemento de avaliação, em que data, e o que ficou registado nesse elemento. Um vazio continua a não ser uma falta.
- **Disponível nos planos Pro e Institucional.** A funcionalidade é opcional: quem não a usa não passa a precisar de N.º de processo em lado nenhum.
- **A grelha carregada é apagada do servidor assim que o ficheiro preenchido é entregue**, e uma limpeza periódica remove o que ficar para trás.

### Correções

- Botão «Gerar ficheiro INOVAR» passou a descarregar mesmo o ficheiro.
- O seletor de ficheiro da grelha deixou de ser praticamente invisível.
- Concordância de singular e plural nos avisos de cobertura parcial.
- O sombreado de evolução no Quadro Síntese ficou circunscrito à célula.
- Os seeders de dados de referência deixaram de poder apagar níveis de escala que não criaram — uma instalação em uso pode correr as migrações e o seeder sem perder nada, e as escalas criadas por cada escola ficam intactas.

## [0.33.0] — 2026-08-15

### Importar resultados de outra plataforma — Intuitivo

- **As exportações do Intuitivo passam a poder ser lidas pelo LÁPIS.** A mesma entrada de Avaliações — «Importar resultados de outra plataforma» — aceita agora o ficheiro `.xlsx` do Intuitivo além da exportação do Plickers, com o mesmo percurso de quatro passos e a mesma regra de fundo: o LÁPIS mostra o que encontrou e só escreve quando o professor confirma. Disponível nos planos Pro e Institucional, como já estava.
- **Um teste com vários grupos é um instrumento, não vários.** Um enunciado dividido em GRUPO I a GRUPO IV continua a ser um único teste, com uma data e uma cotação. Criar quatro instrumentos separados seria descrever mal aquilo que o aluno fez.
- **Cada grupo pode contar para um domínio diferente.** É o que torna esta importação útil: o mesmo teste pode avaliar Leitura, Educação Literária, Gramática e Escrita, e cada bloco vai para o domínio a que pertence, em vez de o teste inteiro ir para um só.
- **O grupo do ficheiro não é o domínio.** O LÁPIS não deduz o domínio do nome do grupo — «GRUPO II» não diz nada sobre o que foi avaliado, e adivinhar a partir de um número romano seria inventar pedagogia. Nenhum domínio vem pré-selecionado, e quando a avaliação conta para a classificação cada grupo participante precisa de um escolhido pelo professor.

### Três níveis de detalhe

- **Por grupos** é o caminho normal do Intuitivo, e o que vem escolhido de origem: cada grupo dá um resultado, com a cotação que o próprio ficheiro declara.
- **Resultado global** importa apenas a classificação do teste. Continua a ser o caminho normal do Plickers, que não mudou.
- **Detalhe por pergunta** conserva a correção questão a questão. «Item 1» pode repetir-se em cada grupo sem se confundir: uma pergunta é identificada pelo grupo a que pertence, e não pelo número que tem dentro dele.
- **O detalhe de origem fica guardado mesmo quando não é importado como avaliação.** Importar por grupos escreve os resultados dos grupos, mas as perguntas e as suas pontuações continuam registadas na importação — servem para conferir contas, diagnosticar e auditar, sem passarem a avaliações que ninguém pediu.

### Contas que têm de bater certo

- **O que o ficheiro soma e o que o LÁPIS calcula são comparados e mostrados.** A soma das perguntas confere com a cotação do grupo, a soma dos grupos com o total do teste, e a soma das pontuações de cada aluno com o total que o ficheiro lhe atribui.
- **O total do ficheiro é conferência, nunca substituição.** Havendo divergência, o LÁPIS avisa em vez de corrigir por sua conta. Diferenças de arredondamento próprias do formato são toleradas; um ficheiro estruturalmente incompatível é recusado.
- **Um grupo só tem resultado quando é inteiramente conhecido.** Faltando a pontuação de uma pergunta desse grupo, o grupo fica sem resultado — somar as restantes daria um número mais baixo com aspeto de nota.

### O que os vazios continuam a não ser

- **Uma célula vazia não é um zero.** Também não é falta, falta justificada, dispensa nem «não aplicável». O que um vazio significa no Intuitivo ainda não está demonstrado, e atribuir-lhe um significado por conveniência seria decidir sobre a avaliação de um aluno sem fundamento. Fica por avaliar, à espera do professor.

### Ficheiros de origem — segurança e privacidade

- **Só é aceite o formato que foi efetivamente verificado.** Um ficheiro que não seja inequivocamente compatível é recusado com uma mensagem clara, em vez de ser interpretado por aproximação. Formatos com macros não são suportados.
- **O ficheiro é validado antes de ser aberto.** Tamanho, número de componentes internos e dimensão depois de descomprimido são verificados primeiro, o que impede que um ficheiro pequeno e malicioso se expanda até esgotar o servidor.
- **Nada dentro do ficheiro é executado.** Fórmulas não são calculadas, ligações a outros documentos não são seguidas e macros não são lidas. O que é importado é o que está escrito nas células.
- **As propriedades pessoais do documento não entram no LÁPIS.** O nome de quem criou ou modificou o ficheiro, e os caminhos da máquina onde foi gravado, não são guardados nem registados; da folha conserva-se apenas o necessário à importação.

## [0.32.1] — 2026-08-15

### Subscrições — uma de cada vez

- **Suspender uma conta passa a retirar mesmo o acesso.** Era o efeito visível do problema: uma organização podia ter mais do que uma subscrição em vigor ao mesmo tempo, e suspender a mais recente deixava a anterior voltar silenciosamente a vigorar. Na prática, «suspensa» podia significar «despromovida para o plano Base», enquanto o ecrã dizia que estava suspensa. A suspensão passa a abranger todas as subscrições em vigor.
- **Uma organização passa a ter, no máximo, uma subscrição em vigor num dado instante.** A regra vive num único sítio, por onde passam todas as mudanças de plano: a subscrição anterior é encerrada no mesmo instante em que a nova começa, sem intervalo e sem sobreposição.
- **Uma conta criada com plano Pro ou Institucional nasce já nesse plano.** Antes nascia em Base e recebia o plano escolhido por cima, no mesmo pedido — que era a origem do problema. Deixa de ser escrita uma subscrição para ser encerrada um instante depois.
- **O histórico é preservado.** Cada mudança de plano deixa a subscrição anterior no registo, com o plano, as datas e o estado que teve. Nenhuma linha é eliminada: o que passa a ser impossível é a sobreposição, não a existência de histórico.
- **Reativar retoma a mesma subscrição** que a suspensão pôs em pausa, e nunca uma que já tinha terminado.
- **Pedir o plano que já está em vigor não faz nada**, em vez de acumular subscrições idênticas.

### Reparação de dados

- **Um comando de operação repara as sobreposições que já existiam**, sem apagar nada: encerra as subscrições que tinham sido substituídas sem nunca terem sido encerradas, e mantém aquela que já era a efetiva — por isso a reparação não altera o plano de ninguém. Corre primeiro em simulação, e recusa-se a agir sobre qualquer caso que não reconheça, deixando-o intacto para decisão humana.

## [0.32.0] — 2026-08-15

### Importar resultados de outra plataforma — Plickers

- **Os resultados de um teste aplicado noutra plataforma podem agora entrar no LÁPIS sem ser copiados à mão.** A partir de Avaliações, «Importar resultados de outra plataforma» lê a exportação, mostra o que encontrou e só escreve quando o professor confirma. Disponível nos planos Pro e Institucional.
- **O caminho normal importa a classificação que a plataforma já calculou.** O Plickers dá um resultado por aluno — 55%, 85% — e é esse que é importado. Reconstruir as cotações de vinte perguntas para obter um número que já existe era trabalho sem produto, e produzia um número *diferente* assim que uma pergunta valesse mais do que outra.
- **O detalhe pergunta a pergunta é opcional**, numa caixa que ninguém tem de tocar. Só quem quiser conservar a correção questão a questão no LÁPIS é que define cotações, chaves de resposta e domínios por questão.
- **O professor escolhe o domínio avaliado.** O LÁPIS não o infere pelo texto das perguntas. Quando a avaliação conta para a classificação, o domínio é obrigatório — um resultado que conta para domínio nenhum é um resultado que não conta para nada, em silêncio.
- **O período é determinado pela data de aplicação** e continua visível e alterável. Uma data que não caia inequivocamente num período não inventa nenhum: pede que o professor escolha.
- **A correspondência entre os alunos do ficheiro e os da turma é conservadora por decisão.** Nome exato e nome normalizado associam; tudo o resto fica «Por associar» à espera do professor. Não há aproximação silenciosa, o número do cartão do Plickers nunca é lido como número de aluno, e o mesmo aluno não pode receber duas linhas do ficheiro. Trinta alunos por associar é preferível a um resultado no aluno errado.
- **Uma importação pode ser cancelada em qualquer passo.** Descarta o ficheiro temporário e a análise, e não toca em nada já avaliado.
- **Cada análise é uma importação nova**, com identificador, resumo e conteúdo próprios. Dois ficheiros com o mesmo nome e conteúdos diferentes deixam de se confundir — a identidade de uma importação vem do que o ficheiro diz, nunca de como se chama.

### O que os vazios continuam a não ser

- **Um aluno sem resultado na plataforma fica por avaliar.** Não recebe zero, não recebe falta, não recebe falta justificada e não recebe dispensa. «Não participou nesta aplicação» é um facto sobre a plataforma de origem, e uma ausência é um acontecimento que só o professor regista.
- **Um zero obtido continua a ser um zero.** A distinção entre «não tem resultado» e «teve zero» é a razão de ser de todo este fluxo.
- **O resultado original fica guardado.** «Resultado na plataforma: 85%» continua a poder ser consultado depois de o ficheiro ser eliminado.

### Correção — dizer o que falta

- **A grelha passa a dizer quem falta resolver, pelo nome**, em vez de recusar a conclusão com um botão cinzento. «Falta resolver 1 resultado antes de concluir a correção. Por avaliar: Marta Tomás.»
- **Uma célula por decidir identifica-se como «Por avaliar»**, na própria linha e no seletor de estado, em vez de um travessão que se confunde com uma célula vazia qualquer.
- **Guardar passa a confirmar que guardou.** Era a única ação da página que gravava sem dizer nada, o que era indistinguível de um botão que não funciona. Sem alterações pendentes, a página di-lo em vez de deixar o botão mudo.
- **Concluir a correção continua a ser um ato explícito do professor.** Guardar persiste trabalho; concluir declara-o terminado, e nada o faz automaticamente.

### Proteção de dados

- **Uma falha de gravação deixa de poder levar a exportação para os registos de erro.** A mensagem de uma exceção de base de dados interpola os valores gravados, e o valor aqui é o ficheiro inteiro — nomes e respostas de trinta alunos. O conteúdo é retirado no ponto da falha; o código de erro é preservado.
- **O ficheiro carregado vive em armazenamento privado e por pouco tempo**: apagado ao importar, apagado ao cancelar, e as sessões abandonadas são encerradas pelo comando de limpeza.
- **A proveniência guardada é minimizada.** Depois da importação, os nomes da plataforma de origem são descartados — a correspondência já diz quem é quem.

### Operação

- **Os dados de referência passam a ser sincronizados em todos os deploys**, não apenas no primeiro. Uma nova capacidade é dado de referência: alterar o seeder sem o executar deixa os planos existentes sem ela, que foi exatamente o que aconteceu com esta funcionalidade.

## [0.31.0] — 2026-08-15

### Instrumentos — grupos e secções

- **Um instrumento pode agora ter secções.** Um teste organiza-se em «Grupo I», «Grupo II», «Parte A» — e a grelha passa a mostrá-lo assim, em vez de uma lista contínua de questões. A estrutura é independente dos domínios: uma secção pode alimentar vários domínios e um domínio pode ser avaliado em várias secções.
- **A numeração das questões passa a ser por secção.** Um mesmo instrumento pode ter «1, 2, 3» no Grupo I e «1, 2, 3» no Grupo II, como acontece num enunciado real. Até aqui o código tinha de ser único em todo o instrumento, o que obrigava a numerações artificiais.
- **Um instrumento simples continua sem estrutura nenhuma.** Quem não precisa de secções não vê nenhuma: o grupo implícito não aparece no ecrã nem obriga a decidir nada.
- **Corrigida a edição estrutural.** Reordenar secções ou renumerar questões deixa de poder colidir com a numeração antiga a meio da gravação, e a edição do cabeçalho (título, data, tipo, peso) passa a estar coberta por testes de regressão.

### Resultados — proposta na escala do perfil

- **A Proposta deixa de ser a percentagem repetida.** Passa a ser o resultado lido na escala que a versão do perfil congelou — um 4, um 16, um 80%, um «Bom». O Resultado continua a ser o valor normalizado em percentagem: são duas colunas com dois significados.
- **As três famílias de escala têm tratamento próprio.** Escalas por níveis usam as bandas configuradas; escalas numéricas usam o intervalo da própria escala (`min + (normalizado/100) × (max − min)`), arredondado pela regra do perfil — genérico para 0–20, 1–20, 0–10 ou qualquer outro; escalas percentuais só são arredondadas. Nenhuma escala está escrita no código.
- **Uma escala sem bandas aprovadas diz que está por configurar**, em vez de mostrar um vazio silencioso. O LÁPIS não infere limiares: o nível é atribuído pelo professor.

### Correção — concluir e reabrir

- **Uma correção pode agora ser dada por concluída.** Até aqui um instrumento entrava em «Em correção» na primeira nota e ficava lá para sempre: uma correção terminada era indistinguível de uma abandonada, e a grelha continuava editável por descuido.
- **«Concluir correção» é recusada enquanto faltar decidir alguma célula aplicável**, e a recusa diz quantas faltam. Contam como decididas a ausência, a dispensa, o «não aplicável» e a anulação — são decisões que o professor já tomou. Só «por avaliar» e «em revisão» mantêm a correção aberta.
- **A barra de progresso e o botão não podem discordar.** Quem vê 6/6 consegue sempre concluir; quem vê 4/5 é sempre recusado — a regra passou a viver num único sítio, usado pelos dois.
- **Uma correção concluída fica em modo de consulta** e a gravação é recusada no servidor, não apenas escondida no ecrã. Fica registado quem concluiu e quando.
- **«Reabrir correção» volta atrás** e devolve as células a editáveis. Nem concluir nem reabrir mexem numa única classificação: concluir é uma afirmação sobre o trabalho do professor, não sobre os resultados dos alunos.

### Resultados — cobertura parcial

- **O aviso ⚠ passa a explicar-se.** Ao passar o rato — ou o foco do teclado — indica o instrumento e o dia: «Teste de Compreensão Leitora · 15/10/2026 — Ausência: 3 questões sem classificação.»
- **«Cobertura insuficiente» passou a «Cobertura parcial».** Se a cobertura fosse mesmo insuficiente, o LÁPIS não devia produzir resultado nenhum. O que acontece é que há resultado e ele assenta em parte dos elementos aplicáveis.
- **Quando não há resultado, o aviso diz outra coisa: «Sem elementos avaliados».** São dois fenómenos diferentes e deixam de partilhar a mesma frase.
- **Uma entrada tardia nunca é descrita como falta.** O aluno que entrou depois de o instrumento ter sido aplicado é excluído do cálculo pela mesma regra, mas não faltou a nada — e o aviso não o pode dizer.
- **As ocorrências são agrupadas por instrumento**, não por célula: quem faltou a um teste de três questões faltou a um teste. Uma questão repartida por dois domínios conta uma só vez no resultado global.
- O cálculo não mudou. Nenhum valor, arredondamento ou classificação é afetado por esta alteração.

## [0.30.0] — 2026-08-14

### Alunos

- **Os dados de um aluno já inscrito passam a ser editáveis.** Nome, número e data de entrada corrigem-se no lugar, a partir da própria turma. Até aqui um simples erro de escrita obrigava a eliminar o aluno e voltar a criá-lo — com tudo o que isso arrastava atrás. A ação «Editar» aparece na coluna Ações, antes de «Eliminar».
- **Um aluno «(sem identidade)» passa a poder receber nome.** Ao guardá-lo, a identidade cifrada é criada e a listagem passa imediatamente a mostrar o nome real. O campo abre vazio, nunca pré-preenchido com o texto marcador.
- **Corrigir a data de entrada corrige também o «ingresso tardio»**, nos dois sentidos: adiar a entrada marca-o, antecipá-la limpa-o. Não fica um aviso desatualizado a dizer o contrário do que a data diz.
- **A correção é sempre uma edição, nunca uma recriação.** O pseudónimo, o `Student` e o `Enrollment` mantêm-se — e com eles ficam intactos instrumentos, resultados, avaliações, registos, intervenções, autoavaliações, relatórios e fotografias. É a garantia de que corrigir um nome não desliga o histórico pedagógico do aluno a quem pertence.

### Fotografias

- **Gestão individual da fotografia**: adicionar, substituir e remover, aluno a aluno, a partir do mesmo diálogo de edição — sem outro ícone na tabela. Até aqui a única via era a importação em lote do ficheiro do Inovar.
- **O aluno que entra a meio do ano deixa de ficar sem fotografia.** Inscreve-se à mão, edita-se, associa-se a fotografia — sem gerar nem importar de novo o ficheiro completo da turma.
- **Uma só implementação de armazenamento**, em `StudentPhotoService`: a importação em lote e a gestão individual passaram a partilhar o mesmo disco privado, o mesmo esquema de nomes e a mesma limpeza. A importação do Inovar continua a funcionar exatamente como antes.
- **Substituir uma fotografia deixa de acumular ficheiros órfãos**, e uma falha a meio deixa de os criar: se a nova referência não chegar a ser gravada, o ficheiro acabado de escrever é removido e a fotografia anterior fica intacta; se uma inscrição falhar durante a importação, a fotografia já escrita para essa linha é limpa. Ao remover, a referência é apagada primeiro — uma limpeza física falhada regista um aviso e nunca faz a aplicação voltar a apontar para um ficheiro que o professor mandou remover.
- **A fotografia continua opcional e continua privada.** Nada é bloqueado por não existir; o ficheiro nunca sai do armazenamento privado, é servido apenas pela rota autorizada, e a validação lê o conteúdo do ficheiro em vez de confiar na extensão.

### Miniaturas

- **Uma miniatura do aluno junto ao nome**, para o professor ligar depressa nome a rosto — sobretudo no início do ano ou numa turma nova: na lista da turma, na grelha de correção, nas classificações, nos resultados e no resumo de avaliação. É ajuda visual e nada mais: não entra em relatórios, registos, intervenções nem em qualquer conteúdo que atravesse a fronteira da IA.
- **Quem não tem fotografia mostra um marcador do mesmo tamanho**, para que a ausência nunca desalinhe as colunas — e uma imagem que falhe a carregar cai para esse mesmo marcador, em vez de deixar uma imagem partida.
- **A fotografia adapta-se à densidade da grelha, não o contrário**: 34 px na lista da turma, 24 px nas grelhas, sem aumentar o espaçamento vertical das linhas. Na grelha de correção, onde a coluna do aluno compete com as colunas de lançamento, a miniatura recolhe em ecrãs estreitos. O nome nunca é escondido em favor da fotografia.

## [0.29.1] — 2026-08-14

### Documentação

- **A solução registada para a armadilha 6 estava errada, e o deploy da 0.29.0 provou-o.** Criar um SSH user dedicado (`lapis-deploy`) não impede o CloudPanel de reescrever a `authorized_keys`: fê-lo na mesma, com as mesmas três chaves de terceiros, 24 segundos depois de a nossa chave ter funcionado. O nome partilhado nunca foi a causa. A solução que resulta é `~/.ssh/authorized_keys2`, que o `sshd` lê e o painel não gere. Fica também registado que continua por esclarecer porque é que chaves de terceiros são injetadas num utilizador deste site — pertencem ao grupo `lapis`, logo leem o `.env`.
- **Armadilha nova: o utilizador de deploy tem de ser dono do código.** Os diretórios pertenciam ao antigo user `deploy` a `750`, e o `lapis-deploy` — no grupo, mas não dono — não conseguiu escrever: 845 ficheiros recusados, `config/app.php` na versão anterior, e o script a dar a extração por concluída na mesma. Fica documentado o `chown` correto (código para quem faz deploy, `storage` e `bootstrap/cache` para o utilizador web com escrita de grupo) e a razão de nunca se usar `chmod 777` — o problema é de propriedade, e `777` mascara-o dando escrita a outras equipas do VPS.
- **Verificar a extração por checksum, não pela versão.** O `tar` falha ficheiro a ficheiro e o `|| true` engole o erro; comparar `config/app.php`, `composer.lock` e `manifest.json` entre local e servidor é o que distingue um deploy real de um que não escreveu nada. Acrescentado à checklist, com o teste de propriedade e escrita a correr **antes** de entrar em manutenção.
- **`.agents` e `.superpowers` acrescentados às exclusões do pacote.** Estão no `.gitignore`, mas o `tar` não o lê — foi assim que 6 MB de skills e fontes TTF foram parar a produção. Documentado também que o servidor tem Node 12, demasiado antigo para o Vite: os assets são sempre compilados localmente.

## [0.29.0] — 2026-08-14

### Intervenções

- **Módulo reconcebido.** Deixou de ser «uma medida de apoio, para um aluno, com duração» e passou a «uma ação pedagógica intencional», que pode ser pontual ou continuada. O registo rápido é o essencial: escolher quem, escolher o tipo, guardar — sem título obrigatório, sem descrição obrigatória (salvo no tipo «Outro»), sem estado nem duração a preencher.
- **Aluno, grupo ou turma.** Uma intervenção pode dirigir-se a um aluno, a um grupo de dois ou mais, ou à turma inteira. Uma intervenção de turma não nomeia alunos de propósito — a turma é o destinatário — mas continua a aparecer ao filtrar por qualquer aluno dela.
- **Catálogo de 29 tipos**, agrupados por contexto (aprendizagem, avaliação, métodos de estudo e autonomia, atenção e autorregulação, comportamento, integração). O contexto é sempre deduzido do tipo, nunca escolhido pelo professor.
- **Domínio disciplinar opcional**, com três leituras distintas: sem domínio específico, um domínio, ou todos os domínios. Só domínios reais da disciplina — «avaliação» ou «comportamento» são contextos, não domínios.
- **Enquadramento pedagógico/legal opcional**, recolhido por defeito. A maioria das intervenções é prática corrente e não precisa dele. Quando o tipo corresponde inequivocamente a uma medida formal, o enquadramento é preenchido e pode ser alterado ou removido; quando apenas se aproxima de uma, é **sugerido** e só fica registado se o professor confirmar — uma sugestão por confirmar não é guardada de todo.
- **Medidas de suporte e adaptações no processo de avaliação são coisas distintas.** Dar tempo suplementar a um aluno regista a adaptação e deixa o nível da medida por especificar: usar uma adaptação nunca significa, por si só, que o aluno está abrangido por medidas universais, seletivas ou adicionais. As duas podem coexistir quando o professor assim o decidir.
- **Monitorização preservada.** Estado, data de fim prevista, conclusão e as apreciações periódicas de eficácia continuam exatamente como estavam, como ações separadas do registo.
- **«Disponível para relatórios»**, ativo por defeito: marca elegibilidade para uso futuro pelo módulo de Relatórios, que não é alterado nesta versão — nada é copiado nem gerado automaticamente.
- **Listagem com filtros** por aluno, tipo, contexto, domínio, período, disponibilidade para relatórios e nível de medida.

### Preparação multijurisdição

- **A intervenção é pedagógica e global; o enquadramento legal é jurisdicional.** «Apoio à organização da escrita» significa o mesmo em qualquer país; o que varia é se alguma legislação o enquadra. O catálogo de tipos deixou de conter qualquer conhecimento jurídico.
- **Portugal passou a ser uma implementação isolada**, com toda a sua terminologia — níveis de medida, medidas e adaptações — fechada no seu próprio enquadramento jurídico.
- **Jurisdição por organização**, com um fallback de compatibilidade para Portugal enquanto uma organização não tiver a sua definida. Uma jurisdição indicada explicitamente para a qual não exista enquadramento **não** herda o português: fica simplesmente sem enquadramento legal.
- **Funciona plenamente sem enquadramento legal.** Uma escola numa jurisdição ainda não suportada regista intervenções normalmente, com tipos, destinatários, domínios, datas, estado, eficácia e filtros — apenas sem a camada jurídica, e sem lhe ser mostrada a taxonomia de outro país.
- **A língua da interface nunca decide qual a lei aplicável.** Uma escola portuguesa pode trabalhar em inglês, e uma escola estrangeira em português.
- **Alterações legislativas futuras não reclassificam o passado.** O enquadramento é resolvido pela data da própria intervenção, e o que ficou decidido é guardado no momento e nunca recalculado — abrir ou editar uma intervenção antiga não a reinterpreta à luz de legislação posterior.

## [0.28.1] — 2026-08-13

### Documentação

- **Duas armadilhas novas no deploy, apanhadas durante o deploy da 0.28.0.** O SSH user `deploy` não é exclusivo deste site — é um nome genérico partilhado com outros sites do VPS, e a sua `authorized_keys` é reescrita por fora, apagando a chave que o painel do LAPIS diz ter guardado. Fica documentado o sintoma enganador (permissões e `sshd -T` aparecem corretos), o sinal fiável (o tamanho do ficheiro: ~90 bytes por chave) e a solução (criar um SSH user com nome único em vez de reutilizar o `deploy`). A segunda: tentativas repetidas fazem o `fail2ban` banir o IP, e `Connection timed out` significa ban — não chave recusada.
- **Estado de produção atualizado.** A produção estava na 0.25.0, não na 0.27.0 — o deploy da 0.28.0 apanhou três versões de uma vez. Fica o aviso para confirmar sempre a versão real no servidor antes de assumir de onde parte um deploy, já que o servidor não tem `.git` e nada indica de fora que commit lá está.

## [0.28.0] — 2026-08-13

### Avaliações

- **Novo módulo Avaliações.** Vista orientada ao fluxo do professor sobre os instrumentos já criados — "o que está a acontecer", não "o que construí". Lista com filtros por Estado, Finalidade e Período, estado derivado por avaliação e progresso sempre contado por alunos, nunca por células (ex.: "12/20", nunca uma contagem de células do tipo "117/200").
- **Página operacional da avaliação.** Resumo com alunos aplicáveis, concluídos, por corrigir, faltas e em revisão; estado individual por aluno (Corrigida, Por corrigir, Faltou, Em revisão, Situação especial, entre outros). Alunos fora do período de matrícula ficam sempre destacados à parte, nunca misturados com os restantes nem a contar para o progresso.
- **Reutilização total da grelha de correção e do fluxo de Instrumentos.** Corrigir a partir de Avaliações abre a mesma grelha já existente — sem nova rota de notas, sem novo sistema de correção. Navegação Avaliações → correção → "Voltar a Avaliações" quando a origem é o novo módulo; o fluxo antigo mantém "Voltar à turma".
- **Criar avaliação reutiliza o fluxo existente de Instrumentos.** "Nova avaliação" pede a turma (não há turma ambiente numa lista que cruza turmas) e abre o formulário de Instrumentos já existente, sem segundo formulário. O período vem pré-preenchido quando o filtro ativo pertence ao ano letivo da turma escolhida; caso contrário, o professor escolhe normalmente.
- **Campo "Finalidade" no formulário de instrumento.** Diagnóstica / Formativa / Sumativa / Outra — antes só existia como valor interno, sem forma de o professor o escolher.
- **Avaliações diagnósticas com "Contabiliza para classificação" a Não por defeito.** Só quando o professor não decide explicitamente o contrário — uma escolha explícita, em qualquer sentido, é sempre respeitada e nunca sobreposta automaticamente, incluindo ao editar uma avaliação diagnóstica já existente.

### Grelha de correção

- **Apreciação qualitativa por domínio.** Junto ao total já existente, cada domínio do instrumento passa a mostrar pontos, percentagem e menção qualitativa própria — agregada a partir da contribuição de cada questão alocada ao domínio, nunca a pontuação isolada de uma única questão.
- **Badges cromáticos consistentes por banda qualitativa**, reutilizáveis fora da grelha — a cor deriva da posição estrutural da banda na escala, nunca do texto do rótulo, para não se perder com escalas personalizadas ou traduzidas.
- **Resultados parciais claramente distintos dos definitivos.** Um domínio com questões ainda por corrigir nunca mostra uma menção qualitativa como se fosse a palavra final.
- **Mesma escala e mesma lógica qualitativa em todo o lado.** Nenhum limiar novo — a apreciação por domínio usa exatamente as mesmas scale bands e a mesma função que já geravam a apreciação global.

## [0.27.0] — 2026-08-13

### Adicionado

- **Ligação de autoavaliação por aluno, sem login.** Em Autoavaliações → turma → período, "Ligações para os alunos" gera uma ligação assinada e temporária (7 dias) por aluno — sem conta nem password — para o aluno preencher a sua própria autoavaliação no próprio dispositivo. Fica registado como preenchido pelo aluno, distinto de quando o professor preenche em entrevista. Disponível apenas nos planos Pro e Institucional (módulo novo `self_assessment_links`), não no plano Base.

## [0.26.0] — 2026-08-12

### Adicionado

- **Módulo Registos concluído.** Os 10 tipos de registo (Trabalho de casa, Participação, Progresso, Dificuldade, Ocorrência disciplinar, Comportamento meritório, Apoio, Contacto, Atividade, Observação) passam a ter campos condicionais próprios — Situação, Participação observada, Domínio relacionado (opcional, só Progresso/Dificuldade), Gravidade, Avaliação global + Incluir no relatório — com dicas curtas e placeholders por tipo. O agrupamento em Aprendizagem/Comportamento e atitudes/Acompanhamento é sempre calculado a partir do tipo (`EvidenceKind::group()`), nunca escolhido pelo professor nem gravado em coluna própria. Editar e eliminar (com confirmação) ficam disponíveis na listagem, com filtros por aluno, tipo e período. Um aviso discreto lembra que os registos não alteram a classificação; uma síntese neutra por tipo resume o que está listado, sem juízos de valor.
- **Registar vários alunos de uma vez.** Ao criar um registo, o campo Aluno passa a alternar entre "Turma inteira" e uma grelha de checkboxes com "Selecionar todos"/"Limpar seleção" — sem ter de percorrer um dropdown aluno a aluno. Cada aluno selecionado fica com o seu próprio registo independente (editável e eliminável à parte), não um registo partilhado.
- **Preparação de dados para relatórios (interface por fazer).** Um registo de Atividade pode ser marcado "Incluir no relatório" — sinalização por registo, distinta de `classes.include_evidence_in_report`/`enrollments.include_evidence_in_report` (essas continuam a decidir se o livro de registos aparece no relatório). Ficam prontos os scopes reutilizáveis (`forClass`, `forEnrollmentOrWholeClass`, `inPeriod`, `inGroup`, `autoSelectableForReport`) que uma futura interface de Relatórios vai usar — esta entrega não altera `ReportsController` nem `resources/js/pages/reports/*`.

### Corrigido

- **Dois testes de Registos davam falso positivo.** Ficaram desatualizados quando `participation_level`/`activity_evaluation` passaram a obrigatórios nos respetivos tipos: o pedido falhava a validação e voltava para trás, e `assertRedirect()` não distinguia isso de um sucesso — o registo nunca chegava a ser criado.
- **Descrição opcional (Trabalho de casa/Participação/Ocorrência disciplinar) podia rebentar a inserção.** A coluna `description` é `NOT NULL` na base de dados; omitir o campo enviava `NULL` para a queda. Passa a gravar `''` quando não preenchida.
- **Erro de tipos no formulário de Registos.** Um tipo local `Record` (a forma de um registo na listagem) tapava o genérico nativo `Record<K, V>` do TypeScript, usado no dicionário de metadados por tipo — `vue-tsc` nunca tinha corrido sobre este ficheiro. Renomeado para `EvidenceRecordRow`.

## [0.25.0] — 2026-08-01

### Alterado

- **Cotação por domínio passa a ser em pontos, não em percentagem.** Ao criar/editar um instrumento, escolhem-se primeiro os domínios avaliados; ao criar cada questão, a cotação de cada domínio insere-se diretamente em pontos (uma questão pode tocar mais do que um domínio). A cotação total da questão é só de leitura, somada automaticamente. Um painel-resumo no final do formulário mostra o total por domínio, atribuindo a cada um só a parte da questão que lhe foi cotada — nunca a questão inteira duplicada nos vários domínios que toca. A base de dados e o motor de cálculo continuam em percentagem por dentro; a conversão é só de apresentação.

### Corrigido

- **O seletor de domínio de cada questão mostrava todos os domínios da turma**, não só os escolhidos no topo do formulário para aquele instrumento.

## [0.24.0] — 2026-08-01

### Adicionado

- **Grau de gravidade nas ocorrências disciplinares.** Ao escolher "Ocorrência disciplinar" nos Registos, aparece um campo obrigatório antes da Descrição com 5 graus: Advertência/Falta de material (G2), Perturbação ligeira da aula (G3), Indisciplina/Falta de respeito (G4), Infração grave (G5), Infração muito grave (G6).
- **"Incluir dados que constam nos Registos do professor" passa de Registos para Relatórios.** A checkbox por registo individual nunca teve efeito nenhum (a pauta nunca leu essa flag). Substituída por uma definição ao nível da turma (omissão) com exceção por aluno, ambas geridas na página do relatório da turma.

### Alterado

- **Três designações de tipo de registo:** "Comportamento positivo" → "Comportamento meritório (G1)"; "Nota" → "Observação"; "Ocorrência" → "Ocorrência disciplinar".

### Corrigido

- **Ineficiência no formulário de instrumento**: a lista de questões por domínio recalculava-se a cada tecla premida em qualquer campo, em vez de só quando a lista de questões muda.

## [0.23.0] — 2026-07-31

### Adicionado

- **Opção "Outro…" no tipo de instrumento, com designação livre.** Ao criar ou editar um instrumento, escolher "Outro…" no campo "Tipo" mostra um campo de texto para o professor escrever a sua própria designação. O `InstrumentType` já estava desenhado para isto (linhas próprias por organização, "o professor pode adicionar as suas") — só faltava a interface. Reutilizar a mesma designação depois não cria um tipo duplicado; volta a usar o mesmo.

## [0.22.6] — 2026-07-31

### Corrigido

- **O dropdown "Tipo" ao criar um instrumento aparecia sem nenhuma opção em produção.** `instrument_types` estava vazio — o deploy inicial (2026-07-27) só correu o `EntitlementsSeeder`, não os outros dois seeders de referência obrigatórios (`SystemScalesSeeder`, `InstrumentTypesSeeder`) que `DatabaseSeeder`/`ReferenceDataSeeder` sempre correm juntos. Corrigido a correr `InstrumentTypesSeeder` diretamente em produção (idempotente); `docs/deployment.md` passa a apontar para `ReferenceDataSeeder` (os três) em vez de só o `EntitlementsSeeder`, para não repetir a lacuna num futuro deploy de raiz.

## [0.22.5] — 2026-07-31

### Corrigido

- **"Associar fotos" dava erro 500 em produção (`UnableToCreateDirectory`).** Efeito colateral da limpeza dos ficheiros de teste poluídos (0.22.3): as pastas `storage/app/private/roster-imports` e `student-photos`, ao serem geridas pelo user `deploy` por SSH, ficaram sem permissão de escrita para o grupo (`750`), e o site corre como `lapis` — só partilha o grupo com `deploy`, não é dono. Corrigido diretamente no servidor (`chmod g+rwX`) e documentado em `docs/deployment.md` como uma nova armadilha a evitar da próxima vez que se mexer nestas pastas por SSH.

### Adicionado

- **Nota explicativa sobre os modelos Intuitivo a usar na ficha da turma.** Junto aos botões "Importar lista"/"Adicionar fotos" (e nas descrições dos respetivos diálogos, e no passo de fotos da pré-visualização da importação), indica-se agora qual o modelo esperado: EB058 (Excel) para a lista de alunos, EB019 (Word) para as fotos.

## [0.22.3] — 2026-07-31

### Corrigido

- **Ficheiros de fotos exportados num formato Word "normal" (imagens inline modernas + nomes em texto simples numa tabela) extraíam sempre 0 fotos, silenciosamente.** `PhotoFileParser` só reconhecia o formato específico do export "Intuitivo" (imagens VML + legendas em `w:altChunk`). Confirmado diretamente com um ficheiro real do utilizador: agora reconhece também o formato de tabela (linha de fotos + linha de nomes separada, imagens via `<w:drawing>`/`<a:blip>`), como alternativa quando o formato original não encontra nada.
- **A resolução do caminho das imagens dentro do ficheiro assumia sempre um caminho absoluto ("/media/imagem.jpg"), mas um documento Word "normal" usa caminhos relativos à pasta `word/` ("media/imagem.jpg").** Confirmado que isto fazia com que as imagens do segundo formato nunca fossem encontradas mesmo depois de reconhecidas — corrigido para resolver cada convenção corretamente (regra OOXML: `/` no início = raiz do ficheiro; sem `/` = relativo a `word/`).

## [0.22.2] — 2026-07-31

### Corrigido

- **Mensagens de sucesso como "N foto(s) associada(s)." ou "Instrumento anulado." nunca apareciam — eram guardadas com `->with('status', ...)`, mas o sistema de toasts só reage a `Inertia::flash('toast', [...])` (o padrão já usado por outros controllers, ex. `EvidenceController`, `InterventionController`).** Sem feedback visível, uma associação de fotos que resultasse em "0 associadas" parecia exatamente igual a uma que tivesse funcionado — impossível distinguir uma falha silenciosa de sucesso. `ClassPhotoImportController`, `RosterImportController` e `InstrumentController` (anular/reverter) passam a usar `Inertia::flash('toast', ...)`, tal como os restantes.

## [0.22.1] — 2026-07-31

### Corrigido

- **Na pré-visualização da importação de turma, era possível confirmar a importação sem as fotos escolhidas chegarem a ser associadas.** "Adicionar fotos" exige dois passos — escolher o ficheiro Word e depois clicar em "Adicionar fotos" — mas nada impedia avançar diretamente para "Confirmar importação" depois de só escolher o ficheiro, saltando o segundo clique sem qualquer aviso. Os logs do servidor confirmaram exatamente isto: nenhum pedido ao passo de anexar fotos chegou a ser feito. `Preview.vue` passa agora a mostrar um aviso e a bloquear "Confirmar importação" enquanto houver um ficheiro de fotos escolhido e ainda não submetido.
- **Um deploy anterior enviou por engano o `storage/app` local (fotos de teste e pastas temporárias de importação de dias anteriores) para produção**, poluindo `storage/app/private/student-photos` e `roster-imports` com ficheiros que nenhum aluno real referenciava. Ficheiros órfãos removidos do servidor (confirmado, zero referências na base de dados de produção); o comando de empacotamento em `docs/deployment.md` passa a excluir sempre `storage/app` — conteúdo carregado é específico de cada ambiente e nunca deve viajar num pacote de código.

## [0.22.0] — 2026-07-31

### Adicionado

- **Rodapé com a versão da aplicação e uma página "Novidades".** Todas as páginas da área do professor passam a mostrar, no rodapé, a versão atual (`v{versão}`) e um link "Novidades" que abre `/novidades` — uma página com o histórico completo de alterações, lido diretamente do `CHANGELOG.md` do repositório (versão, data, categoria e descrição de cada alteração). `App\Support\Changelog\ChangelogParser` faz o parsing do ficheiro; a página não depende de organização/tenant, porque o changelog é igual para todos os professores.

## [0.21.19] — 2026-07-31

### Corrigido

- **"Adicionar fotos" na ficha da turma parecia não fazer nada: a caixa de upload ficava aberta e as fotos não apareciam.** `submitPhotos()` nunca fechava o diálogo depois de submeter — ao contrário de `enroll()` (mesma página) e do fluxo de anulação de instrumentos, que já fecham o seu próprio diálogo em `onSuccess`. Como o redireccionamento do upload volta para a mesma página (`classes.show`), o Inertia reaproveita a instância do componente em vez de a recriar, por isso o estado local do diálogo nunca era reposto sozinho. `photoDialogOpen.value = false` no `onSuccess` resolve — as fotos já associadas corretamente ficavam sempre por trás do diálogo aberto.

## [0.21.18] — 2026-07-31

### Corrigido

- **O recurso ao pseudónimo como alternativa (0.21.17) mostrava-o também na coluna "Nome", indistinguível de um nome real.** Como o pseudónimo já tem a sua própria coluna dedicada onde faz sentido, repeti-lo na coluna do nome escondia o problema em vez de o sinalizar. Todos os mesmos pontos passam a mostrar `(sem identidade)` quando a identidade não existe, deixando claro que é um dado em falta e não um nome válido.

## [0.21.17] — 2026-07-31

### Corrigido

- **Um aluno sem registo de identidade (nome/foto) deixava de conseguir abrir a ficha da turma, relatórios, resultados, avaliação de intervenções, evidências, autoavaliação e a grelha do instrumento — erro 500 "Attempt to read property display_name on null".** Todos os pontos que liam `$student->identity->display_name`/`photo_path` assumiam que a identidade existe sempre; no ambiente local, a tabela `student_identities` acabou vazia (dados perdidos localmente, não em produção). Estes pontos passam agora a usar `optional($student->identity)->display_name`, com o pseudónimo do aluno como alternativa quando a identidade não existe — a app deixa de rebentar mesmo com dados incompletos, mostrando o pseudónimo até a identidade ser restaurada (ex.: reimportando a turma).

## [0.21.16] — 2026-07-30

### Alterado

- **Formulário de instrumento reorganizado por domínio (Tarefa 1).** `InstrumentForm.vue` (partilhado por `Create.vue` e `Edit.vue`) deixa de mostrar uma lista única e plana de questões. O professor escolhe primeiro, em "Domínios avaliados", quais os domínios que o instrumento cobre; cada domínio marcado ganha a sua própria secção com "+ Adicionar questão", e uma questão criada aí já nasce alocada a 100% a esse domínio. A secção "Sem domínio associado" mantém-se para perguntas sem alocação, sem alterações de comportamento. O editor de alocações por questão foi extraído para `InstrumentDomainAllocations.vue`, reutilizado nas duas secções, para que uma pergunta continue a poder repartir-se por vários domínios manualmente. Ao abrir um instrumento existente em `Edit.vue`, os domínios já usados pelas suas perguntas ficam pré-marcados, para que nenhuma pergunta desapareça de vista até o professor mexer nas caixas. Nenhuma alteração de backend, esquema, rotas ou validação.

## [0.21.15] — 2026-07-30

### Corrigido

- **Importar a estrutura de outro instrumento podia copiar alocações de domínio que a turma de destino não avalia.** A revisão final do plano de importação encontrou que a mesma disciplina (`subject_id`) não garante o mesmo conjunto de domínios: duas turmas podem partilhar a disciplina mas ter versões de perfil diferentes (ano/turma distintos), cada uma com os seus próprios domínios. `InstrumentController::importableInstrumentsFor()` passa agora a filtrar as alocações de domínio de cada pergunta pelos domínios que a turma de destino realmente avalia (`InstrumentBuilder::domainsFor()`) — uma alocação para um domínio que a turma de destino não segue é descartada em vez de copiada, evitando dados de domínio inconsistentes.

## [0.21.14] — 2026-07-30

### Adicionado

- **Importar a estrutura de outro instrumento ao criar um novo (Tarefa 2/2).** O formulário de "Novo instrumento" ganha um seletor "Importar de outro instrumento", com os candidatos que a Tarefa 1 já filtra (turmas do próprio professor, mesma disciplina). Ao escolher um e clicar em "Importar questões", copia-se o título, a cotação total, o indicador de bónus e todas as perguntas (com as suas alocações de domínio) para o formulário — tudo continua totalmente editável depois, e nada além da estrutura é copiado (nunca notas de alunos). Fecha o plano de importação de estrutura de instrumentos.

## [0.21.13] — 2026-07-30

### Adicionado

- **Backend para importar a estrutura de um instrumento como ponto de partida (Tarefa 1/2).** `InstrumentController::create()` passa a enviar uma nova prop Inertia `importableInstruments` — os instrumentos das próprias turmas do professor autenticado, filtrados à mesma disciplina da turma alvo (nunca a de um colega, nunca de outra disciplina, onde as alocações de domínio não fariam sentido). Só estrutura viaja: código, enunciado, pontuação possível, indicador de bónus e alocações de domínio por pergunta — nunca notas de alunos. A Tarefa 2 (frontend) consome esta prop em `instruments/Create.vue` para oferecer "importar de um instrumento existente".

## [0.21.12] — 2026-07-30

### Corrigido

- **`PUT /instruments/{instrument}` aceitava `status: 'cancelled'` fora do fluxo dedicado de anulação.** A validação de `InstrumentRequest` ainda listava `cancelled` como um valor de estado aceite pela edição genérica — encontrado na revisão final do plano de edição de instrumentos. Isto contornava por completo `cancel()` (Tarefa 4/6): nenhum motivo era exigido, e `cancelled_at`/`cancelled_by`/`status_before_cancellation` ficavam todos por preencher. Um instrumento assim "anulado" tornava-se ilegível para "Reverter anulação" — que tentaria repor `status_before_cancellation` (`null`) na coluna `status`, não anulável. Só `InstrumentController::cancel()`/`revertCancellation()` devem produzir ou consumir este valor; a lista de estados aceites pela edição genérica deixou de incluir `cancelled`.

## [0.21.11] — 2026-07-30

### Adicionado

- **Interface de edição/anulação de instrumentos em `Grid.vue` (Tarefa 6/6).** A grelha ganha o link "Editar instrumento" e o botão "Anular instrumento" (abre um diálogo com motivo obrigatório, `POST .../cancel`). Enquanto anulado, a grelha mostra uma faixa com o motivo e o botão "Reverter anulação" (`POST .../revert-cancellation`), e todas as caixas de nota e seletores de estado ficam desativados — o link de editar, o botão de anular e o botão Guardar desaparecem por completo, já que o backend recusa estas ações com 403 enquanto o instrumento está anulado (Tarefas 3/4). Fecha o plano de edição de instrumentos iniciado na Tarefa 1.

## [0.21.10] — 2026-07-30

### Adicionado

- **Formulário de edição de instrumentos (Tarefa 5/6).** A lógica de `instruments/Create.vue` foi extraída para um novo `InstrumentForm.vue` partilhado (mesmo padrão já usado em `assessment-profiles/ProfileForm.vue`), com `Create.vue` a passar a ser um invólucro fino sobre ele. O placeholder mínimo de `instruments/Edit.vue` (Tarefa 3) é substituído pelo formulário real, pré-preenchido com os dados atuais do instrumento. Uma questão que já tem notas lançadas (`has_scores`, enviado pelo backend desde a Tarefa 3) tem agora o botão de remover desativado, com um tooltip a explicar porquê — a face visível da proteção que `InstrumentBuilder::update()` já impõe no servidor desde a Tarefa 2.

## [0.21.9] — 2026-07-30

### Adicionado

- **Anulação reversível de instrumentos (Tarefa 4/6).** Novas rotas `POST instruments/{instrument}/cancel` (motivo obrigatório) e `POST instruments/{instrument}/revert-cancellation`, que repõe o estado exato anterior à anulação — o ciclo anular/reverter pode repetir-se sem limite. Enquanto anulado, o instrumento fica só-leitura: `edit()`, `update()` (Tarefa 3) e agora também `saveScores()` recusam o pedido com 403. A grelha (`show()`) passa a enviar `status` (valor bruto) e `cancellation_reason`, para a Tarefa 6 construir a interface de anular/reverter no `Grid.vue`.

## [0.21.8] — 2026-07-30

### Adicionado

- **Fundação para a edição de instrumentos (Tarefa 3/6).** Novas rotas `GET instruments/{instrument}/edit` e `PUT instruments/{instrument}` — ainda sem interface própria (`instruments/Edit.vue` é por agora um placeholder mínimo; a Tarefa 5 constrói o formulário real). `InstrumentRequest` ganha autorização própria: sem isto, um professor sem acesso à turma recebia um erro de validação (422) em vez de acesso negado (403) sempre que o pedido também tivesse dados inválidos, porque a validação do pedido corre antes de qualquer autorização no corpo do controlador. Um instrumento anulado (funcionalidade da Tarefa 4) já fica preparado para ficar só-leitura nestas duas rotas.

## [0.21.7] — 2026-07-30

### Corrigido

- **Trocar o código de duas perguntas existentes na mesma edição fazia `InstrumentBuilder::update()` rebentar com um erro 500.** `instrument_items` tem uma constraint `unique(instrument_id, code)`, e o `update()` introduzido na Tarefa 2/6 passou a atualizar cada pergunta mantida no lugar, uma de cada vez — se a pergunta A (código `Q1`) troca para `Q2` enquanto a pergunta B (`Q2`) troca para `Q1`, escrever a nova pergunta A primeiro colide com o código ainda em uso pela B (o MySQL não tem constraints únicas diferíveis). Isto nunca acontecia na implementação antiga de apagar-tudo-e-recriar, porque nada existia ainda quando as novas linhas eram criadas — uma regressão introduzida especificamente pela mudança para a atualização no lugar. `update()` passa agora por duas fases dentro da mesma transação: primeiro atribui a cada pergunta mantida/editada um código temporário garantidamente único (derivado do seu próprio `id`), só depois é que qualquer uma recebe o código final — nenhum estado intermédio pode colidir com a constraint. Sem alteração de comportamento visível para o caso comum (sem troca de códigos).

## [0.21.6] — 2026-07-30

### Adicionado

- **Fundação para a edição de instrumentos (Tarefa 2/6).** `InstrumentBuilder::update()` deixa de apagar todas as perguntas e recriá-las (o que falhava assim que qualquer nota já existisse) e passa a comparar o que já existe com o que foi submetido: uma pergunta mantida ou editada atualiza-se no lugar (mantém o `id` e as notas já lançadas), uma pergunta nova é criada, e uma pergunta removida só é aceite se não tiver nenhuma nota lançada — caso contrário a atualização inteira é rejeitada sem nada ser escrito. Baixar a cotação de uma pergunta abaixo da maior nota já lançada nela também é rejeitado. Sem nenhuma rota ou interface nova ainda — a lógica de negócio fica pronta para as próximas tarefas a ligarem.

## [0.21.5] — 2026-07-29

### Adicionado

- **Fundação para a edição de instrumentos (Tarefa 1/6).** Nova coluna `status_before_cancellation` em `instruments`, para uma futura anulação/reversão poder repor o estado exato anterior. `InstrumentItem` ganha `scores()`, para saber se uma questão já tem notas lançadas antes de a deixar remover ou baixar a cotação. Sem mudança de comportamento visível ainda — as próximas tarefas deste plano constroem a edição em cima disto.

## [0.21.4] — 2026-07-29

### Corrigido

- **A grelha de correção deixava lançar uma nota acima da cotação máxima de uma questão.** Nem o frontend nem o backend impediam isto — o `max` do campo numérico é só decorativo no HTML, e a validação do pedido em `InstrumentController::saveScores()` não tinha nenhum limite ligado à questão. `RecordScores::save()` passa agora a verificar, antes de abrir a transação, se cada nota lançada excede a cotação máxima da sua questão — se sim, nada é escrito e a exceção `ScoreExceedsMaximumException` chega ao professor como erro de validação. Aplica-se também a questões de bónus: bónus só isenta uma questão do denominador (§4.2), nunca sobe o teto da própria questão. Na grelha (`Grid.vue`), a célula com nota acima do máximo fica visualmente marcada (borda e texto a vermelho) e o botão "Guardar" fica desativado enquanto isso não for corrigido — feedback imediato em vez de só depois de tentar guardar.

### Alterado

- **Revisão da coluna "Apreciação Qualitativa" da grelha de classificação.** O comentário sobre `percentFor()`/`qualitativeLabelFor()` em `instruments/Grid.vue` afirmava "mirrors CalculationEngine" de forma demasiado ampla; passa a dizer exatamente o que é espelhado (a exclusão de itens de bónus do denominador e o casamento inclusivo de bandas) e o que não é (ponderação por domínio, elegibilidade/entrada tardia, `absence_mode` — regras que só o `CalculationEngine` aplica). O cabeçalho "Apreciação Qualitativa" ganha um tooltip a deixar claro que é um indicador só deste instrumento, não a classificação oficial da turma/período. `percentFor()` e `qualitativeLabelFor()` foram extraídas para um novo módulo puro `resources/js/lib/instrumentQualitativeRating.ts` — sem alteração de comportamento — para que a lógica fique isolada e trivialmente testável no dia em que este projeto adotar um test runner de frontend (ainda não existe nenhum).

## [0.21.2] — 2026-07-29

### Adicionado

- **Percentagem e apreciação qualitativa na grelha de classificação.** `instruments/Grid.vue` ganha uma coluna "Apreciação Qualitativa" e a célula do Total passa a mostrar também a percentagem. Ambas são calculadas no cliente, reativamente, à medida que o professor edita as classificações — sem precisar de recarregar a página. A percentagem usa como denominador só os itens já avaliados (nunca a cotação total fixa do instrumento), para que um item por avaliar nunca puxe a percentagem para baixo — a mesma regra "vazio não é zero" que já governa o total. A apreciação qualitativa lê as bandas da escala do perfil de avaliação da turma (`scaleBands`, enviado por `InstrumentController::show()`); mostra "—" sempre que nada está avaliado, ou quando a turma não tem perfil, ou o perfil usa uma escala sem bandas (ex.: "Escala 0 a 20") — nunca um rótulo adivinhado.

## [0.21.1] — 2026-07-29

### Corrigido

- **O emparelhamento de fotos por nome nunca batia certo com um ficheiro real do Intuitivo.** Verificado diretamente contra os ficheiros reais desta sessão: a folha de fotos em Word usa só primeiro+último nome nas legendas ("Afonso Mordomo"), enquanto a lista Excel traz o nome completo com nomes do meio ("Afonso Pito Mordomo") — a comparação por igualdade de string normalizada nunca via os dois como iguais, pelo que nenhuma das 30 fotos reais alguma vez associava. `RosterImportPreviewBuilder::findPhotoIndex()` passa a comparar por subsequência de palavras (as palavras de um nome aparecem, pela mesma ordem, dentro do outro) em vez de igualdade exata, testado nos dois sentidos para cobrir tanto a legenda abreviada como o nome completo. `normalize()` mantém-se inalterado para as outras utilizações (deteção de duplicados, verificação de já-inscrito) — só o emparelhamento de fotos muda.

## [0.21.0] — 2026-07-29

### Adicionado

- **Fotos para alunos já inscritos.** O detalhe da turma passa a aceitar um ficheiro Word de fotografias mesmo depois de a lista ter sido confirmada. O emparelhamento reutiliza a mesma normalização por nome da pré-visualização da importação, guarda cada correspondência em armazenamento permanente e mantém sem alteração os alunos sem fotografia correspondente. Ficheiros Word inválidos regressam ao formulário com erro de validação e a ação continua restrita aos professores da turma.
- **Ativação de turmas.** Uma turma em preparação apresenta agora a ação «Ativar turma» no cabeçalho. A ação atualiza o estado para ativa, o detalhe devolve simultaneamente o valor técnico e o rótulo traduzido, e o botão desaparece depois da ativação.

## [0.20.8] — 2026-07-29

### Alterado

- **A importação de lista de turma passa a ser duas fases sequenciais em vez de um único diálogo confuso.** O diálogo original perguntava tudo de uma vez — Excel, mais uma checkbox opcional "Queres associar fotos?" que revelava um segundo input de ficheiro Word — e os professores achavam pouco claro. `classes/Show.vue` agora só pede o Excel; `RosterImportController::store()` deixou de aceitar `photos` (a validação e o `PhotoFileParser` saíram deste método por completo) e a pré-visualização nasce sempre sem fotos. Um novo endpoint `POST classes/{class}/roster-imports/{token}/photos` (`RosterImportController::attachPhotos()`) anexa o Word DEPOIS, reaproveitando o mesmo `$token` — e faz o emparelhamento por nome contra os dados ATUAIS do cliente (`rows` no pedido), não contra uma cópia do Excel no servidor, para que correções de nome já feitas na pré-visualização não sejam ignoradas. `RosterImportPreviewBuilder` ganha `matchPhotosToRows()`, extraído para reutilizar a normalização de nome já existente em vez de duplicar a lógica de `build()`. `roster-imports/Preview.vue` ganha uma secção "Adicionar fotos" que envia o ficheiro Word mais `form.rows` via `router.post` (nunca axios) e volta a semear `form.rows` a partir das `props.rows` atualizadas no `onSuccess`, já que o `useForm` local não se re-deriva sozinho de props alteradas.

## [0.20.7] — 2026-07-29

### Corrigido

- **A confinação de `photo_temp_path` ao próprio token (0.20.4) ainda era contornável.** Uma revisão posterior mostrou que a verificação `str_starts_with($caminho, $pastaDoToken.'/')` podia ser enganada por um caminho como `roster-imports/{token}/../{outroToken}/0.jpg`: começa literalmente pelo prefixo exigido, mas o Flysystem resolve o `..` embutido dentro da própria raiz do disco (só rejeita travessia que escape da raiz), acabando por ler a foto de OUTRA importação. Em vez de tentar tornar a verificação de string mais esperta, `confirm()` deixa de aceitar qualquer caminho vindo do cliente: as linhas passam a indicar a foto só por `photo_index` (posição) e `photo_extension` (extensão, validada por allowlist alfanumérica — nunca pode conter `/` nem `.`), e é o próprio servidor que reconstrói o caminho a partir destes dois valores estreitos mais o `token` que já controla via rota. Sem `isWithinThisImportsTempFolder()` a decidir se confia ou não num caminho — deixou de existir caminho nenhum vindo de fora para confiar.

## [0.20.6] — 2026-07-29

### Adicionado

- **Correção manual de foto na pré-visualização da importação.** O desenho original exigia que o professor pudesse "trocar foto mal associada" ou associar uma foto a um aluno sem correspondência automática, mas nenhuma das 11 tarefas implementou isto — a pré-visualização só permitia incluir/excluir, editar nome e número. `RosterImportController::store()` passa agora também `photos` (todas as fotos extraídas do ficheiro Word, incluindo as que não bateram certo com nenhum nome). Em `roster-imports/Preview.vue`, cada linha ganha uma miniatura "sem foto" mais uma miniatura por cada foto ainda disponível para essa linha — uma foto já atribuída a OUTRA linha desaparece das opções até essa linha ser reatribuída, impedindo que duas linhas fiquem com a mesma foto.

## [0.20.5] — 2026-07-29

### Adicionado

- **Limpeza agendada das importações de turma abandonadas.** Um professor que carrega uma lista (com fotos) e nunca chega a confirmar deixava a pasta temporária (`storage/app/private/roster-imports/{token}/`), com fotografias reais de alunos, para sempre no disco — só `confirm()` alguma vez chamava a limpeza. Novo comando `roster-imports:prune` (agendado a correr de hora a hora em `routes/console.php`) remove pastas de importação com mais de 6 horas sem atividade. Cumpre a promessa já assumida no desenho («o ficheiro carregado não é retido indefinidamente») e no próprio docblock de `RosterImportTempStorage`.

## [0.20.4] — 2026-07-29

### Corrigido

- **Um erro de validação ao confirmar a importação destruía as fotos já carregadas.** `RosterImportController::confirm()` tinha `Gate::authorize()` e `$request->validate()` dentro do mesmo `try/finally` que apaga a pasta temporária do token — uma linha inválida (nome vazio, número de turma fora do intervalo) fazia a `finally` apagar as fotos antes da exceção de validação chegar ao professor. Ao corrigir e reenviar, todas as fotos falhavam silenciosamente. Autorização e validação passam agora a correr ANTES do `try/finally`; só o ciclo que processa as linhas (que precisa mesmo das fotos temporárias) fica protegido pela limpeza garantida.
- **`photo_temp_path` era um caminho controlado pelo cliente, usado sem confirmar que pertencia a este pedido.** Nada impedia (em teoria) que um pedido manipulado apontasse para a pasta temporária de OUTRO token e associasse a foto de outra importação a um aluno sem relação nenhuma. `confirm()` agora confirma que o caminho está confinado à pasta do próprio token (`roster-imports/{token}/...`) antes de o ler e copiar — um caminho fora daí é tratado como se não houvesse foto, nunca copiado.

## [0.20.3] — 2026-07-29

### Corrigido

- **`PhotoFileParser` emparelhava fotos e legendas errado no documento real do Intuitivo.** O algoritmo usava uma única variável "imagem pendente": ao processar um grupo de N imagens seguido de N legendas (a estrutura real da grelha de fotos do Intuitivo, ex. 6 fotos por linha), cada nova imagem sobrescrevia a pendente, descartando as anteriores — só a última imagem de cada grupo de 6 sobrevivia para ser emparelhada (incorretamente) com a primeira legenda seguinte. Confirmado diretamente contra o ficheiro real: 30 imagens e 30 legendas no documento, mas apenas 5 pares corretos produzidos. Substituída a variável única por uma fila FIFO: cada legenda reclama a imagem mais antiga ainda não emparelhada — comportamento idêntico ao anterior no caso alternado simples, correto também no caso agrupado. Nova fixture `DocxFixtureBuilder::buildGrouped()` reproduz a estrutura agrupada real para o teste de regressão.

## [0.20.2] — 2026-07-27

### Alterado

- **Removida a gama numérica redundante no dropdown de escalas.** O nome da escala já a inclui («Escala 0 a 20», «Escala 1 a 5») — o `(0 a 20)` acrescentado a seguir duplicava a informação. O `<option>` volta a mostrar só o nome.

## [0.20.1] — 2026-07-27

### Alterado

- **Rótulo da gama numérica no dropdown de escalas.** `(0.000 a 20.000)` passa a `(0 a 20)` — o cast `decimal:3` do backend mantém-se (precisão para cálculo), só a apresentação no `<option>` deixa de mostrar casas decimais desnecessárias.

## [0.20.0] — 2026-07-27

### Adicionado

- **Escalas no perfil de avaliação.** A escala de sistema «Escala 1 a 5» passa a ter as bandas normalizadas aprovadas e propõe os níveis Fraco, Insuficiente, Suficiente, Bom e Muito Bom sem retirar ao professor a confirmação final. O motor mantém-se puro e recebe as bandas como value objects.
- **Escalas personalizadas inline.** O formulário de perfil agrupa escalas de sistema e personalizadas, mostra a gama numérica e permite criar uma escala personalizada (nome, mínimo e máximo) num diálogo Inertia sem perder o rascunho já preenchido.

### Corrigido

- **`Scale` nunca aplicava isolamento de organização.** O método `bootScale()` seguia a convenção de arranque de *traits* (`boot{NomeDoTrait}`), mas «Scale» é o nome da própria classe, não de um trait usado por ela — o Eloquent nunca chamava este método. Na prática, o scope `scaleVisibility` nunca filtrava nada (todas as escalas de todas as organizações ficavam visíveis) e `organization_id` nunca era carimbado na criação. Exposto pelo primeiro teste real a criar uma escala personalizada pela aplicação. Corrigido com um `boot()` próprio (`parent::boot()` + registo do scope/hook), o padrão que o Eloquent efetivamente invoca.

## [0.19.6] — 2026-07-27

### Alterado

- **Hero da homepage** (`/`): título passa a "LAP-IS", com "Laboratório de Apoio ao Professor — Informação e Simplificação" e "O seu LÁPIS digital para avaliar, organizar e ensinar." como sub-mensagem. Cabeçalho da homepage mantém a tagline curta ("Mais tempo para ensinar"); o `AppLogo.vue` (páginas autenticadas) passa a mostrar a frase completa.

## [0.19.5] — 2026-07-27

### Corrigido

- **Boot da app crashava (500) se a password SMTP guardada não decifrasse.** `AppServiceProvider::applyPlatformMailSettings()` lia `mail_password` (cast `encrypted`) sem proteção — uma ciphertext que já não bate certo com a `APP_KEY` atual (chave rodada, ou a linha vir de outra base de dados) derrubava **todos** os pedidos, não só o envio de email. Agora decifra explicitamente com `Crypt::decryptString()` num `try/catch`: se falhar, regista o erro e usa o mail do `.env`, sem 500. O operador tem de voltar a guardar as definições de SMTP em `/admin` para o email voltar a funcionar.

## [0.19.4] — 2026-07-27

### Documentação

- **Guia do backoffice** ([docs/backoffice.md](docs/backoffice.md)): tornar-se admin (`lapis:make-admin`), gestão de contas, criar/provisionar, impersonar, e **configuração de SMTP**. Inclui o mapeamento Encriptação→porta→scheme e a config real de produção — `mail.criativatek.com` só escuta **587/STARTTLS** (465/SSL dá `Connection refused`; sondado do VPS). `status.md` e `README.md` atualizados.

## [0.19.3] — 2026-07-27

### Corrigido

- **Backoffice não mostrava toasts.** O `AdminLayout` não montava o `<Toaster />`, por isso o resultado do «Enviar email de teste» (e do «Guardar») disparava mas nunca aparecia. Adicionado — o teste de SMTP passa a reportar sucesso/erro visível.

## [0.19.2] — 2026-07-27

### Corrigido

- **SMTP SSL (porta 465) não enviava.** O override passava `ssl`/`tls` como `scheme`, mas o Symfony Mailer quer `smtps` (TLS implícito, 465) — caso contrário o envio falha. Mapeado: `ssl → smtps`; TLS/nenhuma usam STARTTLS (o default `null`).

## [0.19.1] — 2026-07-27

### Corrigido

- **SMTP: campo «Mailer» era um footgun.** Pedia-se um valor que devia ser sempre `smtp`, e pôr lá o host partia o envio. Removido — o mailer do sistema é sempre SMTP (o override fixa `mail.default=smtp`). O «Enviar email de teste» passa a aceitar um **destinatário** à escolha, para o teste chegar a um inbox real.

## [0.19.0] — 2026-07-27

### Adicionado

- **Backoffice — impersonação de suporte (Slice 5).** O admin da plataforma vê a app como um professor para dar apoio: botão «Impersonar» no detalhe da conta, **banner âmbar** persistente («A ver a app como X — Terminar») e regresso à sua sessão. **Nunca impersona outro admin.** Início e fim **auditados** na trilha da org-alvo — é a ação mais sensível.

## [0.18.0] — 2026-07-27

### Adicionado

- **Backoffice — definições de SMTP (Slice 4).** O operador configura o **email do sistema** (verificação, reset, convites) a partir do backoffice: tabela `platform_settings` (linha única, password **cifrada**) que **sobrepõe o `.env`** em runtime (no boot). Página com o formulário SMTP (password write-only — em branco mantém a guardada) e botão **«Enviar email de teste»**. Desbloqueia o registo self-service assim que houver um SMTP real.

## [0.17.0] — 2026-07-27

### Adicionado

- **Backoffice — criar/provisionar conta (Slice 3).** O operador cria um professor (ou escola) a partir do backoffice: nome, email, password (opcional — gera uma temporária se em branco) e plano. Reutiliza `CreatePersonalOrganization` (user + org + subscrição), aplica o plano escolhido e marca o email verificado (contas provisionadas saltam a verificação). Audita `admin.account_created`.

## [0.16.0] — 2026-07-27

### Adicionado

- **Backoffice — gestão de contas (Slice 2).** Página de detalhe de cada organização com ações do operador: **verificar email** do dono, **mudar plano** (Base/Pro/Institucional), **suspender/reativar** subscrição, e **conceder/revogar** admin da plataforma. Mostra dono, subscrição e módulos ativos resolvidos. Cada ação fica na **trilha de auditoria da org-alvo** (via `runFor`).

### Corrigido

- **Resolução de entitlements não-determinística:** duas subscrições com o mesmo `starts_at` (ex.: mudar de plano no mesmo segundo do registo) podiam resolver para a antiga. `Entitlements::resolve` (e as queries do backoffice) passam a desempatar por `id` — a subscrição mais recente ganha sempre.

## [0.15.0] — 2026-07-27

### Adicionado

- **Backoffice de plataforma — fundação + lista de contas (Slice 1).** Área `/admin` para o operador do SaaS, fora do scope de tenant: coluna `is_platform_admin` em `users` (nunca mass-assignable), middleware `platform-admin` (403 para professores), comando `php artisan lapis:make-admin <email> [--revoke]`, e `AdminLayout` próprio. Primeira página: lista de **todas** as organizações (cross-org) com dono, plano, estado da subscrição e verificação de email — paginada e pesquisável.

## [0.14.1] — 2026-07-23

### Documentação

- **Onboarding portável para um novo contribuidor:** `README.md` (porta de entrada), `docs/workflow.md` (fluxo assistido por IA — equipa de agentes, Codex ativo, loop por slice, armadilhas SQLite-local vs MySQL-CI), `docs/status.md` (feito / bloqueado / falta), `docs/deployment.md` (CloudPanel · lapis.criativatek.com).
- **Orquestrador no repo:** os 4 agentes (`explorer`/`architect`/`implementer`/`reviewer`) passam a viver em `.claude/agents/` — `reviewer` usa `sonnet` (**sem Fable neste projeto**). `AGENTS.md` na raiz para o Codex. Secção «Orquestração & fluxo de IA» no `CLAUDE.md`.

## [0.14.0] — 2026-07-23

### Adicionado

- **Intervenções (§14).** Medidas de apoio por aluno com ciclo de vida (nova → em curso → concluída/cancelada) e apreciações periódicas de eficácia (sem efeito / parcialmente eficaz / eficaz / inconclusivo). Ligadas à inscrição e, opcionalmente, a um domínio; podem marcar-se para o relatório. **Nunca entram no cálculo** (§14.3). Concluir carimba a data de conclusão. Item «Intervenções» do menu passa a construído.

## [0.13.0] — 2026-07-23

### Adicionado

- **Autoavaliação (§15).** O aluno reflete por domínio, em entrevista com o professor: uma pergunta de escala 1–5 por domínio do perfil, e uma reflexão. **Comparada com a avaliação, nunca somada a ela** (§14/§15) — o resultado calculado de cada domínio aparece ao lado da autoavaliação, mas não há FK para os resultados. Template derivado automaticamente dos domínios da turma. Item «Autoavaliações» do menu passa a construído.

## [0.12.0] — 2026-07-23

### Adicionado

- **Registos / Evidências (§14).** O diário de bordo do professor: entradas qualitativas por turma (10 tipos — participação, ocorrência, comportamento, contacto…), opcionalmente sobre um aluno e um domínio, com data e a opção «incluir no relatório». **Nunca entra no cálculo** (§14.3): não tem peso nem FK para resultados. Soft delete. Um registo dirigido tem de apontar para um aluno da própria turma e um domínio da própria disciplina. Item «Registos» do menu passa a construído.

## [0.11.0] — 2026-07-23

### Adicionado

- **Trilha de auditoria (§22.4).** Tabela `audit_events` imutável e tenant-scoped: os eventos que tocam o registo de um aluno deixam uma linha com o autor, o momento e o contexto (valores, motivo). Emitidos pelos serviços que detêm cada ação — confirmação e alteração de classificação (com o motivo), publicação (evento único em lote), migração de perfil, ativação de versão, exportação de pauta (§22.5). Página «Registo de atividade» só-leitura.
- Decisão: tabela própria em vez de `spatie/laravel-activitylog` — o requisito é isolamento por organização + eventos de domínio (não diff de modelos), e uma tabela focada encaixa exato sem dependência nova.

## [0.10.0] — 2026-07-23

### Adicionado

- **Relatórios — pauta de classificações.** Por turma, a pauta dos alunos × períodos com as classificações **decididas** (confirmadas ou publicadas); uma proposta nunca aparece como nota. Imprimível (só a pauta, sem o shell) e exportável em CSV (com BOM UTF-8 para os acentos abrirem bem no Excel). Item «Relatórios» do menu passa a construído.

## [0.9.0] — 2026-07-22

### Adicionado

- **Painel do Professor.** Substitui o placeholder do starter kit por um painel real: saudação, resumo (turmas, classificações por confirmar, por publicar) e cartões por turma com as pendências e atalhos para Classificações/Resultados. Só mostra trabalho já criado — não decide nada. Agregação numa única query (join a `enrollments`), scoped à turma e à organização.

## [0.8.1] — 2026-07-22

### Corrigido (revisão independente da publicação + migração)

- **Bypass de autorização na publicação (P0):** `PublishClassifications` filtrava por período+âmbito mas não por turma; como os períodos pertencem ao ano letivo, publicar uma turma publicava as classificações confirmadas de outra turma do mesmo ano (mesmo de outro professor). Passa a filtrar por `enrollment_id` da turma. Teste com duas turmas no mesmo período.
- **Pré-visualização da migração mentia (P0):** mostrava «antes→depois, alterado» para classificações confirmadas/publicadas que a migração deixa intactas. Agora cada célula tem estado — `mantida` (decisão congelada), `sem proposta`, ou `recalculada` (antes/depois) — e só as recalculáveis contam para os totais.
- **Migração criava propostas não mostradas:** o re-cálculo passa a `refreshOnly` — só atualiza propostas em aberto existentes, nunca cria novas que o professor não viu na pré-visualização.
- **Concorrência:** confirmação de migração e publicação correm agora sob `lockForUpdate` com re-verificação do estado dentro da transação (evita registos de auditoria contraditórios e escritas cegas).
- Contagens da pré-visualização, do registo e do toast agora coincidem (vêm todas do mesmo documento). Guard `under_review` restrito aos instrumentos que contam.

## [0.8.0] — 2026-07-22

### Adicionado

- **Migração de perfil auditável (§10.2, A4).** Uma turma com resultados só muda de versão de perfil através de uma migração registada: pré-visualização por aluno do valor antes/depois em cada período, confirmação com motivo obrigatório, e um registo `class_profile_migrations` imutável.
  - Ativar uma nova versão **não** migra as turmas automaticamente — elas continuam na versão que produziu os seus resultados até uma migração explícita.
  - Ao migrar, as propostas em aberto recalculam sob a nova versão; as classificações confirmadas ou publicadas mantêm-se na versão antiga (o histórico não é recalculado).
  - `ClassController::updateProfile` passa a encaminhar para a migração quando a turma já tem decisões, em vez de trocar a versão em silêncio.

### Corrigido

- `MigrateClassProfile`: a relação `profileVersion` em cache era usada após a troca da FK, recalculando sob a versão antiga. Passa a apontar para a nova versão antes de re-propor.

## [0.7.0] — 2026-07-22

### Adicionado

- **Publicação da classificação (§13.2).** Botão «Publicar confirmadas»: as classificações confirmadas passam a `published` (comunicadas). Publicar **não recalcula nada** — só muda o estado e `published_at`.
  - Um elemento `under_review` (reclamação pendente) **bloqueia** a publicação da classificação desse período (§5); a contagem de retidas é reportada ao professor.

## [0.6.0] — 2026-07-22

### Adicionado

- **Resultado acumulado (§6.3, Q4).** Toggle «Por período / Acumulado» nas classificações. Com `accumulated_mode = all_valid_year_elements`, o motor reprocessa os elementos brutos de todos os períodos contribuintes até ao período em causa — **não** a média das médias dos períodos (que sobreponderaria os primeiros elementos).
  - Período e acumulado são decisões distintas para o mesmo (aluno, período): coexistem na tabela por âmbito.
  - O ingresso tardio atravessa o acumulado corretamente: o aluno é avaliado só pelos elementos que o alcançam (Filipe: só o 2.º período; nunca um zero).
  - `ClassResultsCalculator::forScope/forAccumulated`; proposta e confirmação passam a ser scope-aware.
  - DemoDataSeeder ganha um instrumento no 2.º período para o acumulado ter conteúdo.

## [0.5.0] — 2026-07-22

### Adicionado

- **Decisão de classificação (§7).** O motor propõe, o professor confirma. Tabelas `classifications` e `calculation_snapshots`, serviços `ProposeClassifications` e `ConfirmClassification`, e a página por turma/período.
  - A proposta determinística nunca é sobrescrita; o valor final do professor vive ao lado dela.
  - Alterar a proposta exige um motivo — garantido por CHECK em MySQL (`<=>` null-safe) **e** ao nível do serviço (cenário A10). Verificado com CHECK real em MySQL.
  - A confirmação congela um `calculation_snapshot`: cópias literais dos inputs, versão das regras, explicação estruturada e `payload_hash` SHA-256. Nunca atualizado.
  - Alunos sem resultado calculável (ausência total, ingresso tardio) **não** recebem proposta — nunca um zero.
  - Uma só classificação viva por (aluno, período, âmbito) via coluna gerada `status_active_flag`.
- **Página inicial LÁPIS.** Substitui o ecrã do starter kit Laravel; marca navy + âmbar, tema claro/escuro.

### Corrigido

- CHECK do A10 no `domain-model.md` estava incompleto: rejeitava o próprio estado `proposed` (`final_value` nulo vs proposta preenchida). Corrigido para admitir "sem decisão final" com ambas as colunas `final_*` nulas.
