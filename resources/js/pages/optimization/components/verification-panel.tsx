import { OptimizationPlan } from '@/types/optimization';
import { Badge } from '@/components/ui/badge';
import { CheckIcon, CircleHelpIcon, TriangleAlertIcon } from 'lucide-react';

/**
 * What the server reported after a plan was applied.
 *
 * A write that succeeded and a setting that took effect are different claims: a
 * value can be written correctly, pass the config check, and still be overridden
 * later in the file or ignored because it needed a restart that only reloaded.
 * Without this the operator has no way to tell the difference.
 */
export default function VerificationPanel({ plan }: { plan: OptimizationPlan }) {
  const results = plan.verification ?? [];

  if (results.length === 0) {
    return null;
  }

  const failed = results.filter((r) => r.status === 'fail');
  const unknown = results.filter((r) => r.status === 'unknown');
  const passed = results.filter((r) => r.status === 'pass');

  return (
    <div className="rounded-lg border">
      <div className="bg-muted/40 flex items-center justify-between border-b px-4 py-2">
        <h3 className="text-sm font-medium">What the server reports back</h3>
        <div className="flex items-center gap-2">
          {passed.length > 0 && <Badge variant="success">{passed.length} in force</Badge>}
          {failed.length > 0 && <Badge variant="danger">{failed.length} not applied</Badge>}
          {unknown.length > 0 && <Badge variant="gray">{unknown.length} not checked</Badge>}
        </div>
      </div>

      {/* Failures first: they are the only rows that need someone to act. */}
      {[...failed, ...unknown, ...passed].map((result) => (
        <div key={`${result.component}.${result.config_key}`} className="flex items-center gap-3 border-b px-4 py-2 last:border-b-0">
          {result.status === 'pass' && <CheckIcon className="text-success size-4 shrink-0" />}
          {result.status === 'fail' && <TriangleAlertIcon className="text-destructive size-4 shrink-0" />}
          {result.status === 'unknown' && <CircleHelpIcon className="text-muted-foreground size-4 shrink-0" />}

          <span className="min-w-0 flex-1 font-mono text-sm">{result.config_key}</span>

          {result.status === 'fail' ? (
            <span className="flex items-center gap-2 font-mono text-sm">
              <span className="text-muted-foreground">wanted {result.expected}</span>
              <span className="text-destructive font-medium">got {result.actual}</span>
            </span>
          ) : (
            <span className="text-muted-foreground font-mono text-sm">
              {result.actual ?? 'not read'}
            </span>
          )}
        </div>
      ))}

      {failed.length > 0 && (
        <div className="text-muted-foreground border-t px-4 py-3 text-xs">
          A setting the server does not report back was written but is not in force — usually because something later
          in the configuration overrides it, or because it needs a restart the service has not had.
        </div>
      )}
    </div>
  );
}
