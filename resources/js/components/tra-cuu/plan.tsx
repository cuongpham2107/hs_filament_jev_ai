import { CircleCheckIcon, CircleIcon, CircleXIcon, LoaderCircleIcon, RouteIcon } from 'lucide-react'
import {
    Trace,
    TraceCollapsible,
    TraceCollapsibleContent,
    TraceCollapsibleTrigger,
    TraceDescription,
    TraceIcon,
    TraceLine,
    TraceTitle,
} from '@/components/ui/trace'
import type { Step, StepStatus } from './types'

const ICONS: Record<StepStatus, typeof CircleIcon> = {
    pending: CircleIcon,
    running: LoaderCircleIcon,
    done: CircleCheckIcon,
    error: CircleXIcon,
}

function lineStatus(status: StepStatus): 'running' | 'error' | 'pending' | undefined {
    return status === 'done' ? undefined : status
}

interface PlanProps {
    steps: readonly Step[]
    statuses: Record<string, StepStatus>
    running: boolean
}

/** AI Elements "Plan": các bước của workflow biết trước, mỗi bước chờ / đang chạy / xong / lỗi. */
export function Plan({ steps, statuses, running }: PlanProps) {
    const done = steps.filter((step) => statuses[step.id] === 'done').length
    const failed = steps.some((step) => statuses[step.id] === 'error')

    return (
        <Trace>
            <TraceCollapsible defaultOpen={running}>
                <TraceCollapsibleTrigger>
                    <TraceLine status={failed ? 'error' : running ? 'running' : undefined}>
                        <TraceIcon><RouteIcon /></TraceIcon>
                        <TraceTitle>Các bước tra cứu</TraceTitle>
                        <TraceDescription>{done}/{steps.length} bước xong</TraceDescription>
                    </TraceLine>
                </TraceCollapsibleTrigger>
                <TraceCollapsibleContent>
                    <Trace className="pl-6">
                        {steps.map((step) => {
                            const status = statuses[step.id] ?? 'pending'
                            const Icon = ICONS[status]
                            return (
                                <TraceLine key={step.id} status={lineStatus(status)}>
                                    <TraceIcon>
                                        <Icon className={status === 'running' ? 'animate-spin' : undefined} />
                                    </TraceIcon>
                                    <TraceTitle>{step.label}</TraceTitle>
                                </TraceLine>
                            )
                        })}
                    </Trace>
                </TraceCollapsibleContent>
            </TraceCollapsible>
        </Trace>
    )
}
