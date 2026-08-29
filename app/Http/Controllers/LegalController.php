<?php

namespace App\Http\Controllers;

use App\Support\Legal\LegalDocuments;
use Inertia\Inertia;
use Inertia\Response;

/**
 * As páginas legais públicas.
 *
 * SEM `auth` E SEM `organization`: quem precisa de ler os Termos antes de
 * criar conta tem de os conseguir abrir sem ter conta, e um pedido de
 * privacidade não pertence a nenhum tenant.
 *
 * O TEXTO VEM DE `LegalDocuments`, não do componente Vue. Foi decidido quando
 * o SSR do Inertia estava desligado (texto num `.vue` não chegava à resposta)
 * e mantém-se com o SSR ligado: o payload está no HTML mesmo quando o processo
 * de SSR está em baixo, e `LegalDocumentsTest` afirma o que lá está sem
 * precisar de um servidor Node a correr.
 */
class LegalController extends Controller
{
    public function terms(): Response
    {
        return Inertia::render('legal/Document', [
            'document' => LegalDocuments::terms(),
            'controller' => LegalDocuments::controller(),
        ]);
    }

    public function privacy(): Response
    {
        return Inertia::render('legal/Document', [
            'document' => LegalDocuments::privacy(),
            'controller' => LegalDocuments::controller(),
        ]);
    }

    /**
     * O Acordo de Tratamento de Dados.
     *
     * PÚBLICO E INDEXÁVEL COMO OS OUTROS DOIS, apesar de só se aplicar a quem
     * já tem conta: um professor que esteja a decidir se põe ali os alunos da
     * sua turma tem de o poder ler ANTES de criar conta. Um acordo de
     * subcontratação que só se lê depois de aceite é um acordo que ninguém
     * leu.
     */
    public function processing(): Response
    {
        return Inertia::render('legal/Document', [
            'document' => LegalDocuments::processing(),
            'controller' => LegalDocuments::controller(),
        ]);
    }
}
