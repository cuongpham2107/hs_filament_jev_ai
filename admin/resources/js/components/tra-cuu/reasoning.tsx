import { useState } from 'react'
import { BrainIcon, CircleCheckIcon } from 'lucide-react'
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
import { LinkedText, type CodeLinks } from './links'

interface ReasoningProps {
    lines: string[]
    running: boolean
    seconds?: number
    links: CodeLinks
}

/**
 * AI Elements "Reasoning": Jev không viết ra suy nghĩ, nên ở đây là căn cứ thật Jev đưa ra ở từng bước
 * (chương, nhóm, đường đi trong cây kèm xác suất). Luôn mở sẵn; người dùng tự thu lại nếu muốn.
 */
export function Reasoning({ lines, running, seconds, links }: ReasoningProps) {
    const [open, setOpen] = useState<boolean | null>(null)
    if (lines.length === 0 && !running) {
        return null
    }

    return (
        <Trace>
            <TraceCollapsible open={open ?? true} onOpenChange={setOpen}>
                <TraceCollapsibleTrigger>
                    <TraceLine status={running ? 'running' : undefined}>
                        <TraceIcon>{running ? <BrainIcon /> : <CircleCheckIcon />}</TraceIcon>
                        <TraceTitle>{running ? 'Đang phân loại' : 'Căn cứ phân loại'}</TraceTitle>
                        {seconds !== undefined && <TraceDescription>{seconds.toFixed(1)} giây</TraceDescription>}
                    </TraceLine>
                </TraceCollapsibleTrigger>
                <TraceCollapsibleContent className="pl-6 text-sm whitespace-pre-wrap text-muted-foreground">
                    {lines.length > 0 ? <LinkedText text={lines.join('\n')} links={links} /> : 'Đang chờ Jev chọn chương…'}
                </TraceCollapsibleContent>
            </TraceCollapsible>
        </Trace>
    )
}
