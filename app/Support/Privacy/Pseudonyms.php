<?php

namespace App\Support\Privacy;

/**
 * Names out before text leaves the building, names back after it returns.
 *
 * PSEUDONYMISATION, AND THE WORD IS CHOSEN CAREFULLY (§5 of the AI Core brief).
 * This is not anonymisation and must never be called that. «Aluno A» is
 * reversible — this very class reverses it, three lines below — and the mapping
 * that reverses it exists in memory on this machine for the duration of one
 * request. Under Article 4(5) GDPR that is pseudonymised personal data: the
 * remote system holds a letter, the responsibility stays here, and calling it
 * «anonymous» in a privacy notice would be a false statement to a data subject.
 *
 * WHY IT LIVES IN Support\Privacy AND NOT IN THE FEATURE THAT FIRST NEEDED IT.
 * The algorithm below was written for `App\Services\Reporting\Writing\PseudonymMap`,
 * which still exists and still owns the Reporting-specific question of WHICH
 * names a report is about (its roster, plus the student an individual report
 * concerns). What it no longer owns is HOW a name becomes a pseudonym, because
 * the AI core needs the same rules for material that has nothing to do with a
 * report. One implementation, two entry points — `PseudonymMap` delegates here
 * and its public behaviour is unchanged.
 *
 * THE RULES, AND WHY EACH IS A COMPROMISE:
 *
 *   Longest first          «Maria Silva Costa» is substituted before «Maria», so
 *                          a full name is never half-replaced.
 *
 *   First names included   A teacher writes «a Maria», not «a Maria Silva
 *                          Costa». A map that only knew full names would cover
 *                          almost nothing of what people actually type.
 *
 *   Short tokens excluded  «Ana» is a name; so is the risk of replacing a
 *                          preposition. Single tokens under four characters are
 *                          left to the whole-word boundary and to the notice on
 *                          the screen, rather than mangling every sentence
 *                          containing «dos».
 *
 *   Whole words only       A name that is a substring of an ordinary word is not
 *                          a name in that word.
 *
 * WHAT IT CANNOT DO, WRITTEN DOWN RATHER THAN HOPED AWAY. It only knows the
 * names it was given. A sibling, a colleague, a student from another class typed
 * into a free-text field is a name this map has never seen. That is why
 * `AiPayloadSanitizer` runs pattern-based identifier removal AFTER this, why it
 * verifies rather than assumes, and why the screens that can carry names say so
 * out loud.
 */
final readonly class Pseudonyms
{
    /** Shorter than this and a name token is left alone. See the class docblock. */
    private const MINIMUM_TOKEN_LENGTH = 4;

    /**
     * AS PALAVRAS QUE SEGURAM O GÉNERO, e o que fica no lugar delas (§42).
     *
     * Vazio significa «desaparece»: «o aluno A melhorou» é «Álvaro Simões
     * melhorou». As contrações voltam à preposição sozinha, que é neutra — e
     * nenhuma delas exige saber o género de quem quer que seja, que é
     * precisamente o ponto.
     *
     * @var array<string, string>
     */
    private const CONNECTORS = [
        'o' => '', 'a' => '', 'os' => '', 'as' => '',
        'um' => '', 'uma' => '', 'uns' => '', 'umas' => '',
        'do' => 'de', 'da' => 'de', 'dos' => 'de', 'das' => 'de',
        'ao' => 'a', 'à' => 'a', 'aos' => 'a', 'às' => 'a',
        'no' => 'em', 'na' => 'em', 'nos' => 'em', 'nas' => 'em',
        'pelo' => 'por', 'pela' => 'por', 'pelos' => 'por', 'pelas' => 'por',
    ];

    /**
     * PRIVATE, LIKE `SanitisedPayload`'s, AND FOR THE SAME REASON. A map handed
     * in from outside is a map nobody built by the rules above — it could be
     * empty when it should not be, or map a name to itself. `of()` and `none()`
     * are the two ways in, and `none()` says «no people are involved here» out
     * loud rather than by passing an empty array that might be an oversight.
     *
     * @param  array<string, string>  $byName  the real name => «Aluno A»
     * @param  string  $prefix  the word the pseudonyms are built on — «Aluno»
     * @param  array<string, string>  $restoreAs  «Aluno A» => the name to put back
     */
    private function __construct(
        public array $byName,
        private string $prefix = 'Aluno',
        private array $restoreAs = [],
    ) {}

    /** Nothing to substitute. */
    public static function none(): self
    {
        return new self([]);
    }

    /**
     * Build a map from a list of real names.
     *
     * The pseudonym is positional and ephemeral: the first name given becomes
     * «Aluno A», the second «Aluno B». It carries no information about the
     * person and is not stable between requests — two calls about the same class
     * in a different order produce different letters, which is the intended
     * property. A stable pseudonym is a pseudonymous identifier, and a
     * pseudonymous identifier accumulated across requests is a profile.
     *
     * @param  list<string>  $names
     */
    public static function of(array $names, string $prefix = 'Aluno'): self
    {
        $map = [];
        $next = 'A';

        foreach (array_values(array_unique(array_map('trim', $names))) as $name) {
            if ($name === '') {
                continue;
            }

            $pseudonym = $prefix.' '.$next;
            $next++;

            $map[$name] = $pseudonym;

            foreach (preg_split('/\s+/u', $name) ?: [] as $part) {
                if (mb_strlen($part) >= self::MINIMUM_TOKEN_LENGTH && ! array_key_exists($part, $map)) {
                    $map[$part] = $pseudonym;
                }
            }
        }

        // Longest first, so «Maria Silva Costa» is never half-replaced by the
        // entry for «Maria».
        uksort($map, fn (string $left, string $right): int => mb_strlen($right) <=> mb_strlen($left));

        return new self($map, $prefix, self::fullestNames($map));
    }

    /**
     * A MESMA SUBSTITUIÇÃO, MAS A DEVOLVER «Álvaro Simões» EM VEZ DE «Álvaro
     * Simões Ribeiro da Costa» (§40).
     *
     * QUEM PEDE ISTO É QUEM ESCREVE PARA SER LIDO. Uma análise de turma que
     * enumere seis alunos pelo nome completo é ilegível; primeiro e último nome
     * é como uma pessoa chama outra numa reunião de conselho de turma, e é a
     * forma que o professor reconhece.
     *
     * QUEM NÃO PEDE ISTO É QUEM REESCREVE O QUE O PROFESSOR JÁ TINHA ESCRITO.
     * Numa ocorrência disciplinar ou num relatório, o texto de partida é dele;
     * encurtar-lhe os nomes seria editar-lhe a prosa a pretexto de a devolver.
     *
     * A CORRESPONDÊNCIA NÃO MUDA — o que muda é só o que se põe de volta. O mapa
     * continua a conhecer o nome completo e todos os seus elementos, e por isso
     * continua a apanhá-los todos à saída.
     */
    public function restoringShortNames(): self
    {
        $short = [];

        foreach ($this->restoreAs as $pseudonym => $name) {
            $short[$pseudonym] = self::shorten($name);
        }

        return new self($this->byName, $this->prefix, $short);
    }

    /**
     * «Álvaro Simões Ribeiro da Costa» → «Álvaro Simões».
     *
     * O PRIMEIRO E O ÚLTIMO, e não o primeiro e o segundo: é o apelido que
     * distingue dois «Álvaro» na mesma turma, e é ele que uma pauta escreve.
     * Um nome de um só elemento fica como está — não há o que encurtar.
     */
    protected static function shorten(string $name): string
    {
        $parts = array_values(array_filter(preg_split('/\s+/u', trim($name)) ?: []));

        if (count($parts) <= 1) {
            return trim($name);
        }

        return $parts[0].' '.$parts[count($parts) - 1];
    }

    /**
     * pseudonym => the fullest name that maps to it.
     *
     * @param  array<string, string>  $byName
     * @return array<string, string>
     */
    protected static function fullestNames(array $byName): array
    {
        $names = [];

        foreach ($byName as $name => $pseudonym) {
            if (! array_key_exists($pseudonym, $names) || mb_strlen($name) > mb_strlen($names[$pseudonym])) {
                $names[$pseudonym] = $name;
            }
        }

        return $names;
    }

    public function isEmpty(): bool
    {
        return $this->byName === [];
    }

    /** Replace every name this map knows with its pseudonym. */
    public function apply(string $text): string
    {
        foreach ($this->byName as $name => $pseudonym) {
            $text = (string) preg_replace($this->pattern($name), $pseudonym, $text);
        }

        return $text;
    }

    /**
     * PÔR OS NOMES DE VOLTA — e não deixar nenhum pseudónimo chegar ao professor.
     *
     * O QUE ESTAVA ERRADO, E PORQUE ERA GRAVE. A substituição era um
     * `str_replace('Aluno E', …)`, e um modelo não escreve «Aluno E e Aluno F»:
     * escreve «com exceção dos alunos E e F». O prefixo aparece uma vez, no
     * plural, e as letras ficam soltas — nenhuma das duas formas casava com a
     * procura, e o professor via na análise da sua turma uma frase sobre
     * «alunos E e F» que não identifica ninguém e não serve para nada (§39).
     *
     * O QUE ESTA VERSÃO RECONHECE, e cada caso tem o seu teste:
     *
     *   Aluno A          →  Álvaro Simões
     *   aluno A          →  Álvaro Simões
     *   alunos A e B     →  Álvaro Simões e Marta Tomás
     *   Alunos A, B e C  →  Álvaro Simões, Marta Tomás e João Dias
     *
     * E A PARTE QUE NÃO É ÓBVIA: O ARTIGO SAI COM O PSEUDÓNIMO (§42). «o aluno
     * E» é masculino porque «aluno» é masculino, não porque a pessoa o seja;
     * trocar só o pseudónimo produziria «o Marta Tomás», que é exatamente a
     * frase que o produto não pode escrever. Não se infere género nenhum — o
     * artigo simplesmente desaparece, e as contrações que o levam dentro voltam
     * à preposição que são: «dos alunos E e F» → «de Álvaro Simões e Marta
     * Tomás», «ao aluno A» → «a Álvaro Simões».
     *
     * UMA LETRA SOLTA NUNCA É TOCADA. A procura exige o prefixo à frente, e por
     * isso um «E» no meio de uma frase — ou a conjunção «e» — continua a ser o
     * que era (§41). E uma lista com uma letra que este mapa não conhece fica
     * inteira como estava: substituir metade seria pior do que não substituir.
     */
    public function rehydrate(string $text): string
    {
        $text = (string) preg_replace_callback(
            $this->listPattern(),
            fn (array $matches): string => $this->restoreList($matches),
            $text,
        );

        // A REDE DE SEGURANÇA, para tudo o que a procura acima não previu: um
        // pseudónimo escrito de uma forma que ninguém antecipou continua a ser
        // trocado pelo nome, porque o que não pode acontecer de maneira nenhuma
        // é chegar «Aluno E» ao ecrã.
        foreach ($this->restoreAs as $pseudonym => $name) {
            $text = str_replace($pseudonym, $name, $text);
        }

        return $text;
    }

    /**
     * «[o|dos|ao|…] Aluno[s] A[, B][ e C]», com o prefixo insensível a
     * maiúsculas e as letras NÃO.
     *
     * AS LETRAS TÊM DE SER MAIÚSCULAS, e isso não é uma preferência. Um
     * pseudónimo é sempre «Aluno E» com E maiúsculo; aceitar minúsculas faria
     * «o aluno e o professor» ser lido como o pseudónimo «Aluno E» seguido de
     * «o professor», e a frase ficaria destruída.
     */
    protected function listPattern(): string
    {
        $connectors = implode('|', array_map(
            fn (string $connector): string => preg_quote($connector, '/'),
            // Mais longos primeiro: «dos» tem de ser tentado antes de «do».
            $this->connectorsByLength(),
        ));

        return '/(?:(?<![\p{L}\p{N}])(?<connector>(?i:'.$connectors.'))\s+)?'
            .'(?<![\p{L}\p{N}])(?i:'.preg_quote($this->prefix, '/').')s?\s+'
            .'(?<letters>[A-Z](?:(?:\s*,\s*|\s+e\s+)[A-Z])*)'
            .'(?![\p{L}\p{N}])/u';
    }

    /**
     * @return list<string>
     */
    protected function connectorsByLength(): array
    {
        $connectors = array_keys(self::CONNECTORS);

        usort($connectors, fn (string $left, string $right): int => mb_strlen($right) <=> mb_strlen($left));

        return $connectors;
    }

    /**
     * Uma correspondência inteira, trocada pelos nomes que ela nomeia.
     *
     * @param  array<int|string, string>  $matches
     */
    protected function restoreList(array $matches): string
    {
        $letters = preg_split('/\s*,\s*|\s+e\s+/u', $matches['letters']) ?: [];
        $names = [];

        foreach ($letters as $letter) {
            $name = $this->restoreAs[$this->prefix.' '.$letter] ?? null;

            // UMA LETRA DESCONHECIDA DEIXA A FRASE INTEIRA COMO ESTAVA. Trocar
            // metade de uma enumeração produziria uma frase que mistura nomes e
            // pseudónimos, que é pior do que a frase original.
            if ($name === null) {
                return $matches[0];
            }

            $names[] = $name;
        }

        return $this->connectorFor($matches['connector'] ?? '').$this->enumerate($names);
    }

    /**
     * O que fica no lugar do artigo — nada, ou a preposição que a contração
     * escondia. A maiúscula do original é preservada quando sobra palavra para a
     * levar: «Dos alunos A e B» abre uma frase, e «de» com minúscula no início
     * de uma frase seria um erro novo em vez do que se veio corrigir.
     */
    protected function connectorFor(string $connector): string
    {
        if ($connector === '') {
            return '';
        }

        $replacement = self::CONNECTORS[mb_strtolower($connector)] ?? '';

        if ($replacement === '') {
            return '';
        }

        if (mb_strtoupper(mb_substr($connector, 0, 1)) === mb_substr($connector, 0, 1)) {
            $replacement = mb_strtoupper(mb_substr($replacement, 0, 1)).mb_substr($replacement, 1);
        }

        return $replacement.' ';
    }

    /**
     * «A», «A e B», «A, B e C» — a enumeração como se escreve em português, e
     * não uma lista separada por vírgulas até ao fim.
     *
     * @param  list<string>  $names
     */
    protected function enumerate(array $names): string
    {
        if (count($names) === 1) {
            return $names[0];
        }

        $last = array_pop($names);

        return implode(', ', $names).' e '.$last;
    }

    /**
     * Whether any name this map knows about survived the substitution.
     *
     * ASSERTED RATHER THAN ASSUMED. The substitution is a regex over text this
     * code did not write, and «it cannot happen» is not something to find out
     * from a support ticket.
     */
    public function coversEverythingIn(string $text): bool
    {
        foreach (array_keys($this->byName) as $name) {
            if (preg_match($this->pattern($name), $text) === 1) {
                return false;
            }
        }

        return true;
    }

    /** Whole-word, Unicode-aware, and not fooled by accents or by punctuation. */
    protected function pattern(string $name): string
    {
        return '/(?<![\p{L}\p{N}])'.preg_quote($name, '/').'(?![\p{L}\p{N}])/u';
    }
}
