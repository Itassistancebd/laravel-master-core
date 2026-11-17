export interface AuditActorRef { type: string | null; id: string }

export interface AuditEventRecord {
    id: string;
    event_name: string;
    category: string;
    outcome: 'succeeded' | 'failed' | 'denied';
    source: string;
    actor: AuditActorRef | null;
    subject: AuditActorRef | null;
    correlation_id: string | null;
    request_method: string | null;
    route_name: string | null;
    ip_address: string | null;
    user_agent: string | null;
    before_values: Record<string, unknown> | null;
    after_values: Record<string, unknown> | null;
    metadata: Record<string, unknown> | null;
    occurred_at: string;
    created_at: string;
}

export interface AuditPageMeta { current_page: number; last_page: number; per_page: number; total: number }
export interface AuditPage { events: AuditEventRecord[]; meta: AuditPageMeta }
export interface AuditFilters {
    search?: string; from?: string; to?: string; category?: string; outcome?: string;
    event_name?: string; actor_id?: string; subject_id?: string; correlation_id?: string;
    sort?: 'occurred_at'; direction?: 'asc' | 'desc'; per_page?: number; page?: number;
}
