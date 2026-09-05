<?php

use App\Http\Controllers\AcademicCalendarExceptionController;
use App\Http\Controllers\AcademicCalendarImportController;
use App\Http\Controllers\AcademicYearCalendarController;
use App\Http\Controllers\AcademicYearContextController;
use App\Http\Controllers\AcademicYearController;
use App\Http\Controllers\ActivityController;
use App\Http\Controllers\AssessmentController;
use App\Http\Controllers\AssessmentProfileController;
use App\Http\Controllers\CalendarEventController;
use App\Http\Controllers\ChangelogController;
use App\Http\Controllers\ClassController;
use App\Http\Controllers\ClassificationController;
use App\Http\Controllers\ClassPhotoImportController;
use App\Http\Controllers\ClassProfileMigrationController;
use App\Http\Controllers\ClassReassignmentController;
use App\Http\Controllers\ClassStatisticsAnalysisController;
use App\Http\Controllers\ClassStatisticsController;
use App\Http\Controllers\ConfigurationSharingController;
use App\Http\Controllers\CorrectionImportController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DataExportController;
use App\Http\Controllers\DataImportController;
use App\Http\Controllers\EnrollmentController;
use App\Http\Controllers\EvaluationSheetController;
use App\Http\Controllers\EvaluationSheetCsvExportController;
use App\Http\Controllers\EvaluationSheetHistoryController;
use App\Http\Controllers\EvaluationSheetInovarExportController;
use App\Http\Controllers\EvidenceController;
use App\Http\Controllers\HelpAssistantController;
use App\Http\Controllers\HelpController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\InovarExportController;
use App\Http\Controllers\InstitutionAdminController;
use App\Http\Controllers\InstitutionAiController;
use App\Http\Controllers\InstrumentController;
use App\Http\Controllers\InterimAssessmentController;
use App\Http\Controllers\InterventionController;
use App\Http\Controllers\InvitationAcceptanceController;
use App\Http\Controllers\IssueReportController;
use App\Http\Controllers\LegalController;
use App\Http\Controllers\LessonController;
use App\Http\Controllers\LessonScheduleController;
use App\Http\Controllers\LessonSequenceController;
use App\Http\Controllers\LessonWeekController;
use App\Http\Controllers\MarketingController;
use App\Http\Controllers\NationalHolidaySuggestionController;
use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\OrganizationController;
use App\Http\Controllers\OrganizationMembershipController;
use App\Http\Controllers\PrivacyNoticeController;
use App\Http\Controllers\PublicSelfAssessmentController;
use App\Http\Controllers\PublicSupportController;
use App\Http\Controllers\Records\IncidentDescriptionRewriteController;
use App\Http\Controllers\Reports\ReportController;
use App\Http\Controllers\Reports\ReportExportController;
use App\Http\Controllers\Reports\ReportRewriteController;
use App\Http\Controllers\Reports\ReportSectionController;
use App\Http\Controllers\Reports\ReportTemplateController;
use App\Http\Controllers\ResultsController;
use App\Http\Controllers\RosterImportController;
use App\Http\Controllers\ScaleController;
use App\Http\Controllers\SelfAssessmentController;
use App\Http\Controllers\SeoController;
use App\Http\Controllers\StudentDirectoryController;
use App\Http\Controllers\StudentPhotoController;
use App\Http\Controllers\StudentProgressController;
use App\Http\Controllers\SubjectController;
use App\Http\Controllers\SupportController;
use App\Http\Controllers\TeacherTimetableController;
use App\Http\Controllers\TeamController;
use App\Http\Controllers\TimetableImportController;
use App\Http\Controllers\VoucherValidationController;
use Illuminate\Support\Facades\Route;

// The public landing page. A GET (not Route::inertia) because the plan cards
// are read from the entitlement tables — see HomeController.
Route::get('/', HomeController::class)->name('home');

// The rest of the marketing site — one page per search intent. Public, no
// tenant. The list of what exists is App\Support\Seo\PublicPages.
Route::get('funcionalidades/{slug}', [MarketingController::class, 'feature'])->name('marketing.feature');
Route::get('planos', [MarketingController::class, 'plans'])->name('marketing.plans');
Route::get('seguranca', [MarketingController::class, 'security'])->name('marketing.security');
Route::get('sobre', [MarketingController::class, 'about'])->name('marketing.about');

// «Este código vale?» — a validação REAL por trás do campo de voucher da
// landing. Público porque quem pergunta ainda nem conta tem; throttled porque
// um campo de texto sem sessão é um oráculo de enumeração se ninguém o travar;
// e só LÊ — resgatar exige conta, no checkout ou na página do plano.
Route::middleware('throttle:10,1')->post('voucher/validate', VoucherValidationController::class)
    ->name('voucher.validate');

// «Fale connosco» — a Central de Suporte para quem ainda não tem conta, ou não
// consegue entrar nela. CRIA E MAIS NADA: não há GET de um pedido, não há URL
// assinada e não há recuperação por referência — `SUP-XXXXXX` é um número de
// protocolo, não uma credencial (ADR-0011 §3). Limitado a 5 por minuto; o IP
// serve ao limitador e não é gravado.
Route::get('contacto', [PublicSupportController::class, 'create'])->name('support.public.create');
Route::middleware('throttle:5,1')->post('contacto', [PublicSupportController::class, 'store'])
    ->name('support.public.store');

// robots.txt and sitemap.xml, served by the application so both can name the
// site's own address instead of a domain frozen into a file in public/.
// See SeoController — the old public/robots.txt was deleted with this, because
// a real file there is answered by the web server and this route would never
// have run.
Route::get('robots.txt', [SeoController::class, 'robots'])->name('seo.robots');
Route::get('sitemap.xml', [SeoController::class, 'sitemap'])->name('seo.sitemap');

// As páginas legais. Públicas, sem 'auth' e sem 'organization': quem precisa
// de as ler antes de criar conta tem de as conseguir abrir sem ter conta.
Route::get('termos', [LegalController::class, 'terms'])->name('legal.terms');
Route::get('privacidade', [LegalController::class, 'privacy'])->name('legal.privacy');
Route::get('tratamento-de-dados', [LegalController::class, 'processing'])->name('legal.processing');

// Not tenant data — the changelog is the same for everyone, so it stays
// outside the 'organization' group (no tenant resolution needed).
Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('novidades', [ChangelogController::class, 'index'])->name('changelog.index');
});

// O Centro de Ajuda (A2, Onboarding & Help) — same reasoning as «novidades»
// above: the article set is the same for every organization, so this stays
// outside the 'organization' group too. «search» is declared before the
// {article} wildcard below, or it would be swallowed as an (invalid)
// article id — the same ordering «reports/novo» before «reports/{report}»
// already uses.
Route::middleware(['auth', 'verified'])->group(function () {
    /*
     * Central de Suporte, do lado de quem tem conta.
     *
     * SEM `module:`, como o Centro de Ajuda logo abaixo: o suporte humano não é
     * uma capability e não entra em nenhuma PlanVersion — Base, Pro e
     * Institucional têm o mesmo (ADR-0011). Um professor no Base que não
     * consegue entrar na conta é quem mais precisa disto.
     *
     * O pedido resolve-se pelo ULID e a policy decide; não há rota que aceite
     * uma referência, de propósito.
     */
    Route::get('support', [SupportController::class, 'index'])->name('support.index');
    Route::get('support/novo', [SupportController::class, 'create'])->name('support.create');
    Route::middleware('throttle:6,1')->post('support', [SupportController::class, 'store'])->name('support.store');
    Route::get('support/{support}', [SupportController::class, 'show'])->name('support.show');
    Route::middleware('throttle:10,1')->post('support/{support}/mensagens', [SupportController::class, 'reply'])
        ->name('support.reply');

    /*
     * O widget de reportar problema. Rota propria porque leva multipart, imagens
     * e uma decisao de consentimento que o formulario de conversa nao tem — mas
     * cai na MESMA fila, pela mesma accao. Throttle mais apertado: cada pedido
     * pode trazer tres imagens de 5 MB.
     */
    Route::middleware('throttle:5,1')->post('issues', [IssueReportController::class, 'store'])
        ->name('issues.store');
    Route::get('support/{support}/imagens/{attachment}', [IssueReportController::class, 'image'])
        ->name('support.image');

    Route::get('help', [HelpController::class, 'index'])->name('help.index');
    Route::get('help/search', [HelpController::class, 'search'])->name('help.search');
    // «Assistente Lapispro» — above the {article} wildcard for the same reason
    // «search» is. It is a POST and the wildcard is a GET, so nothing would
    // actually be swallowed today; keeping every fixed help path above the
    // catch-all is what stops the next person who adds one from debugging a
    // 404.
    //
    // Gated inside the controller rather than by `module:`, because the Centro
    // de Ajuda has no 'organization' middleware and because a locked
    // capability must still render the page with an honest explanation, never
    // a 403 where the help articles used to be.
    //
    // NO `throttle:` MIDDLEWARE, deliberately. AiGateway rate-limits the
    // `help_assistant` capability itself — per user and per organization —
    // because it must also work for a job or a command, where there is no
    // middleware stack. Adding a route throttle for the same capability would
    // count every request twice and halve the ceiling (ai-core contract §6).
    Route::post('help/assistente', [HelpAssistantController::class, 'store'])
        ->name('help.assistant');
    Route::get('help/{article}', [HelpController::class, 'show'])->name('help.show');
});

// The student's no-login entry into a self-assessment (§15): no 'auth', no
// 'organization' — the signed URL itself is the only access control, and the
// controller resolves the tenant by hand from the class in the URL. GET and
// POST share the exact same path on purpose: a signed URL's signature covers
// the path + query string, not the HTTP verb, so the same link a student
// opens is also what the form posts back to.
Route::middleware(['signed', 'throttle:120,1'])->group(function () {
    Route::get('auto/{classUlid}/{periodUlid}/{enrollmentUlid}', [PublicSelfAssessmentController::class, 'edit'])
        ->name('self-assessments.public.edit');
    Route::post('auto/{classUlid}/{periodUlid}/{enrollmentUlid}', [PublicSelfAssessmentController::class, 'store'])
        ->name('self-assessments.public.store');
});

// An invitation into an institutional organization (Fatia 3): no `auth`, no
// `organization` — reached by a guest as often as by someone already signed
// in, and the token itself (looked up by hash, never by a guessable id) is
// the access control, the same shape password-reset tokens already use. Not
// `signed`: this is not a Laravel-signed URL, it is this app's own
// single-use, expirable, hash-stored token.
Route::middleware('throttle:60,1')->get('invitations/{token}', [InvitationAcceptanceController::class, 'show'])
    ->name('invitations.show');

// Teacher-facing area. Everything here reads tenant-owned data, so an
// organization must be resolved before the request reaches a controller.
Route::middleware(['auth', 'verified', 'organization'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');

    // The "Primeiros passos" card's own state (A1a, Onboarding & Help) — see
    // OnboardingController. POST to hide it, DELETE to bring it back, same
    // request/cancel shape as settings/account-closure.
    // Fechar o aviso de que a Política de Privacidade mudou. Informativo: não
    // regista aceitação e não bloqueia nada — ver PrivacyNoticeController.
    Route::post('avisos/privacidade', [PrivacyNoticeController::class, 'dismiss'])->name('privacy-notice.dismiss');

    Route::post('dashboard/onboarding-dismissal', [OnboardingController::class, 'dismiss'])->name('onboarding.dismiss');
    Route::delete('dashboard/onboarding-dismissal', [OnboardingController::class, 'restore'])->name('onboarding.restore');

    // Which organization the session is acting for (Fatia 2). The membership
    // check lives in the controller, not a route param binding — a stranger's
    // ulid must 403, never a clean 404 that confirms it exists.
    Route::post('organizations/switch', [OrganizationController::class, 'switch'])->name('organizations.switch');
    Route::post('academic-years/{academic_year}/select', [AcademicYearContextController::class, 'select'])
        ->name('academic-years.select');

    // Audit trail (§22.4) — read-only view of the organization's recorded events.
    // Institucional-only capability: the visibility scoping inside AuditEvent
    // (owner sees all, member sees only their own) is a layer on top of this
    // gate, not a substitute for it.
    Route::get('activity', [ActivityController::class, 'index'])
        ->middleware('module:audit_log')
        ->name('activity.index');

    // Academic years are the temporal foundation (§9). Reached from the header
    // year selector, not the sidebar — the year is a context, not a menu item.
    Route::get('academic-years', [AcademicYearController::class, 'index'])->name('academic-years.index');
    Route::get('academic-years/create', [AcademicYearController::class, 'create'])->name('academic-years.create');
    Route::post('academic-years', [AcademicYearController::class, 'store'])->name('academic-years.store');
    Route::get('academic-years/{academic_year}/edit', [AcademicYearController::class, 'edit'])->name('academic-years.edit');
    Route::put('academic-years/{academic_year}', [AcademicYearController::class, 'update'])->name('academic-years.update');
    Route::delete('academic-years/{academic_year}', [AcademicYearController::class, 'destroy'])->name('academic-years.destroy');

    // AS EXCEÇÕES LETIVAS DO ANO — feriados, interrupções letivas e dias não
    // letivos, uma de cada vez.
    //
    // ANINHADAS NO ANO, E NÃO SOLTAS: uma exceção não existe fora de um ano
    // letivo, e é o ano que responde pela autorização (a mesma
    // AcademicYearPolicy dos períodos, aplicada no controlador). Os dois
    // parâmetros ligam-se pelo `ulid`, que é o `getRouteKeyName()` dos dois
    // modelos e a convenção de todos os endereços deste projeto.
    //
    // E NÃO HÁ AQUI `index`, `create`, `edit` NEM `show`: as três ações escrevem
    // e respondem com `back()` para a página onde o professor está — «Editar ano
    // letivo», que é a única que as lista e a única que as mostra num
    // formulário. Um endereço próprio para as ver seria uma segunda página a
    // dizer o que a primeira já diz.
    Route::post('academic-years/{academic_year}/exceptions', [AcademicCalendarExceptionController::class, 'store'])
        ->name('academic-years.exceptions.store');
    Route::put('academic-years/{academic_year}/exceptions/{exception}', [AcademicCalendarExceptionController::class, 'update'])
        ->name('academic-years.exceptions.update');
    Route::delete('academic-years/{academic_year}/exceptions/{exception}', [AcademicCalendarExceptionController::class, 'destroy'])
        ->name('academic-years.exceptions.destroy');

    // «Sugerir feriados nacionais» (§17) — os feriados oficiais do país DESTE ano
    // letivo (`country_code`, §16), propostos linha a linha.
    //
    // AO LADO DAS EXCEÇÕES E NÃO DENTRO DO CALENDÁRIO, porque é isso que isto é:
    // uma segunda porta para escrever a MESMA `academic_calendar_exceptions`, no
    // mesmo ecrã, sob a mesma AcademicYearPolicy. Não é uma capacidade nova e não
    // ganha uma entitlement própria — quem pode escrever um feriado à mão pode
    // aceitar um sugerido, e quem não pode não pode nenhum dos dois.
    //
    // O GET DEVOLVE JSON e não uma página: é o conteúdo de um diálogo que abre
    // dentro de «Editar ano letivo», e não um endereço onde alguém aterre. A mesma
    // forma de `reports.context` e de `lessons.previous-summary`. E não escreve
    // nada — só o POST escreve, e volta a verificar tudo quando o faz.
    Route::get('academic-years/{academic_year}/holiday-suggestions', [NationalHolidaySuggestionController::class, 'index'])
        ->name('academic-years.holiday-suggestions.index');
    Route::post('academic-years/{academic_year}/holiday-suggestions', [NationalHolidaySuggestionController::class, 'store'])
        ->name('academic-years.holiday-suggestions.store');

    // «Calendário do Ano Letivo» (Fase 5.2) — the year's own structure and its
    // avaliações read together, in two views. Both are GET-only readings of
    // AcademicPeriod and Instrument.applied_on; the calendar owns no table.
    //
    // The Mês view keeps the /calendar address the navigation placeholder
    // answered at until now, so a bookmark made before the real page existed
    // still lands on it. Both views are addressable in their own right — the
    // Ano view is a URL and not a toggle inside the other, so the back button
    // and a bookmark both work on it.
    //
    // Gated by module:calendar, the entitlement this menu entry has carried
    // since long before there was a page behind it — no new capability is
    // invented for a reading of data the teacher can already reach.
    //
    // «Acontecimentos» (Fase 5.3) — a reunião, a atividade, a visita de estudo
    // e o «outro»: as únicas coisas datadas que não têm casa em mais lado
    // nenhum da aplicação. São o primeiro — e único — sítio onde o calendário
    // escreve, e por isso vivem noutro controlador: o das duas vistas acima é
    // uma leitura e não recusa nada durante uma sessão de suporte, justamente
    // porque olhar não muda nada. Estas três recusam.
    //
    // Não há vista própria: as três respondem com um `back()` para a vista de
    // Mês ou de Ano em que o professor estava, que é onde o resultado se vê.
    Route::middleware('module:calendar')->group(function () {
        Route::get('calendar', [AcademicYearCalendarController::class, 'index'])->name('calendar.index');
        Route::get('calendar/ano', [AcademicYearCalendarController::class, 'year'])->name('calendar.year');

        Route::post('calendar/acontecimentos', [CalendarEventController::class, 'store'])
            ->name('calendar.events.store');
        Route::put('calendar/acontecimentos/{calendarEvent}', [CalendarEventController::class, 'update'])
            ->name('calendar.events.update');
        Route::delete('calendar/acontecimentos/{calendarEvent}', [CalendarEventController::class, 'destroy'])
            ->name('calendar.events.destroy');

        // «Importar o calendário da escola» (Fase 5.6) — o .xlsx que o
        // agrupamento publica, lido para a estrutura DESTE ano letivo.
        //
        // Os mesmos três passos, com os mesmos nomes, que timetable-imports.*:
        // escolher (GET create), rever (POST store, que não escreve nada) e
        // confirmar (POST confirm, a única que escreve).
        //
        // `module:calendar_import`, E JÁ NÃO `module:calendar`. Enquanto o
        // calendário inteiro era Pro, importá-lo era «parte de ter o
        // calendário» e não precisava de chave própria. Agora que a Matriz
        // Mestre §2 devolve ao Base o calendário mensal/anual e os
        // acontecimentos, a importação é a única linha dessa tabela que
        // continua marcada só para Pro e Institucional — «Importação avançada
        // de calendário» — e tem a chave que a §17 já lhe dava. O grupo fica
        // aninhado no de `module:calendar`: importar exige as duas, porque
        // importar um calendário para uma organização que não pode ter
        // calendário nenhum não significaria nada.
        //
        // Alcançado a partir da própria página do Calendário e NÃO de uma entrada
        // de menu própria (§24): quem importa um calendário está a olhar para o
        // calendário quando decide fazê-lo.
        Route::middleware('module:calendar_import')->group(function () {
            Route::get('academic-calendar-imports/create', [AcademicCalendarImportController::class, 'create'])
                ->name('academic-calendar-imports.create');
            Route::post('academic-calendar-imports', [AcademicCalendarImportController::class, 'store'])
                ->name('academic-calendar-imports.store');
            Route::post('academic-calendar-imports/confirm', [AcademicCalendarImportController::class, 'confirm'])
                ->name('academic-calendar-imports.confirm');
        });
    });

    // Subjects — the teacher's disciplines. Managed from the header subject
    // selector, like academic years.
    Route::get('subjects', [SubjectController::class, 'index'])->name('subjects.index');
    Route::post('subjects', [SubjectController::class, 'store'])->name('subjects.store');
    Route::put('subjects/{subject}', [SubjectController::class, 'update'])->name('subjects.update');
    Route::delete('subjects/{subject}', [SubjectController::class, 'destroy'])->name('subjects.destroy');

    // Assessment profiles — a sidebar module (gated by the assessment_profiles
    // module). Activation freezes the draft into an immutable version.
    Route::get('assessment-profiles', [AssessmentProfileController::class, 'index'])
        ->middleware('module:assessment_profiles')->name('assessment-profiles.index');
    Route::get('assessment-profiles/create', [AssessmentProfileController::class, 'create'])
        ->middleware('module:assessment_profiles')->name('assessment-profiles.create');
    Route::post('assessment-profiles', [AssessmentProfileController::class, 'store'])
        ->middleware('module:assessment_profiles')->name('assessment-profiles.store');
    Route::post('scales', [ScaleController::class, 'store'])
        ->middleware('module:assessment_profiles')->name('scales.store');
    Route::get('assessment-profiles/{assessment_profile}/edit', [AssessmentProfileController::class, 'edit'])
        ->middleware('module:assessment_profiles')->name('assessment-profiles.edit');
    Route::get('assessment-profiles/{assessment_profile}/reuse', [AssessmentProfileController::class, 'reuseForm'])
        ->middleware('module:assessment_profiles')->name('assessment-profiles.reuse-form');
    Route::post('assessment-profiles/{assessment_profile}/reuse/preview', [AssessmentProfileController::class, 'reusePreview'])
        ->middleware('module:assessment_profiles')->name('assessment-profiles.reuse-preview');
    Route::post('assessment-profiles/{assessment_profile}/reuse/confirm', [AssessmentProfileController::class, 'reuseConfirm'])
        ->middleware('module:assessment_profiles')->name('assessment-profiles.reuse-confirm');
    Route::put('assessment-profiles/{assessment_profile}', [AssessmentProfileController::class, 'update'])
        ->middleware('module:assessment_profiles')->name('assessment-profiles.update');
    Route::post('assessment-profiles/{assessment_profile}/activate', [AssessmentProfileController::class, 'activate'])
        ->middleware('module:assessment_profiles')->name('assessment-profiles.activate');
    Route::delete('assessment-profiles/{assessment_profile}', [AssessmentProfileController::class, 'destroy'])
        ->middleware('module:assessment_profiles')->name('assessment-profiles.destroy');

    // Classes — the teacher's own turmas (gated by the classes module). Students
    // are enrolled from the class detail page.
    Route::middleware('module:institution_admin')->group(function () {
        // Must precede `classes/{class}` so "reassignment" is not consumed as a class ULID.
        Route::get('classes/reassignment', [ClassReassignmentController::class, 'index'])->name('classes.reassignment.index');
        Route::post('classes/reassignment/{class}/assign', [ClassReassignmentController::class, 'assign'])->name('classes.reassignment.assign');
    });

    // "Configurar horários" — the front door onto BOTH ways a teacher sets up
    // a turma's schedule (PDF import, or by hand on the turma's own page).
    // Reached from Turmas' own header before either path is chosen. Must be
    // registered before `classes/{class}` (module:classes, right below), or
    // "schedule-setup" would be swallowed as an (invalid) class ulid — the
    // same instruments/create vs instruments/{instrument} pitfall, here
    // crossing a module boundary instead of sitting inside one group, hence
    // its own block rather than living inside the module:lessons group
    // below. Gated by module:lessons like the timetable-imports.* routes it
    // links to, not by module:classes: a base-plan teacher without the
    // lessons entitlement has nothing to configure here.
    Route::middleware('module:lessons')->group(function () {
        Route::get('classes/schedule-setup', [ClassController::class, 'scheduleSetup'])->name('classes.schedule-setup');
    });

    Route::middleware('module:classes')->group(function () {
        Route::get('classes', [ClassController::class, 'index'])->name('classes.index');
        Route::get('classes/create', [ClassController::class, 'create'])->name('classes.create');
        Route::post('classes', [ClassController::class, 'store'])->name('classes.store');
        Route::get('classes/{class}', [ClassController::class, 'show'])->name('classes.show');
        Route::get('classes/{class}/edit', [ClassController::class, 'edit'])->name('classes.edit');
        Route::put('classes/{class}', [ClassController::class, 'update'])->name('classes.update');
        Route::post('classes/{class}/activate', [ClassController::class, 'activate'])->name('classes.activate');
        Route::put('classes/{class}/profile', [ClassController::class, 'updateProfile'])->name('classes.profile.update');
        Route::delete('classes/{class}', [ClassController::class, 'destroy'])->name('classes.destroy');

        // Profile migration (§10.2, A4): required when a class with results changes
        // version — preview the impact, then confirm with a reason.
        Route::get('classes/{class}/profile-migration', [ClassProfileMigrationController::class, 'create'])->name('classes.profile-migration.create');
        Route::post('classes/{class}/profile-migration', [ClassProfileMigrationController::class, 'store'])->name('classes.profile-migration.store');

        Route::post('classes/{class}/students', [EnrollmentController::class, 'store'])->name('classes.students.store');
        Route::put('classes/{class}/students/{enrollment}', [EnrollmentController::class, 'update'])->name('classes.students.update');
        // The school's own identifiers, saved as the teacher has them: a column.
        Route::put('classes/{class}/process-numbers', [EnrollmentController::class, 'updateProcessNumbers'])->name('classes.process-numbers.update');
        Route::delete('classes/{class}/students/{enrollment}', [EnrollmentController::class, 'destroy'])->name('classes.students.destroy');
        // The photo is its own request: an upload and a data edit fail
        // differently, and one must not discard the other.
        Route::post('classes/{class}/students/{enrollment}/photo', [StudentPhotoController::class, 'update'])->name('classes.students.photo.update');
        Route::delete('classes/{class}/students/{enrollment}/photo', [StudentPhotoController::class, 'destroy'])->name('classes.students.photo.destroy');

        Route::post('classes/{class}/roster-imports', [RosterImportController::class, 'store'])->name('classes.roster-imports.store');
        Route::post('classes/{class}/photos', [ClassPhotoImportController::class, 'store'])->name('classes.photos.store');
        Route::post('classes/{class}/roster-imports/{token}/photos', [RosterImportController::class, 'attachPhotos'])
            ->where('token', '[0-9a-fA-F-]{36}')
            ->name('classes.roster-imports.attach-photos');
        // Constrained to the shape RosterImportTempStorage::newToken() actually
        // generates (a UUID) — never a bare string, so a token can never itself
        // carry a path segment like ".." into the temp-folder path it builds.
        Route::post('classes/{class}/roster-imports/{token}/confirm', [RosterImportController::class, 'confirm'])
            ->where('token', '[0-9a-fA-F-]{36}')
            ->name('classes.roster-imports.confirm');
        Route::get('classes/{class}/roster-imports/{token}/photos/{index}', [RosterImportController::class, 'previewPhoto'])
            ->where(['token' => '[0-9a-fA-F-]{36}', 'index' => '[0-9]+'])
            ->name('classes.roster-imports.preview-photo');

        Route::get('students/{student}/photo', [StudentPhotoController::class, 'show'])->name('students.photo');
    });

    // «Alunos» — the directory. Its own module gate, `students`, which is the
    // one config/navigation.php has always declared for this entry and which
    // routes/app.php's placeholder route already enforced: the entitlement
    // existed long before there was a page behind it, and nothing about who may
    // reach /students changed with this slice.
    //
    // THE URL IS THE ONE THE PLACEHOLDER ANSWERED AT, so a bookmark made before
    // the page existed still lands on it. Only the route NAME moved, from
    // `students` to `students.index` — nothing referenced the old one, and the
    // dotted form is what every other real destination uses.
    Route::middleware('module:students')->group(function () {
        Route::get('students', [StudentDirectoryController::class, 'index'])->name('students.index');
    });

    Route::middleware('module:lessons')->group(function () {
        // «Horário do Professor» — the whole week in one read-only page, and
        // the standalone home of both ways of filling it in. Distinct from
        // classes.schedule-setup, which stays exactly where it is as Turmas'
        // own contextual shortcut. One segment, so it never collides with the
        // timetable-imports/* routes further down.
        Route::get('timetable', [TeacherTimetableController::class, 'index'])->name('timetable.index');

        Route::get('lessons', [LessonWeekController::class, 'index'])->name('lessons.index');
        Route::post('lessons/materialize-week', [LessonWeekController::class, 'materialize'])->name('lessons.materialize-week');

        // Reusable lesson sequences (Fatia 4) — secondary to the weekly view
        // above, never a replacement for it. Registered before the
        // single-segment lessons/{lesson} wildcard below, which would
        // otherwise swallow GET lessons/sequences by treating "sequences"
        // as a lesson ulid.
        Route::get('lessons/sequences', [LessonSequenceController::class, 'index'])->name('lessons.sequences.index');
        Route::post('lessons/sequences', [LessonSequenceController::class, 'store'])->name('lessons.sequences.store');
        Route::put('lessons/sequences/{lessonSequence}', [LessonSequenceController::class, 'update'])->name('lessons.sequences.update');
        Route::delete('lessons/sequences/{lessonSequence}', [LessonSequenceController::class, 'destroy'])->name('lessons.sequences.destroy');
        Route::post('lessons/sequences/{lessonSequence}/apply', [LessonSequenceController::class, 'apply'])->name('lessons.sequences.apply');

        Route::get('lessons/{lesson}', [LessonController::class, 'show'])->name('lessons.show');
        Route::put('lessons/{lesson}/summary', [LessonController::class, 'updateSummary'])->name('lessons.summary.update');
        Route::post('lessons/{lesson}/mark-taught', [LessonController::class, 'markTaught'])->name('lessons.mark-taught');
        // Read-only convenience for "Basear no sumário anterior" — never
        // writes; copies into the CURRENT lesson's still-open, unsaved form.
        Route::get('lessons/{lesson}/previous-summary', [LessonController::class, 'previousSummary'])->name('lessons.previous-summary');

        Route::post('lesson-slots', [LessonScheduleController::class, 'store'])->name('lesson-slots.store');
        Route::put('lesson-slots/{recurringLessonSlot}', [LessonScheduleController::class, 'update'])->name('lesson-slots.update');
        Route::delete('lesson-slots/{recurringLessonSlot}', [LessonScheduleController::class, 'destroy'])->name('lesson-slots.destroy');
        Route::post('classes/{class}/lessons/materialize', [LessonScheduleController::class, 'materialize'])->name('lessons.materialize');

        // Importing a timetable export into recurring slots. Global rather than
        // nested inside a turma — one file spans several — and gated by this
        // module because RecurringLessonSlot is exactly what it creates.
        Route::get('timetable-imports/create', [TimetableImportController::class, 'create'])->name('timetable-imports.create');
        Route::post('timetable-imports', [TimetableImportController::class, 'store'])->name('timetable-imports.store');
        Route::post('timetable-imports/confirm', [TimetableImportController::class, 'confirm'])->name('timetable-imports.confirm');
    });

    // Instruments and the grading grid. Created inside a class; the grid is the
    // instrument's own page.
    Route::middleware('module:instruments')->group(function () {
        Route::get('instruments', [InstrumentController::class, 'index'])->name('instruments.index');
        // The front door when no class is already known from context (this
        // area's own "+ Novo" button) — a thin picker that then hands off to
        // the real, unchanged instruments.create below. Declared before
        // instruments/{instrument}, or "create" would be swallowed as an
        // (invalid) instrument ulid.
        Route::get('instruments/create', [InstrumentController::class, 'createChoosingClass'])->name('instruments.create-picker');
        Route::get('classes/{class}/instruments/create', [InstrumentController::class, 'create'])->name('instruments.create');
        Route::post('classes/{class}/instruments', [InstrumentController::class, 'store'])->name('instruments.store');
        Route::get('instruments/{instrument}', [InstrumentController::class, 'show'])->name('instruments.show');
        // The grid a teacher fills in offline and brings back. Deliberately on
        // the instrument: it is generated FROM one, and there is nothing to ask
        // when the answer is the evaluation already on screen (§11).
        Route::get('instruments/{instrument}/grelha', [InstrumentController::class, 'downloadGrid'])->name('instruments.grid');
        Route::get('instruments/{instrument}/edit', [InstrumentController::class, 'edit'])->name('instruments.edit');
        Route::put('instruments/{instrument}', [InstrumentController::class, 'update'])->name('instruments.update');
        Route::post('instruments/{instrument}/cancel', [InstrumentController::class, 'cancel'])->name('instruments.cancel');
        Route::post('instruments/{instrument}/revert-cancellation', [InstrumentController::class, 'revertCancellation'])->name('instruments.revert-cancellation');
        Route::post('instruments/{instrument}/scores', [InstrumentController::class, 'saveScores'])->name('instruments.scores.save');
        // Saving and finishing are different acts: the grid's "Guardar" never
        // closes a correction, and closing one is its own explicit decision.
        Route::post('instruments/{instrument}/complete', [InstrumentController::class, 'completeCorrection'])->name('instruments.complete');
        Route::post('instruments/{instrument}/reopen', [InstrumentController::class, 'reopenCorrection'])->name('instruments.reopen');
        Route::delete('instruments/{instrument}', [InstrumentController::class, 'destroy'])->name('instruments.destroy');
    });

    // Avaliações — a read-only view of instruments-as-applications. Creating
    // and correcting hand off to the existing instruments routes above;
    // nothing here writes an Instrument.
    Route::middleware('module:assessments')->group(function () {
        Route::get('assessments', [AssessmentController::class, 'index'])->name('assessments.index');
        Route::get('assessments/{instrument}', [AssessmentController::class, 'show'])->name('assessments.show');
    });

    // Importing correction grids exported from other assessment platforms
    // (Plickers today, others later). A Pro/Institucional capability: the
    // middleware is the access control, and a Base organization cannot reach any
    // of these by typing a URL. Every route also runs the policy, because the
    // plan says "may your organization", not "is this yours".
    Route::middleware('module:correction_grid_import')->group(function () {
        Route::get('imports/correction/create', [CorrectionImportController::class, 'create'])->name('correction-imports.create');
        Route::post('imports/correction', [CorrectionImportController::class, 'store'])->name('correction-imports.store');
        Route::get('imports/correction/{import}', [CorrectionImportController::class, 'edit'])->name('correction-imports.edit');
        Route::patch('imports/correction/{import}', [CorrectionImportController::class, 'update'])->name('correction-imports.update');
        Route::post('imports/correction/{import}/confirm', [CorrectionImportController::class, 'confirm'])->name('correction-imports.confirm');
        Route::delete('imports/correction/{import}', [CorrectionImportController::class, 'destroy'])->name('correction-imports.destroy');
    });

    // Results — the calculation engine's output for a class, per period.
    Route::middleware('module:results')->group(function () {
        Route::get('results', [ResultsController::class, 'index'])->name('results.index');
        // Declared BEFORE the {period?} wildcard, which would otherwise swallow
        // it and go looking for a period called «quadro-sintese».
        Route::get('classes/{class}/results/quadro-sintese', [ResultsController::class, 'summary'])->name('results.summary');
        // Same reason as above: declared before the {period?} wildcard. The
        // period is optional — without one the read model opens on the latest
        // period that actually has results.
        Route::get('classes/{class}/results/estatistica/{period?}', [ClassStatisticsController::class, 'show'])->name('results.statistics');

        // «Analisar com IA» (§4 do brief AI Experiences). The optional period
        // sits at the END of the path, behind a literal segment, because
        // Laravel only allows an optional parameter last — and «analise-ia»
        // can never be mistaken for a period.
        //
        // A READ, DESPITE BEING A POST. It is a POST because it spends money
        // and must not be repeatable by a refresh or prefetchable by a
        // browser, not because it changes anything: there is no write path
        // from an AI answer anywhere in this application.
        //
        // No `throttle:` middleware here either — AiGateway rate-limits the
        // `ai_pedagogical_analysis` capability itself, and a second ceiling on
        // the same capability would halve it (ai-core contract §6).
        Route::post('classes/{class}/results/estatistica/analise-ia/{period?}', [ClassStatisticsAnalysisController::class, 'store'])
            ->name('results.statistics.analyse');

        // Avaliações intercalares — a kept photograph of a class on a date.
        // Inside Resultados, because that is what it is a photograph OF; not a
        // module of its own (§21).
        Route::post('classes/{class}/avaliacoes-intercalares', [InterimAssessmentController::class, 'store'])->name('interim-assessments.store');
        Route::get('classes/{class}/avaliacoes-intercalares/{interimAssessment}', [InterimAssessmentController::class, 'show'])->name('interim-assessments.show');
        Route::get('classes/{class}/avaliacoes-intercalares/{interimAssessment}/comparar', [InterimAssessmentController::class, 'compare'])->name('interim-assessments.compare');
        // Renaming only — the snapshot itself refuses to move.
        Route::put('classes/{class}/avaliacoes-intercalares/{interimAssessment}', [InterimAssessmentController::class, 'update'])->name('interim-assessments.update');
        Route::delete('classes/{class}/avaliacoes-intercalares/{interimAssessment}', [InterimAssessmentController::class, 'destroy'])->name('interim-assessments.destroy');

        // Exporting a period's qualitative mentions into the grid INOVAR
        // produced. Its own capability: the assessment core does not depend on
        // it, and a school that never uses INOVAR never meets it.
        Route::middleware('module:inovar_export')->group(function () {
            Route::get('classes/{class}/exports/inovar/{period}', [InovarExportController::class, 'create'])->name('exports.inovar.create');
            Route::post('classes/{class}/exports/inovar/{period}', [InovarExportController::class, 'store'])->name('exports.inovar.store');
            // A DOWNLOAD, so a GET the browser can follow on its own — the same
            // shape the instrument grid and the pauta export already use. A
            // binary response cannot come back through an Inertia visit.
            Route::get('classes/{class}/exports/inovar/{period}/{token}', [InovarExportController::class, 'generate'])->name('exports.inovar.generate');
        });
        // «Analisar a avaliação com IA» (§7 do brief AI-complete). The optional
        // period sits at the END of the path, behind a literal segment,
        // because Laravel only allows an optional parameter last — and
        // «analise-ia» can never be mistaken for a period ulid. Declared
        // BEFORE `results/{period?}` below for the same reason «search» is
        // declared before «help/{article}»: a fixed segment above a catch-all.
        //
        // A READ, DESPITE BEING A POST. It is a POST because it spends money
        // and must not be repeatable by a refresh or prefetchable by a
        // browser, not because it changes anything: there is no write path
        // from an AI answer anywhere in this application.
        //
        // No `throttle:` middleware — AiGateway rate-limits the
        // `ai_assessment` capability itself, and a second ceiling on the same
        // capability would halve it (ai-core contract §6).
        Route::post('classes/{class}/results/analise-ia/{period?}', [ResultsController::class, 'analyse'])
            ->name('results.analyse');
        Route::get('classes/{class}/results/{period?}', [ResultsController::class, 'show'])->name('results.show');

        // Pautas de Avaliação — one view, not three: quantitativo, apreciação
        // qualitativa por domínio e classificação (sugerida vs. decidida) num
        // único ecrã, sem seletor de "modo". Ocultar grupos é apresentação; os
        // dados enviados nunca mudam com o que o professor esconde (§ briefing).
        Route::get('avaliacao/pautas', [EvaluationSheetController::class, 'index'])->name('evaluation-sheets.index');

        // Guardar uma pauta, e reler as que já foram guardadas.
        //
        // Declaradas ANTES do wildcard `pauta-avaliacao/{period?}` logo abaixo,
        // que de outra forma engoliria «historico» e iria procurar um período
        // com esse ulid — exatamente a armadilha que já obrigou
        // `results/quadro-sintese` a subir acima do seu próprio wildcard.
        Route::post('classes/{class}/pauta-avaliacao/{period}/guardar', [EvaluationSheetHistoryController::class, 'store'])->name('evaluation-sheets.store');
        Route::get('classes/{class}/pauta-avaliacao/historico', [EvaluationSheetHistoryController::class, 'history'])->name('evaluation-sheets.history');
        // O ficheiro de um registo. Declarada ANTES de `historico/{export}` por
        // higiene, e deliberadamente FORA de `module:inovar_export`: um registo
        // já criado continua a ser da escola, e um plano que muda não pode
        // trancar a porta do que já foi exportado. A autorização real está no
        // controlador — turma visível, registo desta turma, ficheiro existente.
        Route::get('classes/{class}/pauta-avaliacao/historico/{export}/ficheiro', [EvaluationSheetHistoryController::class, 'download'])->name('evaluation-sheets.download');
        Route::get('classes/{class}/pauta-avaliacao/historico/{export}', [EvaluationSheetHistoryController::class, 'snapshot'])->name('evaluation-sheets.snapshot');

        // O CSV da pauta do período que está a ser visto. Declarado ANTES do
        // wildcard `pauta-avaliacao/{period?}` pela mesma razão que «historico»
        // o é — «csv» não é um ulid de período e não pode ser lido como um.
        //
        // NÃO é «Preparar exportação para o Inovar» (§6): aquela produz a
        // grelha da escola a partir de uma pauta guardada e deixa registo no
        // histórico; esta é uma leitura de dados, aqui e agora, sem snapshot.
        Route::get('classes/{class}/pauta-avaliacao/csv/{period?}', EvaluationSheetCsvExportController::class)->name('evaluation-sheets.csv');

        // «Preparar exportação para o Inovar», a partir da própria Pauta.
        //
        // A capability é a que já existe — nenhuma nova (§ briefing). O fluxo
        // antigo `exports.inovar.*` continua exatamente onde estava e não é
        // tocado por nada disto.
        Route::middleware('module:inovar_export')->group(function () {
            Route::get('classes/{class}/pauta-avaliacao/inovar/{period}', [EvaluationSheetInovarExportController::class, 'create'])->name('evaluation-sheets.inovar.create');
            Route::post('classes/{class}/pauta-avaliacao/inovar/{period}', [EvaluationSheetInovarExportController::class, 'store'])->name('evaluation-sheets.inovar.store');
            // Confirmar TERMINA NUM REDIRECT, não num ficheiro: ao contrário do
            // fluxo antigo, aqui o ficheiro fica, e o que o professor precisa
            // de receber é o registo no histórico.
            Route::post('classes/{class}/pauta-avaliacao/inovar/{period}/{token}', [EvaluationSheetInovarExportController::class, 'confirm'])->name('evaluation-sheets.inovar.confirm');
        });

        Route::get('classes/{class}/pauta-avaliacao/{period?}', [EvaluationSheetController::class, 'show'])->name('evaluation-sheets.show');

        // The decision layer (§7): propose from the engine, then the teacher confirms.
        Route::get('classes/{class}/classifications/{period?}', [ClassificationController::class, 'show'])->name('classifications.show');
        Route::post('classes/{class}/classifications/{period}/propose', [ClassificationController::class, 'propose'])->name('classifications.propose');
        Route::post('classes/{class}/classifications/{period}/publish', [ClassificationController::class, 'publish'])->name('classifications.publish');
        // The decision is identified by WHOSE it is — this student, this period,
        // this scope — and not by the row that happens to store it. A period
        // whose proposals were never generated has no row yet, and the teacher's
        // classification must not wait on one; this opens it and confirms
        // through the same service either way.
        Route::post('classes/{class}/classifications/{period}/{enrollment}/decide', [ClassificationController::class, 'decide'])
            ->name('classifications.decide');
    });

    // As URLs da pauta de classificações antiga (`pautas.*`), que a Pauta de
    // Avaliação absorveu. Continuam a responder — um bookmark de um professor
    // não deixa de ser válido só porque o ecrã mudou de sítio (§8, §9).
    //
    // DELIBERADAMENTE FORA de `module:reports` e de `module:results`. Um
    // redirect não mostra nada e não decide nada: quem aplica o seu próprio
    // gate é o DESTINO, que vive dentro de `module:results` e recusa lá se for
    // caso disso. Se estas ficassem debaixo de `module:reports`, uma escola
    // sem o módulo de relatórios veria um 403 no caminho para um ecrã que tem
    // todo o direito de abrir — e o bookmark aterraria no sítio errado por uma
    // razão que já não é a sua. Continuam, isso sim, dentro do grupo
    // autenticado e com tenant resolvido: nada disto é público.
    //
    // O `{class}` é preservado no destino — `Route::redirect()` substitui os
    // parâmetros do URL de origem no de destino, por isso a turma que estava
    // no bookmark é a turma que se abre.
    Route::redirect('reports/pautas', '/avaliacao/pautas');
    Route::redirect('classes/{class}/report', '/classes/{class}/pauta-avaliacao');
    Route::redirect('classes/{class}/report/export', '/classes/{class}/pauta-avaliacao/csv');

    // Relatórios (§53). O relatório DOCUMENTO — secções, um autor, um rascunho
    // que se finaliza e se exporta. A pauta, que já viveu neste módulo como
    // `pautas.*`, é hoje a Pauta de Avaliação e vive em Avaliação
    // (`evaluation-sheets.*`, dentro de `module:results`).
    Route::middleware('module:reports')->group(function () {
        Route::get('reports', [ReportController::class, 'index'])->name('reports.index');

        // Every literal segment is declared before `reports/{report}`, or it
        // would be swallowed as an (invalid) report ulid.
        Route::get('reports/novo', [ReportController::class, 'create'])->name('reports.create');
        Route::post('reports', [ReportController::class, 'store'])->name('reports.store');
        Route::get('reports/contexto/{class}', [ReportController::class, 'context'])->name('reports.context');

        // Modelos (§25). Declared before `reports/{report}`, or «modelos» would
        // be swallowed as an (invalid) report ulid.
        Route::get('reports/modelos', [ReportTemplateController::class, 'index'])->name('reports.templates.index');
        Route::post('reports/modelos', [ReportTemplateController::class, 'store'])->name('reports.templates.store');
        Route::get('reports/modelos/{template}', [ReportTemplateController::class, 'edit'])->name('reports.templates.edit');
        Route::put('reports/modelos/{template}', [ReportTemplateController::class, 'update'])->name('reports.templates.update');
        Route::post('reports/modelos/{template}/duplicar', [ReportTemplateController::class, 'duplicate'])->name('reports.templates.duplicate');

        // The report document itself. Last, so the wildcard cannot shadow the
        // literal segments above.
        Route::get('reports/{report}', [ReportController::class, 'show'])->name('reports.show');
        Route::put('reports/{report}', [ReportController::class, 'update'])->name('reports.update');
        Route::delete('reports/{report}', [ReportController::class, 'destroy'])->name('reports.destroy');
        Route::post('reports/{report}/gerar', [ReportController::class, 'regenerate'])->name('reports.regenerate');
        // §37: after this the document is fixed. There is no route back —
        // correcting a finished report means deriving a new one (§32).
        Route::post('reports/{report}/finalizar', [ReportController::class, 'finalize'])->name('reports.finalize');
        Route::post('reports/{report}/derivar', [ReportController::class, 'derive'])->name('reports.derive');
        // §18: the draft's arrangement, kept for next time. Structure only.
        Route::post('reports/{report}/guardar-modelo', [ReportTemplateController::class, 'storeFromReport'])
            ->name('reports.templates.from-report');
        // The logo frozen INTO this report — served from the private disk by an
        // authorizing controller, exactly as the live one is (§39, §65).
        Route::get('reports/{report}/logotipo', [ReportController::class, 'logo'])->name('reports.logo');

        // §47: two formats, one content. GETs the browser can follow on its
        // own — a binary response cannot come back through an Inertia visit.
        Route::get('reports/{report}/pdf', [ReportExportController::class, 'pdf'])->name('reports.export.pdf');
        Route::get('reports/{report}/word', [ReportExportController::class, 'docx'])->name('reports.export.docx');

        // One section at a time (§44) — edit, regenerate from today's data,
        // restore the last automatic text, reorder.
        Route::put('reports/{report}/seccoes/ordem', [ReportSectionController::class, 'reorder'])->name('reports.sections.reorder');
        Route::put('reports/{report}/seccoes/{section}', [ReportSectionController::class, 'update'])->name('reports.sections.update');
        Route::post('reports/{report}/seccoes/{section}/gerar', [ReportSectionController::class, 'regenerate'])->name('reports.sections.regenerate');
        Route::post('reports/{report}/seccoes/{section}/restaurar', [ReportSectionController::class, 'restore'])->name('reports.sections.restore');

        // «Aperfeiçoar redação» (§4 do brief de IA). Rate limited per user AND
        // per organization: one teacher holding down a button cannot spend the
        // school's budget, and thirty teachers each within their own limit still
        // cannot (§28).
        //
        // THE CEILING MOVED INTO `AiGateway` with the AI-complete slice, and
        // the route throttle came off with it. The gateway limits the
        // `ai_reports` capability per user and per organization, on the same
        // numbers, and also for a job or a command where there is no middleware
        // stack. Keeping both would have counted every request twice and halved
        // the ceiling (ai-core contract §6).
        Route::post('reports/{report}/seccoes/{section}/aperfeicoar', [ReportRewriteController::class, 'store'])
            ->name('reports.sections.rewrite');
    });

    // Evolução do Aluno — one student's year, read forwards. A VIEW, not a
    // document: Relatórios already produces the document, and this feeds it
    // without replacing it (§3 do brief).
    Route::middleware('module:student_progress')->group(function () {
        Route::get('evolucao', [StudentProgressController::class, 'index'])->name('student-progress.index');
        Route::get('classes/{class}/evolucao', [StudentProgressController::class, 'show'])->name('student-progress.class');
        // Through the ENROLMENT, never a student id: a result belongs to the
        // (student, class) pair, and a student who left still has a year (§58).
        Route::get('classes/{class}/evolucao/{enrollment}', [StudentProgressController::class, 'student'])->name('student-progress.student');
        // Print/PDF (HTML+CSS print, no server-side engine) — one document
        // whose composition adapts to `Entitlements`, never a second engine.
        // Same guards, same binding, same tenancy as the panel above.
        Route::get('classes/{class}/evolucao/{enrollment}/imprimir', [StudentProgressController::class, 'print'])->name('student-progress.print');
        // «Sugestão de estratégia (IA)» (§13). Gated by `ai_strategies` inside
        // the suggester, on the server.
        //
        // NO `throttle:` MIDDLEWARE ANY MORE, and its removal is part of the
        // migration onto the gateway rather than a relaxation. `AiGateway`
        // rate-limits the `ai_strategies` capability itself, per user and per
        // organization, because it also has to work for a job or a command
        // where there is no middleware stack. Leaving the route throttle on
        // would count every request twice and halve the ceiling (ai-core
        // contract §6).
        Route::post('classes/{class}/evolucao/{enrollment}/sugestao-estrategia', [StudentProgressController::class, 'suggestStrategy'])
            ->name('student-progress.suggest-strategy');

        // «Síntese de acompanhamento (IA)» — a reading of the Evolução this
        // page already computed. A POST for the same reason «Analisar com IA»
        // is one: it spends money, and must not be repeatable by a refresh or
        // prefetchable by a browser. It is still a READ — no endpoint anywhere
        // accepts a synthesis back.
        Route::post('classes/{class}/evolucao/{enrollment}/sintese-ia', [StudentProgressController::class, 'synthesise'])
            ->name('student-progress.synthesise');
    });

    // Records — the teacher's logbook (§14). Qualitative evidence, never a grade.
    Route::middleware('module:records')->group(function () {
        Route::get('records', [EvidenceController::class, 'index'])->name('records.index');
        Route::get('classes/{class}/records', [EvidenceController::class, 'show'])->name('records.show');
        Route::get('classes/{class}/records/homework-batch', [EvidenceController::class, 'homeworkBatch'])->name('records.homework-batch.show');
        Route::put('classes/{class}/records/homework-batch', [EvidenceController::class, 'updateHomeworkBatch'])->name('records.homework-batch.update');
        Route::delete('classes/{class}/records/homework-batch', [EvidenceController::class, 'destroyHomeworkBatch'])->name('records.homework-batch.destroy');
        Route::post('classes/{class}/records', [EvidenceController::class, 'store'])->name('records.store');
        Route::put('records/{record}', [EvidenceController::class, 'update'])->name('records.update');
        Route::delete('records/{record}', [EvidenceController::class, 'destroy'])->name('records.destroy');

        // «Aperfeiçoar redação» on the disciplinary occurrence description
        // (SUP-U8FMAE). Same capability as Relatórios (`ai_reports`) — the
        // gateway itself applies the rate limit and quota per user AND per
        // organization, so no route throttle belongs here either (ai-core
        // contract §6).
        Route::post('classes/{class}/records/aperfeicoar-descricao', [IncidentDescriptionRewriteController::class, 'store'])
            ->name('records.incident.rewrite');
    });

    // Self-assessment (§15). Compared with the calculated grade, never summed in.
    Route::middleware('module:self_assessments')->group(function () {
        Route::get('self-assessments', [SelfAssessmentController::class, 'index'])->name('self-assessments.index');
        Route::get('classes/{class}/self-assessments/{period?}', [SelfAssessmentController::class, 'show'])->name('self-assessments.show');
        // Must come before the {enrollment} wildcard route below, or "links"
        // would itself be swallowed as an (invalid) enrollment ulid. Gated by
        // its own module — the base plan keeps self_assessments (the teacher
        // fills it in an interview) but not the student's no-login link.
        Route::get('classes/{class}/self-assessments/{period}/links', [SelfAssessmentController::class, 'links'])
            ->middleware('module:self_assessment_links')
            ->name('self-assessments.links');
        Route::get('classes/{class}/self-assessments/{period}/{enrollment}', [SelfAssessmentController::class, 'edit'])->name('self-assessments.edit');
        Route::post('classes/{class}/self-assessments/{period}/{enrollment}', [SelfAssessmentController::class, 'store'])->name('self-assessments.store');
    });

    // Interventions (§14): what the teacher did, for a student, a group or the
    // whole class — never part of the calculation. PATCH stays the light
    // status-only action so the quick "Concluir" button does not have to
    // resend the whole record; PUT is the full edit.
    Route::middleware('module:interventions')->group(function () {
        Route::get('interventions', [InterventionController::class, 'index'])->name('interventions.index');
        Route::get('classes/{class}/interventions', [InterventionController::class, 'show'])->name('interventions.show');
        Route::post('classes/{class}/interventions', [InterventionController::class, 'store'])->name('interventions.store');
        Route::put('interventions/{intervention}', [InterventionController::class, 'update'])->name('interventions.update');
        Route::patch('interventions/{intervention}', [InterventionController::class, 'updateStatus'])->name('interventions.status.update');
        Route::post('interventions/{intervention}/reviews', [InterventionController::class, 'addReview'])->name('interventions.reviews.store');
        Route::delete('interventions/{intervention}', [InterventionController::class, 'destroy'])->name('interventions.destroy');
    });

    // Leaving is not an "Equipa" action — any member, on any plan, must be
    // able to leave any institutional organization they belong to, so this
    // sits outside the `module:institution_admin` group below (which exists
    // to keep a Base/Pro organization out of governance features it hasn't
    // paid for; leaving isn't one of those).
    Route::post('organizations/leave', [OrganizationMembershipController::class, 'leave'])
        ->name('organizations.leave');

    // "Exportar os meus dados" (Fatia 4, §20/§52) — every plan, not gated by
    // any module: this is portability, not a paid feature.
    Route::get('data-exports', [DataExportController::class, 'index'])->name('data-exports.index');
    Route::post('data-exports', [DataExportController::class, 'store'])->name('data-exports.store');
    Route::get('data-exports/{data_export}', [DataExportController::class, 'download'])->name('data-exports.download');

    // "Importar dados" (Fatia 6) — restoring a backup this same export
    // produces. Deliberately absent from EnsureAccountIsOperational's
    // allow-list: unlike export, a restore is never permitted while the
    // account or the current organization is winding down (§37-38).
    //
    // GATED BY `module:data_backup_restore`, AND THE EXPORT ABOVE IS NOT.
    // The two halves of this page pair are different rows of Matriz Mestre §7
    // and always were: «Exportação dos próprios dados» and «Exportação RGPD»
    // are ticked for every plan — §20 makes portability a property of the
    // platform, not a paid feature — while «Backup completo self-service» and
    // «Restauro self-service» are marked Pro and Institucional. Until this
    // realignment neither half was gated at all, so a Base organization could
    // restore a complete backup over its own data. Exporting stays exactly
    // where it was; only putting a backup back is Pro.
    Route::middleware('module:data_backup_restore')->group(function () {
        Route::get('data-imports/create', [DataImportController::class, 'create'])->name('data-imports.create');
        Route::post('data-imports', [DataImportController::class, 'store'])->name('data-imports.store');
        Route::get('data-imports/{data_import}', [DataImportController::class, 'edit'])->name('data-imports.edit');
        Route::post('data-imports/{data_import}/confirm', [DataImportController::class, 'confirm'])->name('data-imports.confirm');
        Route::delete('data-imports/{data_import}', [DataImportController::class, 'destroy'])->name('data-imports.destroy');
    });

    Route::middleware('module:template_sharing')->group(function () {
        Route::get('configuracao/partilhar', [ConfigurationSharingController::class, 'export'])->name('configuration-sharing.export');
        Route::post('configuracao/partilhar', [ConfigurationSharingController::class, 'download'])->name('configuration-sharing.download');
        Route::get('configuracao/importar', [ConfigurationSharingController::class, 'import'])->name('configuration-sharing.import');
        Route::post('configuracao/importar/preview', [ConfigurationSharingController::class, 'preview'])->name('configuration-sharing.preview');
        Route::post('configuracao/importar/confirmar', [ConfigurationSharingController::class, 'confirm'])->name('configuration-sharing.confirm');
    });

    // Equipa (Fatia 3) — an institutional organization's members and pending
    // invitations, owner-only (TeamController, OrganizationInvitationPolicy).
    // Fatia 4 adds removing a member, transferring ownership, and reassigning
    // orphaned classes — still owner-only. The module gate keeps a Base/Pro
    // organization out; it cannot check WHO is asking or the organization's
    // actual TYPE, which is why the policy still runs on every action
    // underneath it.
    // Governação de IA — Institucional only, and gated on its OWN key rather
    // than on `institution_admin`. A school could reasonably have institutional
    // administration without ever turning AI on, and `ai_governance` is what
    // says which. Declared outside the group below for exactly that reason.
    Route::middleware('module:ai_governance')->group(function () {
        Route::get('institution/ia', [InstitutionAiController::class, 'index'])->name('institution.ai');
    });

    Route::middleware('module:institution_admin')->group(function () {
        Route::get('institution', [InstitutionAdminController::class, 'index'])->name('institution.index');
        Route::get('team', [TeamController::class, 'index'])->name('team.index');
        Route::post('team/invitations', [TeamController::class, 'store'])->name('team.invitations.store');
        Route::delete('team/invitations/{invitation}', [TeamController::class, 'destroy'])->name('team.invitations.destroy');
        // No {member} route parameter: `User` carries no `ulid` (nothing has
        // ever needed to address one in a URL before this fatia), so the
        // target travels in the request body instead of exposing a raw
        // sequential id in the path — see TeamController::targetMember().
        Route::delete('team/members', [TeamController::class, 'removeMember'])->name('team.members.destroy');
        Route::post('team/members/transfer-ownership', [TeamController::class, 'transferOwnership'])->name('team.members.transfer-ownership');

        // Organization closure (Fatia 5, §9-§12) — owner-only. The UI lives
        // at institution.index; these mutation paths stay stable.
        Route::post('team/closure', [TeamController::class, 'requestClosure'])->name('organization.closure.request');
        Route::delete('team/closure', [TeamController::class, 'cancelClosure'])->name('organization.closure.cancel');
    });
});

require __DIR__.'/app.php';
require __DIR__.'/settings.php';
require __DIR__.'/admin.php';
