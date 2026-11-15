import { useEffect, useRef } from 'react'
import { Streamdown } from 'streamdown'
import * as echarts from 'echarts/core'
import { PieChart } from 'echarts/charts'
import { LegendComponent, TooltipComponent } from 'echarts/components'
import { CanvasRenderer } from 'echarts/renderers'

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

/** Thẻ kết quả / thẻ cần bổ sung (markdown do workflow dựng), biểu đồ vẽ tại chỗ. */
export function Answer({ markdown }: { markdown: string }) {
    return (
        <div className="flex flex-col gap-3">
            {splitCharts(markdown).map((part, index) =>
                part.kind === 'chart'
                    ? <EChart key={index} option={part.text} />
                    : <Streamdown key={index} mode="static">{part.text}</Streamdown>,
            )}
        </div>
    )
}
