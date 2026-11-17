import { useEffect, useRef, useState } from 'react'
import { Streamdown, type Components } from 'streamdown'
import * as echarts from 'echarts/core'
import { PieChart } from 'echarts/charts'
import { LegendComponent, TooltipComponent } from 'echarts/components'
import { CanvasRenderer } from 'echarts/renderers'
import { linkCodesInMarkdown, type CodeLinks } from './links'
import { StructuredResultCard } from './result-card'

echarts.use([PieChart, LegendComponent, TooltipComponent, CanvasRenderer])

const ECHARTS_BLOCK = /```echarts\s*\n([\s\S]*?)\n```/g

type Part = { kind: 'markdown' | 'chart'; text: string }

/** Tách khối ```echarts (biểu đồ so sánh mã do workflow sinh) ra khỏi markdown để vẽ bằng ECharts. */
function splitCharts(markdown: string): Part[] {
    const parts: Part[] = []
    let last = 0
    for (const match of markdown.matchAll(ECHARTS_BLOCK)) {
        parts.push({ kind: 'markdown', text: markdown.slice(last, match.index) })
        parts.push({ kind: 'chart', text: match[1] })
        last = (match.index ?? 0) + match[0].length
    }
    parts.push({ kind: 'markdown', text: markdown.slice(last) })
    return parts.filter((part) => part.text.trim() !== '')
}

function EChart({ option }: { option: string }) {
    const ref = useRef<HTMLDivElement>(null)

    useEffect(() => {
        if (!ref.current) {
            return
        }
        const chart = echarts.init(ref.current)
        try {
            chart.setOption(JSON.parse(option))
        } catch {
            chart.dispose()
            return
        }
        const observer = new ResizeObserver(() => chart.resize())
        observer.observe(ref.current)
        return () => {
            observer.disconnect()
            chart.dispose()
        }
    }, [option])

    return <div ref={ref} className="h-52 w-full max-w-xl" role="img" aria-label="Biểu đồ so sánh xác suất các mã" />
}

/**
 * Link trong thẻ là <a> thật mở tab mới (giữ khung chat). Tắt link safety của Streamdown (nó biến link thành nút
 * hỏi xác nhận); link ngoài admin vẫn có rel="noopener noreferrer".
 */
const markdownComponents: Components = {
    a: ({ href, children }) => (
        <a href={href} target="_blank" rel="noopener noreferrer"
            className="font-mono font-medium text-foreground underline decoration-dotted underline-offset-4 hover:decoration-solid">
            {children}
        </a>
    ),
}

/** Stream từng đoạn markdown để thẻ kết quả hiện dần mượt mà như streaming text (bỏ qua khối echarts để không cắt giữa JSON). */
function useStreamedMarkdown(markdown: string, animate: boolean): string {
    const [length, setLength] = useState(() => (animate ? 0 : markdown.length))

    useEffect(() => {
        if (!animate) {
            setLength(markdown.length)
            return
        }
        setLength(0)
        const step = Math.max(18, Math.ceil(markdown.length / 50))
        const timer = window.setInterval(() => {
            setLength((prev) => {
                let next = Math.min(markdown.length, prev + step)
                const chartStart = markdown.indexOf('```echarts', prev)
                if (chartStart !== -1 && chartStart < next) {
                    const chartEnd = markdown.indexOf('\n```', chartStart + 10)
                    if (chartEnd !== -1) {
                        next = Math.max(next, chartEnd + 4)
                    }
                }
                if (next >= markdown.length) {
                    window.clearInterval(timer)
                }
                return next
            })
        }, 16)

        return () => window.clearInterval(timer)
    }, [markdown, animate])

    return animate ? markdown.slice(0, length) : markdown
}

/** Thẻ kết quả / thẻ cần bổ sung (markdown do workflow dựng), biểu đồ vẽ tại chỗ, mã 8 số thành link. */
export function Answer({ markdown, links, animate = false }: { markdown: string; links: CodeLinks; animate?: boolean }) {
    const visibleMarkdown = useStreamedMarkdown(markdown, animate)

    if (visibleMarkdown.includes('### 1. Mặt hàng') && visibleMarkdown.includes('### 2. Mã HS')) {
        return (
            <StructuredResultCard
                markdown={visibleMarkdown}
                links={links}
                markdownComponents={markdownComponents}
                renderChart={(option) => <EChart option={option} />}
            />
        )
    }

    return (
        <div className="flex flex-col gap-3">
            {splitCharts(visibleMarkdown).map((part, index) =>
                part.kind === 'chart'
                    ? <EChart key={index} option={part.text} />
                    : <Streamdown key={index} mode="static" linkSafety={{ enabled: false }} components={markdownComponents}>
                        {linkCodesInMarkdown(part.text, links)}
                    </Streamdown>,
            )}
        </div>
    )
}
