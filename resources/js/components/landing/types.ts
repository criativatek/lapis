/** One plan, as HomeController sends it. */
export type LandingPlan = {
    key: string;
    name: string;
    /**
     * Every module the plan carries, by entitlement key. The comparison table
     * asks «does this plan carry `advanced_analytics`», which a display name
     * cannot answer without breaking the day somebody renames one.
     */
    moduleKeys: string[];
};
