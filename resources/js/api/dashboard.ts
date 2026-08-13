import { http } from '@/api/http';
import type { DashboardData } from '@/types/models';

export function getDashboard() {
    return http.get<{ data: DashboardData }>('/api/dashboard');
}
