import { CircleAlertIcon, CircleCheckIcon, CircleIcon, ListTodoIcon, LoaderCircleIcon } from 'lucide-react'
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
import type { Turn } from './types'

function shortName(message: string): string {
    const line = message.replace(/\s+/g, ' ').trim()
    return line.length > 90 ? `${line.slice(0, 90)}…` : line
}

function itemNote(turn: Turn): string | undefined {
    if (turn.status === 'error') {
        return 'lỗi'
    }
    if (turn.result?.status === 'can_bo_sung') {
        return 'cần bổ sung'
    }
    return undefined
}

interface QueueProps {
    turns: Turn[]
    onSelect: (id: string) => void
}

/** AI Elements "Queue": các mặt hàng dán cùng lúc, chia hai phần Cần tra / Đã xong. */
export function Queue({ turns, onSelect }: QueueProps) {
    const todo = turns.filter((turn) => turn.status === 'queued' || turn.status === 'running')
    const done = turns.filter((turn) => turn.status === 'done' || turn.status === 'error')

    return (
        <Trace className="rounded-lg border p-3">
            <TraceCollapsible defaultOpen>
                <TraceCollapsibleTrigger>
                    <TraceLine status={todo.length > 0 ? 'running' : undefined}>
                        <TraceIcon><ListTodoIcon /></TraceIcon>
                        <TraceTitle>{todo.length} mặt hàng cần tra</TraceTitle>
                    </TraceLine>
                </TraceCollapsibleTrigger>
                <TraceCollapsibleContent>
                    <Trace className="pl-6">
                        {todo.map((turn) => (
                            <TraceLine key={turn.id} status={turn.status === 'running' ? 'running' : 'pending'}>
                                <TraceIcon>
                                    {turn.status === 'running' ? <LoaderCircleIcon className="animate-spin" /> : <CircleIcon />}
                                </TraceIcon>
                                <TraceTitle>{shortName(turn.message)}</TraceTitle>
                            </TraceLine>
                        ))}
                    </Trace>
                </TraceCollapsibleContent>
            </TraceCollapsible>
            <TraceCollapsible defaultOpen={false}>
                <TraceCollapsibleTrigger>
                    <TraceLine>
                        <TraceIcon><CircleCheckIcon /></TraceIcon>
                        <TraceTitle>{done.length} đã xong</TraceTitle>
                    </TraceLine>
                </TraceCollapsibleTrigger>
                <TraceCollapsibleContent>
                    <Trace className="pl-6">
                        {done.map((turn) => (
                            <button key={turn.id} type="button" onClick={() => onSelect(turn.id)}>
                                <TraceLine status={turn.status === 'error' ? 'error' : undefined}>
                                    <TraceIcon>
                                        {itemNote(turn) ? <CircleAlertIcon /> : <CircleCheckIcon />}
                                    </TraceIcon>
                                    <TraceTitle>{shortName(turn.message)}</TraceTitle>
                                    {itemNote(turn) && <TraceDescription>{itemNote(turn)}</TraceDescription>}
                                </TraceLine>
                            </button>
                        ))}
                    </Trace>
                </TraceCollapsibleContent>
            </TraceCollapsible>
        </Trace>
    )
}
