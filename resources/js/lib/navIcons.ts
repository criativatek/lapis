import {
    BarChart3,
    BookOpen,
    Building2,
    CalendarClock,
    CalendarDays,
    CalendarRange,
    CircleHelp,
    ClipboardList,
    Download,
    FileText,
    Footprints,
    GraduationCap,
    HeartHandshake,
    LayoutGrid,
    NotebookPen,
    PenLine,
    PieChart,
    Settings,
    Share2,
    SlidersHorizontal,
    Table2,
    TrendingUp,
    UserCheck,
    Users,
} from '@lucide/vue';
import type { LucideIcon } from '@lucide/vue';

/**
 * Maps the icon name in config/navigation.php to its component. The backend
 * sends a name string, not a component, so the menu stays data-driven; this is
 * the one place that knows which names are real. A missing name falls back to a
 * neutral icon rather than crashing the shell.
 */
const icons: Record<string, LucideIcon> = {
    LayoutGrid,
    Users,
    GraduationCap,
    SlidersHorizontal,
    ClipboardList,
    Download,
    PenLine,
    BarChart3,
    UserCheck,
    NotebookPen,
    HeartHandshake,
    // A path one person walked. Deliberately not another chart: «Acompanhamento
    // do Aluno» sits directly under «Análise da Turma», and two graph icons
    // side by side would say the two pages do the same thing to different data
    // — which is exactly the confusion this menu is being fixed to remove.
    Footprints,
    TrendingUp,
    PieChart,
    FileText,
    CalendarDays,
    CalendarRange,
    // A clock inside the calendar: «Horário do Professor» is about the hours
    // of the week, next to a «Calendário» (its days) and an «Estrutura» (its
    // spans). The three sit together and must not read as the same thing.
    CalendarClock,
    BookOpen,
    Building2,
    Settings,
    Share2,
    CircleHelp,
    Table2,
};

export function navIcon(name: string): LucideIcon {
    return icons[name] ?? LayoutGrid;
}
