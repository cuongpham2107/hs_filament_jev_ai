import { useEffect, useRef } from 'react'
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

    return <div ref={ref} className="h-80 w-full max-w-xl" role="img" aria-label="Biểu đồ so sánh xác suất các mã" />
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

/** Thẻ kết quả / thẻ cần bổ sung (markdown do workflow dựng), biểu đồ vẽ tại chỗ, mã 8 số thành link. */
export function Answer({ markdown, links }: { markdown: string; links: CodeLinks }) {
    const structured = (
        <StructuredResultCard
            markdown={markdown}
            links={links}
            markdownComponents={markdownComponents}
            renderChart={(option) => <EChart option={option} />}
        />
    )
    if (markdown.includes('### 1. Mặt hàng') && markdown.includes('### 2. Mã HS')) {
        return structured
    }

    return (
        <div className="flex flex-col gap-3">
            {splitCharts(markdown).map((part, index) =>
                part.kind === 'chart'
                    ? <EChart key={index} option={part.text} />
                    : <Streamdown key={index} mode="static" linkSafety={{ enabled: false }} components={markdownComponents}>
                        {linkCodesInMarkdown(part.text, links)}
                    </Streamdown>,
            )}
        </div>
    )
}
