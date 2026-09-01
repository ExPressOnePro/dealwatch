import { type PipelineRun, type PipelineRuns } from '@/types/deal';

const TITLES: Record<string, string> = {
    collect: 'Сбор с 999.md',
    analytics: 'Пересчёт аналитики',
};

const TONES: Record<string, string> = {
    queued: 'surface-info',
    running: 'surface-info',
    done: 'surface-success',
    failed: 'surface-danger',
};

function when(run: NonNullable<PipelineRun>): string | null {
    const stamp = run.finished_at || run.started_at || run.queued_at;
    if (!stamp) return null;

    return new Date(stamp).toLocaleString('ru-MD', { hour: '2-digit', minute: '2-digit', day: '2-digit', month: '2-digit' });
}

/**
 * Сбор и пересчёт выполняются в очереди, поэтому их результат приходит
 * не в ответ на нажатие, а сюда — при следующей загрузке страницы.
 */
export function RunStatus({ runs }: { runs?: PipelineRuns }) {
    const entries = Object.entries(runs ?? {}).filter(([, run]) => Boolean(run)) as Array<[string, NonNullable<PipelineRun>]>;

    if (entries.length === 0) {
        return null;
    }

    return (
        <div className="grid gap-2 sm:grid-cols-2">
            {entries.map(([key, run]) => (
                <div key={key} className={`rounded-lg border px-3 py-2 text-sm ${TONES[run.state] ?? 'surface-neutral'}`}>
                    <div className="flex items-baseline justify-between gap-2">
                        <span className="text-xs font-semibold tracking-wide uppercase opacity-80">{TITLES[key] ?? key}</span>
                        {when(run) && <span className="text-xs opacity-70">{when(run)}</span>}
                    </div>
                    <div className="mt-0.5 leading-snug">
                        {run.state === 'queued' && '⏳ '}
                        {run.state === 'running' && '⚙️ '}
                        {run.state === 'failed' && '⚠️ '}
                        {run.message}
                    </div>
                    {(run.state === 'queued' || run.state === 'running') && (
                        <div className="mt-1 text-xs opacity-70">Обнови страницу, чтобы увидеть результат.</div>
                    )}
                </div>
            ))}
        </div>
    );
}
