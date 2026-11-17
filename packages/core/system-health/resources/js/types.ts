export type HealthCheckStatus = 'healthy' | 'unavailable' | 'not_configured';
export type HealthCheckOutcome = 'successful' | 'failed' | 'not_configured';

export interface HealthCheckResult {
    key: string;
    name: string;
    status: HealthCheckStatus;
    outcome: HealthCheckOutcome;
    message: string;
}

export interface SystemHealthSnapshot {
    checked_at: string;
    overall_status: HealthCheckStatus;
    scope: string;
    checks: HealthCheckResult[];
}
