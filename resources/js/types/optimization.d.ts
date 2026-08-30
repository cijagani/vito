export interface OptimizationBudget {
  total_ram_mb: number;
  os_mb: number;
  database_mb: number;
  redis_mb: number;
  workers_mb: number;
  opcache_mb: number;
  opcache_shm_mb: number;
  opcache_jit_mb: number;
  fpm_pool_mb: number;
  worker_rss_mb: number;
  max_workers: number;
}

export interface OptimizationFacts {
  total_ram_mb: number;
  cores: number;
  physical_cores: number;
  disk_rotational: boolean;
  virtualisation: string | null;
  db_local: boolean;
  redis_local: boolean;
  has_workers: boolean;
  php_versions: string[];
  avg_worker_rss_mb: number | null;
  swap_total_mb: number;
  oom_kill_count: number;
}

export interface OptimizationProposal {
  id: number;
  component: string;
  config_key: string;
  current_value: string | null;
  proposed_value: string;
  severity: string;
  severity_color: 'gray' | 'success' | 'info' | 'warning' | 'danger';
  apply_method: string;
  apply_method_color: 'gray' | 'success' | 'info' | 'warning' | 'danger';
  is_disruptive: boolean;
  rationale: string;
  kb_ref: string | null;
  clamped: boolean;
  accepted: boolean;
  is_change: boolean;
}

export interface OptimizationPlan {
  id: number;
  server_id: number;
  status: string;
  status_color: 'gray' | 'success' | 'info' | 'warning' | 'danger';
  source: string;
  budget: OptimizationBudget | null;
  facts: OptimizationFacts | null;
  ruleset_versions: Record<string, number> | null;
  is_disruptive: boolean;
  proposals?: OptimizationProposal[];
  created_at: string;
  applied_at: string | null;
  rolled_back_at: string | null;
}
