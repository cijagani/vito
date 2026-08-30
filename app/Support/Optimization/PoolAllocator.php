<?php

namespace App\Support\Optimization;

use App\Enums\SiteLoadClass;
use App\Models\Site;
use Illuminate\Support\Collection;

/**
 * Divides the PHP-FPM pool between the sites on a server.
 *
 * An even split is wrong the moment a busy application shares a machine with
 * brochure sites: the application is starved while memory sits reserved for pages
 * that are served from cache. Each site therefore carries a load class, and the
 * pool is divided by weight.
 */
class PoolAllocator
{
    /**
     * Every site keeps enough workers to serve concurrent requests. Below two, a
     * pool is a queue rather than a pool.
     */
    private const int MIN_WORKERS_PER_SITE = 2;

    private const int MIN_MEMORY_LIMIT_MB = 128;

    private const int MAX_MEMORY_LIMIT_MB = 512;

    /**
     * @param  Collection<int, Site>|array<int, Site>  $sites
     * @return array<int, array{site: Site, pool_mb: int, memory_limit_mb: int, max_children: int}>
     */
    public function allocate(iterable $sites, int $poolMb): array
    {
        $eligible = collect($sites)->filter(fn (Site $site): bool => $this->usesPhpPool($site))->values();

        if ($eligible->isEmpty()) {
            return [];
        }

        $totalWeight = $eligible->sum(fn (Site $site): int => $this->loadClass($site)->weight());

        // Fund every site's floor first. Weighting the whole pool instead would
        // let one busy site round its quieter neighbours down to nothing.
        $floorMb = self::MIN_WORKERS_PER_SITE * self::MIN_MEMORY_LIMIT_MB;
        $floorTotal = $floorMb * $eligible->count();
        $weightedMb = max($poolMb - $floorTotal, 0);

        $allocations = [];

        foreach ($eligible as $site) {
            $weight = $this->loadClass($site)->weight();
            $share = $floorMb + (int) ($weightedMb * $weight / $totalWeight);

            $memoryLimit = $this->memoryLimit($share);
            $maxChildren = max((int) ($share / $memoryLimit), self::MIN_WORKERS_PER_SITE);

            $allocations[] = [
                'site' => $site,
                'pool_mb' => $share,
                'memory_limit_mb' => $memoryLimit,
                'max_children' => $maxChildren,
            ];
        }

        return $allocations;
    }

    /**
     * Sites served entirely by the web server or proxied to another runtime hold
     * no PHP workers, so they take no share of the pool.
     */
    private function usesPhpPool(Site $site): bool
    {
        return $site->php_version !== null && $site->php_version !== '';
    }

    private function loadClass(Site $site): SiteLoadClass
    {
        return $site->load_class ?? SiteLoadClass::MEDIUM;
    }

    /**
     * What one worker is permitted to use. Derived from the site's own share so
     * that max_children and memory_limit come from a single division rather than
     * two independent guesses that quietly disagree.
     */
    private function memoryLimit(int $shareMb): int
    {
        $limit = (int) ($shareMb / 10);

        return max(self::MIN_MEMORY_LIMIT_MB, min($limit, self::MAX_MEMORY_LIMIT_MB));
    }
}
