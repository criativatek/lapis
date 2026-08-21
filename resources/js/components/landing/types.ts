/** One plan card, as HomeController sends it. */
export type LandingPlan = {
    key: string;
    name: string;
    /** Every module the plan carries. */
    modules: string[];
    /** Only what it adds to the plan below it — empty for the first one. */
    adds: string[];
};
