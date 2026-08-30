import { Head, router, usePage } from '@inertiajs/react';
import { Server } from '@/types/server';
import ServerLayout from '@/layouts/server/layout';
import HeaderContainer from '@/components/header-container';
import Heading from '@/components/heading';
import Container from '@/components/container';
import { Button } from '@/components/ui/button';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { GaugeIcon, TriangleAlertIcon } from 'lucide-react';
import { useState } from 'react';
import { OptimizationPlan } from '@/types/optimization';
import BudgetBar from '@/pages/optimization/components/budget-bar';
import ProposalList from '@/pages/optimization/components/proposal-list';
import ApplyActions from '@/pages/optimization/components/apply-actions';
import VerificationPanel from '@/pages/optimization/components/verification-panel';

export default function Optimization() {
  const page = usePage<{
    server: Server;
    plan: OptimizationPlan | null;
    hasDatabase: boolean;
  }>();

  const [analyzing, setAnalyzing] = useState(false);
  const plan = page.props.plan;

  const analyze = () => {
    router.post(
      route('optimization.analyze', { server: page.props.server.id }),
      {},
      {
        onStart: () => setAnalyzing(true),
        onFinish: () => setAnalyzing(false),
      },
    );
  };

  return (
    <ServerLayout>
      <Head title={`Optimization - ${page.props.server.name}`} />

      <Container className="max-w-5xl">
        <HeaderContainer>
          <Heading
            title="Optimization"
            description="Tuning values derived from this server's own resources"
          />
          <div className="flex items-center gap-2">
            {plan && <ApplyActions server={page.props.server} plan={plan} />}
            <Button onClick={analyze} disabled={analyzing} variant={plan ? 'outline' : 'default'}>
              <GaugeIcon />
              {analyzing ? 'Analyzing...' : 'Analyze server'}
            </Button>
          </div>
        </HeaderContainer>

        {plan?.facts && plan.facts.oom_kill_count > 0 && (
          <Alert variant="destructive">
            <TriangleAlertIcon />
            <AlertDescription>
              The kernel has killed {plan.facts.oom_kill_count} process
              {plan.facts.oom_kill_count === 1 ? '' : 'es'} on this server to reclaim memory. That usually means
              something is configured to use more than the machine has.
            </AlertDescription>
          </Alert>
        )}

        {!plan && (
          <div className="rounded-lg border border-dashed p-8 text-center">
            <p className="text-muted-foreground text-sm">
              This server has not been analyzed yet. Analyzing reads its configuration over SSH and reports what should
              change. It writes nothing.
            </p>
          </div>
        )}

        {plan && (
          <div className="flex flex-col gap-6">
            <div className="rounded-lg border p-4">
              {plan.budget && <BudgetBar budget={plan.budget} />}
            </div>

            {plan.facts && !plan.facts.db_local && (
              <Alert>
                <AlertDescription>
                  No database is installed here, so this server reserves no memory for one and its PHP-FPM pool is
                  sized accordingly.
                </AlertDescription>
              </Alert>
            )}

            <VerificationPanel plan={plan} />

            {plan.proposals && plan.proposals.length > 0 ? (
              <ProposalList server={page.props.server} plan={plan} proposals={plan.proposals} />
            ) : (
              <div className="rounded-lg border border-dashed p-8 text-center">
                <p className="text-muted-foreground text-sm">
                  Nothing to propose for this server. Every setting the optimizer checks is already correct.
                </p>
              </div>
            )}

            <div className="text-muted-foreground flex items-center gap-2 text-xs">
              <Badge variant={plan.status_color}>{plan.status}</Badge>
              <span>Analyzed {new Date(plan.created_at).toLocaleString()}</span>
            </div>
          </div>
        )}
      </Container>
    </ServerLayout>
  );
}
