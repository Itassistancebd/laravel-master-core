export interface CoreNotification {
    id: string;
    event_key: string;
    title: string;
    message: string;
    read_at: string | null;
    created_at: string;
}

export interface NotificationPage {
    items: CoreNotification[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
}
