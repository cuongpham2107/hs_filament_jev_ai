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
    usage?: { input_tokens: number; cost_usd: number }
    links: CodeLinks
}

/** "35.210 token · $0,0015" — tiền chỉ tính token vào, theo bảng giá trong config/hs.php. */
function usageLabel(usage: { input_tokens: number; cost_usd: number }): string {
    const tokens = new Intl.NumberFormat('vi-VN').format(usage.input_tokens)
    const cost = usage.cost_usd.toLocaleString('vi-VN', {
        minimumFractionDigits: 4,
        maximumFractionDigits: 4,
    })

    return `${tokens} token · $${cost}`
}

/**
 * AI Elements "Reasoning": Jev không viết ra suy nghĩ, nên ở đây là căn cứ thật Jev đưa ra ở từng bước
 * (chương, nhóm, đường đi trong cây kèm xác suất). Thu gọn mặc định — chỉ còn dòng thời gian/token; bấm mới mở.
 */
export function Reasoning({ lines, running, seconds, usage, links }: ReasoningProps) {
    const [open, setOpen] = useState<boolean | null>(null)
    if (lines.length === 0 && !running) {
        return null
    }

    return (
        <Trace>
            <TraceCollapsible open={open ?? false} onOpenChange={setOpen}>
                <TraceCollapsibleTrigger>
                    <TraceLine status={running ? 'running' : undefined}>
                        <TraceIcon>{running ? <BrainIcon /> : <CircleCheckIcon />}</TraceIcon>
                        <TraceTitle>{running ? 'Đang phân loại' : 'Căn cứ phân loại'}</TraceTitle>
                        {seconds !== undefined && <TraceDescription>{seconds.toFixed(1)} giây</TraceDescription>}
                        {usage && usage.input_tokens > 0 && (
                            <TraceDescription>{usageLabel(usage)}</TraceDescription>
                        )}
                    </TraceLine>
                </TraceCollapsibleTrigger>
                <TraceCollapsibleContent className="pl-6 text-sm whitespace-pre-wrap text-muted-foreground">
                    {lines.length > 0 ? <LinkedText text={lines.join('\n')} links={links} /> : 'Đang chờ Jev chọn chương…'}
                </TraceCollapsibleContent>
            </TraceCollapsible>
        </Trace>
    )
}
