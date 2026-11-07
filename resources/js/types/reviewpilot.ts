export type Tenant = {
    organization: {
        id: string;
        name: string;
        slug: string;
        automation_kill_switch: boolean;
    };
    role: string;
    canManage: boolean;
    canBill: boolean;
    plan: string | null;
    onTrial: boolean;
    trialEndsAt: string | null;
    pendingApprovals: number;
    organizations: { id: string; name: string }[];
    demoMode: { ai: boolean; google: boolean };
    automationPausedGlobally: boolean;
};

export type Paginated<T> = {
    data: T[];
    links: { url: string | null; label: string; active: boolean }[];
    current_page: number;
    last_page: number;
    total: number;
    from: number | null;
    to: number | null;
};

export type ReviewListItem = {
    id: string;
    reviewer: string;
    rating: number;
    excerpt: string;
    summary: string | null;
    sentiment: string | null;
    risk_flags: string[];
    risk_score: number | null;
    location: { id: string; name: string | null };
    state: string;
    state_label: string;
    priority: number;
    created_at: string;
    language: string | null;
};

export type Draft = {
    id: string;
    variant: string;
    text: string;
    language: string | null;
    guardrails_passed: boolean;
    failed_checks: { name: string; pass: boolean; message: string | null }[];
};

export type Option = { id: string; name: string };
