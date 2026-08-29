<?php

namespace App\Console\Commands;

use App\Actions\Admin\SetTestAccount;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use Illuminate\Support\Carbon;

/**
 * A decisão humana, aplicada às contas que já existem — uma a uma e com rasto.
 *
 * O pré-voo comercial parou o deploy da 0.90.0 sobre onze subscrições em vigor
 * sem condição registada. O responsável do produto respondeu à pergunta que o
 * portão fazia: **não existe cliente pagante nenhum**, todas as contas atuais
 * foram criadas para testes, incluindo as de dois professores parceiros
 * convidados para experimentar. Isto escreve essa resposta na base de dados.
 *
 * PORQUÊ UM COMANDO E NÃO QUINZE VISITAS AO BACKOFFICE. O botão existe e é o
 * caminho normal — mas repetir a mesma decisão quinze vezes à mão é como se
 * perde uma. Cada organização é marcada pela MESMA `SetTestAccount` que o botão
 * usa, e por isso cada uma tem o seu próprio evento de auditoria, com o mesmo
 * operador, o mesmo antes/depois e a mesma nota. Não há caminho de escrita novo.
 *
 * `--created-before` É OBRIGATÓRIO, e é a trava que faz este comando ser seguro
 * de existir. «Todas as contas atuais» é uma frase cuja verdade caduca: corrido
 * daqui a um ano, sem data, isto marcaria como teste os clientes reais que
 * entretanto tivessem aderido. Com o instante, o alcance fica congelado no
 * momento em que a decisão foi tomada, e um registo público feito depois é
 * estruturalmente inalcançável.
 *
 * UM INSTANTE, LIDO À LETRA — e não «esse dia todo». A primeira versão disto
 * expandia uma data à seca para o fim do dia, e um ensaio apanhou-a em
 * flagrante: com `--created-before=2026-08-29`, uma conta criada às 23:55 desse
 * mesmo dia — depois da decisão, portanto — era marcada como de teste. Uma data
 * expandida é um alvo móvel dentro do próprio dia, exatamente o acidente que
 * esta trava existe para impedir. Agora `2026-08-29` significa
 * `2026-08-29 00:00:00` e a comparação é ESTRITAMENTE ANTERIOR: para abranger o
 * dia 29 inteiro escreve-se `2026-08-30`, ou um instante com horas. O comando
 * imprime sempre o instante que resolveu, para que ninguém tenha de adivinhar.
 *
 * E RECUSA UM CUTOFF NO FUTURO. Uma classificação histórica que alcança contas
 * que ainda não existem não é histórica — é uma armadilha à espera do próximo
 * cliente. Se o instante pedido é posterior a agora, o comando não corre.
 *
 * NÃO INFERE NADA. Não olha para emails, domínios, nomes, planos, ids nem
 * pagamentos. O critério é «existia antes desta data» — que não é uma dedução
 * sobre a natureza da conta, é o âmbito que o operador escreveu.
 *
 * NÃO TOCA NA SUBSCRIÇÃO. Nem plano, nem versão, nem estado, nem condição
 * comercial, nem preço, nem prazo. Depois de correr, `commercial_condition` e
 * `contracted_price_cents` continuam NULL em todas elas — porque continua a não
 * existir contrato nenhum, que era precisamente o ponto.
 */
class MarkTestAccounts extends Command
{
    use ConfirmableTrait;

    protected $signature = 'lapis:mark-test-accounts
        {--operator= : Email do administrador de plataforma que toma a decisão}
        {--created-before= : Só organizações criadas ESTRITAMENTE ANTES deste instante (YYYY-MM-DD ou YYYY-MM-DD HH:MM:SS; uma data à seca é a meia-noite desse dia)}
        {--note= : Porquê, nas palavras do operador}
        {--force : Não pedir confirmação}';

    protected $description = 'Mark the organizations that existed before a given date as test accounts, one audited decision each';

    public function handle(SetTestAccount $setTestAccount): int
    {
        $operator = $this->resolveOperator();

        if ($operator === null) {
            return self::FAILURE;
        }

        $cutoff = $this->resolveCutoff();

        if ($cutoff === null) {
            return self::FAILURE;
        }

        // `is_test_account = false` e não todas: correr isto duas vezes não
        // escreve nada da segunda, e não enche a auditoria de eventos que não
        // mudaram coisa nenhuma.
        $this->components->twoColumnDetail('<fg=gray>Instante-limite resolvido</>', $cutoff->toDateTimeString());

        $alvos = Organization::query()
            ->where('is_test_account', false)
            ->where('created_at', '<', $cutoff)
            ->orderBy('id')
            ->get();

        if ($alvos->isEmpty()) {
            $this->components->info('Nenhuma organização por marcar antes de '.$cutoff->toDateTimeString().'. Nada a fazer.');

            return self::SUCCESS;
        }

        // Sem emails nem nomes: uma lista de contas num log de deploy é PII que
        // não é precisa para decidir. O ULID chega para auditar depois.
        $this->table(
            ['ULID', 'Tipo', 'Criada em'],
            $alvos->map(fn (Organization $organization): array => [
                $organization->ulid,
                $organization->type->value,
                $organization->created_at?->toDateTimeString() ?? '—',
            ])->all(),
        );

        $this->components->warn(
            $alvos->count().' organização(ões) passam a conta de teste, decididas por '.$operator->email.'. '
            .'Não muda plano, versão do plano, estado, módulos, acesso, condição comercial nem preço.'
        );

        if (! $this->confirmToProceed('Marcar estas organizações como contas de teste?', fn (): bool => true)) {
            return self::FAILURE;
        }

        $note = $this->option('note');

        foreach ($alvos as $organization) {
            $setTestAccount->set($organization, $operator, true, is_string($note) ? $note : null);
            $this->components->twoColumnDetail($organization->ulid, '<fg=green>marcada</>');
        }

        $this->components->info($alvos->count().' organização(ões) marcadas como conta de teste, cada uma com o seu evento de auditoria.');

        return self::SUCCESS;
    }

    /**
     * O operador, que tem de ser uma pessoa real e administradora — a auditoria
     * regista QUEM decidiu, e «a consola» não é ninguém.
     */
    protected function resolveOperator(): ?User
    {
        $email = $this->option('operator');

        if (! is_string($email) || trim($email) === '') {
            $this->components->error('Falta --operator=<email>. A auditoria tem de registar quem tomou a decisão.');

            return null;
        }

        $operator = User::query()->where('email', trim($email))->first();

        if ($operator === null) {
            $this->components->error('Não existe utilizador com esse email.');

            return null;
        }

        if (! $operator->is_platform_admin) {
            $this->components->error('Esse utilizador não é administrador de plataforma.');

            return null;
        }

        return $operator;
    }

    /**
     * A data-limite, obrigatória. Ver o docblock da classe: é o que impede este
     * comando de um dia varrer clientes reais que ainda não existem.
     */
    protected function resolveCutoff(): ?Carbon
    {
        $raw = $this->option('created-before');

        if (! is_string($raw) || trim($raw) === '') {
            $this->components->error('Falta --created-before. Sem instante, «todas as contas atuais» caduca e passa a apanhar clientes futuros.');

            return null;
        }

        try {
            // À LETRA, sem expandir para o fim do dia. Ver o docblock da classe:
            // uma data expandida apanhava contas criadas depois da decisão, no
            // mesmo dia. `2026-08-29` é a meia-noite; para o dia 29 inteiro
            // escreve-se `2026-08-30`.
            $cutoff = Carbon::parse(trim($raw));
        } catch (\Throwable) {
            $this->components->error('Instante inválido em --created-before. Use YYYY-MM-DD ou YYYY-MM-DD HH:MM:SS.');

            return null;
        }

        if ($cutoff->isFuture()) {
            $this->components->error(
                'O instante-limite ('.$cutoff->toDateTimeString().') é posterior a agora. Uma classificação '
                .'histórica que alcança contas que ainda não existem é uma armadilha à espera do próximo cliente.'
            );

            return null;
        }

        return $cutoff;
    }
}
