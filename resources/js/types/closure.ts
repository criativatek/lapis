/**
 * A recoverable closure request, as computed backend-side
 * (App\Support\Retention\ClosureStatusPresenter). The client only ever
 * displays `recoverable`/`days_remaining` — it never recomputes the boundary
 * (§14 of the lifecycle brief).
 */
export type ClosureStatus = {
    requested_at: string;
    scheduled_deletion_at: string;
    days_remaining: number;
    recoverable: boolean;
};

export type OrganizationClosureStatus = ClosureStatus & {
    is_owner: boolean;
};
