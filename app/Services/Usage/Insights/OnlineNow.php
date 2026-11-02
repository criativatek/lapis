<?php

namespace App\Services\Usage\Insights;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Quem tem uma sessão iniciada neste momento («Ligados agora»).
 *
 * Lê a tabela `sessions` (driver `database`) e mais nada: não há recolha nova,
 * evento nem histórico — quando a sessão expira, a pessoa deixa de aparecer. Uma
 * sessão viva é a que teve atividade dentro de `session.lifetime`; «ativo agora»
 * é a janela curta de {@see self::ACTIVE_WINDOW_MINUTES}.
 *
 * Exclui as mesmas pessoas que o resto do painel (anonimizadas, em oposição à
 * recolha, em encerramento) e o acesso técnico: uma sessão de impersonação é do
 * suporte a trabalhar na conta de outra pessoa, e essa pessoa não está «ligada».
 * A sessão própria do técnico conta como dele.
 */
final class OnlineNow
{
    /** Atividade dentro desta janela quer dizer «ativo agora». */
    public const ACTIVE_WINDOW_MINUTES = 15;

    /**
     * @return array{as_of: string, window_minutes: int, session_minutes: int, active_count: int, people: list<array{ulid: string|null, name: string, email: string, internal: bool, minutes_ago: int, active: bool}>}
     */
    public function build(CarbonImmutable $now): array
    {
        $sessionMinutes = (int) config('session.lifetime');
        $nowTimestamp = $now->getTimestamp();

        $rows = DB::table('sessions')
            ->join('users', 'users.id', '=', 'sessions.user_id')
            ->whereNotNull('sessions.user_id')
            ->where('sessions.last_activity', '>=', $now->subMinutes($sessionMinutes)->getTimestamp())
            ->whereNull('users.anonymized_at')
            ->whereNull('users.usage_opted_out_at')
            ->whereNull('users.closure_requested_at')
            ->get([
                'sessions.user_id',
                'sessions.payload',
                'sessions.last_activity',
                'users.name',
                'users.email',
                'users.is_platform_admin',
                'users.is_support_technician',
            ]);

        // Uma entrada por pessoa, com a atividade mais recente das suas sessões.
        $byUser = [];
        foreach ($rows as $row) {
            if ($this->isImpersonated((string) $row->payload)) {
                continue;
            }

            $userId = (int) $row->user_id;
            if (isset($byUser[$userId]) && (int) $byUser[$userId]->last_activity >= (int) $row->last_activity) {
                continue;
            }

            $byUser[$userId] = $row;
        }

        $ulids = $byUser === []
            ? []
            : DB::table('usage_user_states')->whereIn('user_id', array_keys($byUser))->pluck('ulid', 'user_id')->all();

        $people = [];
        foreach ($byUser as $userId => $row) {
            $minutesAgo = max(0, intdiv($nowTimestamp - (int) $row->last_activity, 60));

            $people[] = [
                'ulid' => isset($ulids[$userId]) ? (string) $ulids[$userId] : null,
                'name' => (string) $row->name,
                'email' => (string) $row->email,
                'internal' => (bool) $row->is_platform_admin || (bool) $row->is_support_technician,
                'minutes_ago' => $minutesAgo,
                'active' => $minutesAgo < self::ACTIVE_WINDOW_MINUTES,
                'last_activity' => (int) $row->last_activity,
            ];
        }

        usort($people, fn (array $a, array $b): int => [$b['last_activity'], $a['name']] <=> [$a['last_activity'], $b['name']]);

        $people = array_map(function (array $person): array {
            unset($person['last_activity']);

            return $person;
        }, $people);

        return [
            'as_of' => $now->setTimezone((string) config('app.timezone'))->format('H:i'),
            'window_minutes' => self::ACTIVE_WINDOW_MINUTES,
            'session_minutes' => $sessionMinutes,
            'active_count' => count(array_filter($people, fn (array $person): bool => $person['active'])),
            'people' => $people,
        ];
    }

    /**
     * O payload é `base64(serialize(array))`. Se não se conseguir descodificar,
     * a sessão conta como normal: é preferível mostrar uma pessoa a mais do que
     * esconder uma que está mesmo ligada.
     */
    private function isImpersonated(string $payload): bool
    {
        try {
            $decoded = base64_decode($payload, true);
            if ($decoded === false) {
                return false;
            }

            $data = @unserialize($decoded, ['allowed_classes' => false]);

            return is_array($data) && ! empty($data['impersonator_id']);
        } catch (Throwable) {
            return false;
        }
    }
}
