import { useState } from 'react'
import { CheckIcon, CircleAlertIcon, CircleCheckIcon, CopyIcon, ExternalLinkIcon } from 'lucide-react'
import { Badge } from '@/components/ui/badge'
import { Progress } from '@/components/ui/progress'
import { codeHref, type CodeLinks } from './links'

interface SummaryProps {
    status: 'ok' | 'can_bo_sung'
    code: string | null
    confidence: number
    links: CodeLinks
}

function formatCode(code: string): string {
    return `${code.slice(0, 4)}.${code.slice(4, 6)}.${code.slice(6)}`
}

/** Dải đầu câu trả lời: trạng thái, mã (bấm để mở bản ghi trong CSDL, sao chép), độ tin cậy. */
export function Summary({ status, code, confidence, links }: SummaryProps) {
    const [copied, setCopied] = useState(false)
    const ok = status === 'ok'
    const percent = Math.round(confidence * 1000) / 10
    const href = code ? codeHref(links, code) : null

    const copy = async () => {
        if (!code) {
            return
        }
        await navigator.clipboard.writeText(code)
        setCopied(true)
        setTimeout(() => setCopied(false), 1500)
    }

    return (
        <div className="flex flex-col gap-3 rounded-xl border bg-card p-4 shadow-xs">
            <div className="flex flex-wrap items-center gap-3">
                <Badge className={ok
                    ? 'bg-emerald-600/10 text-emerald-700 dark:bg-emerald-400/10 dark:text-emerald-300'
                    : 'bg-amber-500/15 text-amber-700 dark:bg-amber-400/10 dark:text-amber-300'}>
                    {ok ? <CircleCheckIcon /> : <CircleAlertIcon />}
                    {ok ? 'Đã xác định mã' : 'Cần bổ sung thông tin'}
                </Badge>
                {code && (
                    <div className="flex items-center gap-1">
                        {href ? (
                            <a href={href} target="_blank" rel="noopener" title="Mở dòng mã này trong biểu thuế"
                                className="group inline-flex items-center gap-1.5 rounded-lg px-1 font-mono text-2xl font-semibold tracking-tight hover:bg-muted">
                                {formatCode(code)}
                                <ExternalLinkIcon className="size-4 text-muted-foreground group-hover:text-foreground" />
                            </a>
                        ) : (
                            <span className="font-mono text-2xl font-semibold tracking-tight">{formatCode(code)}</span>
                        )}
                        <button type="button" onClick={copy} aria-label="Sao chép mã"
                            className="rounded-md p-1.5 text-muted-foreground hover:bg-muted hover:text-foreground">
                            {copied ? <CheckIcon className="size-4" /> : <CopyIcon className="size-4" />}
                        </button>
                        {!ok && <span className="text-xs text-muted-foreground">(đang nghiêng về, chưa kết luận)</span>}
                    </div>
                )}
            </div>
            <div className="flex items-center gap-3">
                <span className="w-24 shrink-0 text-xs text-muted-foreground">Độ tin cậy</span>
                <Progress value={percent} className="flex-1" aria-label="Độ tin cậy" />
                <span className="w-12 shrink-0 text-right text-sm font-medium tabular-nums">{percent}%</span>
            </div>
        </div>
    )
}
