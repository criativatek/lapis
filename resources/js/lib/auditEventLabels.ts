/**
 * O nome humano de cada evento do audit trail (`audit_events.event`).
 *
 * Só eventos que ficam numa organização — os da plataforma (backoffice, motor
 * de IA) têm `organization_id` NULL e nunca chegam a esta página. Um evento
 * que ainda não esteja aqui mostra «Ação registada», nunca a chave técnica.
 */
const labels: Record<string, string> = {
    'classification.confirmed': 'Classificação confirmada',
    'classification.overridden': 'Classificação alterada',
    'classification.redecided': 'Classificação revista',
    'classification.published': 'Classificações publicadas',
    'domain-appreciation.decided': 'Apreciação por domínio decidida',
    'domain-appreciation.redecided': 'Apreciação por domínio revista',
    'domain-appreciation.cleared':
        'Apreciação por domínio devolvida à proposta',
    'class.profile_migrated': 'Turma migrada de versão de perfil',
    'class.reassigned': 'Turma atribuída a outro professor',
    'profile_version.activated': 'Versão de perfil ativada',
    'scores.stale_write_rejected':
        'Gravação de notas recusada por dados desatualizados',
    'results.ai_analysis_requested': 'Análise da turma pedida à IA',
    'results.ai_assessment_analysis_requested':
        'Análise de avaliação pedida à IA',
    'report.created': 'Relatório criado',
    'report.derived': 'Relatório criado a partir de outro',
    'report.finalized': 'Relatório finalizado',
    'report.exported': 'Documento exportado',
    'report.deleted': 'Relatório eliminado',
    'report.rewrite_suggested': 'Sugestão de redação pedida à IA',
    'report.rewrite_accepted': 'Sugestão de redação aceite',
    'report_template.created': 'Modelo de relatório criado',
    'report_template.duplicated': 'Modelo de relatório duplicado',
    'inovar.exported': 'Grelha INOVAR exportada',
    'evaluation-sheet.inovar.exported': 'Grelha INOVAR exportada da pauta',
    'class-synopsis.exported': 'Quadro Síntese exportado',
    'intervention.created': 'Estratégia ou medida registada',
    'intervention.updated': 'Estratégia ou medida alterada',
    'intervention.deleted': 'Estratégia ou medida eliminada',
    'intervention.status_changed': 'Estado de estratégia ou medida alterado',
    'intervention.reopened': 'Estratégia ou medida reaberta',
    'intervention.followup_added': 'Acompanhamento de medida registado',
    'intervention.ai_suggestion_requested':
        'Sugestão de estratégias pedida à IA',
    'evidence.rewrite_suggested': 'Sugestão de descrição pedida à IA',
    'student-progress.ai_synthesis_requested':
        'Síntese de acompanhamento pedida à IA',
    'help.ai_answer_requested': 'Pergunta ao assistente do Centro de Ajuda',
    'lesson.prepared': 'Aula preparada',
    'lesson.taught': 'Aula marcada como lecionada',
    'lesson.summary_reviewed': 'Sumário revisto',
    'lesson.summary_cleared': 'Sumário apagado',
    'lesson.inserted': 'Aula inserida',
    'lesson.deleted': 'Aula eliminada',
    'lesson.attendance_recorded': 'Assiduidade registada',
    'lesson.attendance_corrected': 'Assiduidade corrigida',
    'lesson_sequence.saved': 'Planificação guardada',
    'lesson_sequence.applied': 'Planificação aplicada',
    'calendar_event.created': 'Acontecimento do calendário criado',
    'calendar_event.updated': 'Acontecimento do calendário alterado',
    'configuration_package.exported': 'Configuração partilhada',
    'configuration_package.imported': 'Configuração importada',
    'configuration_package.reused': 'Configuração reutilizada',
    'data_export.requested': 'Exportação de dados pedida',
    'data_export.generated': 'Exportação de dados gerada',
    'data_import.uploaded': 'Cópia de segurança carregada',
    'data_import.completed': 'Cópia de segurança reposta',
    'data_import.failed': 'Reposição de cópia de segurança falhou',
    'data_import.cancelled': 'Reposição de cópia de segurança cancelada',
    'organization.invitation_created': 'Convite enviado',
    'organization.invitation_cancelled': 'Convite cancelado',
    'organization.invitation_accepted': 'Convite aceite',
    'organization.member_removed': 'Membro removido',
    'organization.member_left': 'Membro saiu da organização',
    'organization.ownership_transferred': 'Responsabilidade transferida',
    'organization.closure_requested': 'Encerramento da organização pedido',
    'organization.closure_cancelled': 'Encerramento da organização cancelado',
    'account.closure_requested': 'Encerramento da conta pedido',
    'account.closure_cancelled': 'Encerramento da conta cancelado',
    'commercial.voucher_redeemed': 'Código promocional usado',
    'commercial.payment_requested': 'Pagamento por transferência pedido',
    'capability.voucher_redeemed': 'Código de funcionalidade usado',
};

export function auditEventLabel(event: string): string {
    return labels[event] ?? 'Ação registada';
}
