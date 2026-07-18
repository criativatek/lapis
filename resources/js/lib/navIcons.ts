import {
    BarChart3,
    BookOpen,
    Building2,
    CalendarDays,
    ClipboardList,
    FileText,
    GraduationCap,
    HeartHandshake,
    LayoutGrid,
    NotebookPen,
    PenLine,
    PieChart,
    Settings,
    SlidersHorizontal,
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
    PenLine,
    BarChart3,
    UserCheck,
    NotebookPen,
    HeartHandshake,
    TrendingUp,
    PieChart,
    FileText,
    CalendarDays,
    BookOpen,
    Building2,
    Settings,
};

export function navIcon(name: string): LucideIcon {
    return icons[name] ?? LayoutGrid;
}
