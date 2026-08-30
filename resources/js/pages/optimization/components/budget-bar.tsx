import { OptimizationBudget } from '@/types/optimization';

/**
 * The machine's memory, divided the way the optimizer divided it.
 *
 * Showing the whole pie rather than only the numbers we changed is what makes a
 * proposal arguable: a reader can see that PHP-FPM is small because the database
 * reserve is large, and disagree with the split rather than with the result.
 */
export default function BudgetBar({ budget }: { budget: OptimizationBudget }) {
  const segments = [
    { label: 'OS', value: budget.os_mb, className: 'bg-muted-foreground/40' },
    { label: 'Database', value: budget.database_mb, className: 'bg-chart-1' },
    { label: 'Redis', value: budget.redis_mb, className: 'bg-chart-2' },
    { label: 'Workers', value: budget.workers_mb, className: 'bg-chart-3' },
    { label: 'OPcache', value: budget.opcache_mb, className: 'bg-chart-4' },
    { label: 'PHP-FPM pool', value: budget.fpm_pool_mb, className: 'bg-chart-5' },
  ].filter((segment) => segment.value > 0);

  const total = budget.total_ram_mb || 1;
  const percent = (value: number) => Math.round((value / total) * 1000) / 10;
  const format = (mb: number) => (mb >= 1024 ? `${(mb / 1024).toFixed(1)} GB` : `${mb} MB`);

  return (
    <div className="flex flex-col gap-3">
      <div className="flex items-baseline justify-between">
        <span className="text-sm font-medium">Memory budget</span>
        <span className="text-muted-foreground text-sm">{format(budget.total_ram_mb)} total</span>
      </div>

      <div
        className="bg-muted flex h-3 w-full overflow-hidden rounded-full"
        role="img"
        aria-label={segments.map((s) => `${s.label} ${format(s.value)}`).join(', ')}
      >
        {segments.map((segment) => (
          <div key={segment.label} className={segment.className} style={{ width: `${percent(segment.value)}%` }} />
        ))}
      </div>

      <div className="flex flex-wrap gap-x-4 gap-y-1">
        {segments.map((segment) => (
          <div key={segment.label} className="flex items-center gap-1.5">
            <span className={`size-2 rounded-full ${segment.className}`} />
            <span className="text-xs">{segment.label}</span>
            <span className="text-muted-foreground text-xs">{format(segment.value)}</span>
          </div>
        ))}
      </div>

      <p className="text-muted-foreground text-xs">
        {budget.max_workers} PHP-FPM workers fit in {format(budget.fpm_pool_mb)} at a measured {budget.worker_rss_mb} MB
        each.
      </p>
    </div>
  );
}
