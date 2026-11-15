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
import type { EvaluationStep } from './types'

interface ReasoningProps {
    lines: string[]
    evaluation: EvaluationStep[]
    running: boolean
    seconds?: number
}

const SURE = 0.7 // cùng ngưỡng với scripts/wf_code_d.py: mỗi bước phải >= 70% mới trả mã

/** Các lựa chọn Jev chấm ở một bước, nhánh được chọn tô đậm, thanh dài theo xác suất. */
function EvaluationList({ step }: { step: EvaluationStep }) {
    return (
        <div className="flex flex-col gap-1">
            <p className="text-xs font-medium text-foreground">{step.buoc}</p>
            <ul className="flex flex-col gap-1">
                {step.lua_chon.map((option, index) => (
                    <li key={index} className="grid grid-cols-[3.5rem_1fr] items-start gap-2">
                        <span className="flex flex-col gap-0.5 pt-0.5">
                            <span className={`text-xs tabular-nums ${option.chon ? 'font-semibold text-foreground' : ''}`}>
                                {(option.p * 100).toFixed(option.p < 0.1 ? 1 : 0)}%
                            </span>
                            <span className="h-1 w-full rounded-full bg-muted">
                                <span
                                    className={`block h-1 rounded-full ${option.chon ? (option.p >= SURE ? 'bg-primary' : 'bg-amber-500') : 'bg-muted-foreground/40'}`}
                                    style={{ width: `${Math.max(option.p * 100, 2)}%` }}
                                />
                            </span>
                        </span>
                        <span className={option.chon ? 'font-medium text-foreground' : ''}>
                            {option.chon ? '✓ ' : ''}{option.ten}
                        </span>
                    </li>
                ))}
            </ul>
        </div>
    )
}

/**
 * AI Elements "Reasoning": Jev không viết ra suy nghĩ, nên ở đây là căn cứ thật Jev đưa ra ở từng bước —
 * chương, nhóm đã xét, và xác suất Jev chấm cho mọi lựa chọn ở mỗi bước. Mở khi đang chạy, tự đóng khi xong.
 */
export function Reasoning({ lines, evaluation, running, seconds }: ReasoningProps) {
    const [open, setOpen] = useState<boolean | null>(null)
    if (lines.length === 0 && !running) {
        return null
    }

    return (
        <Trace>
            <TraceCollapsible open={open ?? running} onOpenChange={setOpen}>
                <TraceCollapsibleTrigger>
                    <TraceLine status={running ? 'running' : undefined}>
                        <TraceIcon>{running ? <BrainIcon /> : <CircleCheckIcon />}</TraceIcon>
                        <TraceTitle>{running ? 'Đang phân loại' : 'Căn cứ phân loại'}</TraceTitle>
                        {seconds !== undefined && <TraceDescription>{seconds.toFixed(1)} giây</TraceDescription>}
                    </TraceLine>
                </TraceCollapsibleTrigger>
                <TraceCollapsibleContent className="flex flex-col gap-4 pl-6 text-sm text-muted-foreground">
                    <p className="whitespace-pre-wrap">{lines.length > 0 ? lines.join('\n') : 'Đang chờ Jev chọn chương…'}</p>
                    {evaluation.length > 0 && (
                        <div className="flex flex-col gap-3">
                            <p className="text-xs">
                                Jev chấm từng lựa chọn ở mỗi bước (✓ là nhánh được chọn; mỗi bước phải ≥ {SURE * 100}% mới trả mã):
                            </p>
                            {evaluation.map((step, index) => <EvaluationList key={index} step={step} />)}
                        </div>
                    )}
                </TraceCollapsibleContent>
            </TraceCollapsible>
        </Trace>
    )
}
