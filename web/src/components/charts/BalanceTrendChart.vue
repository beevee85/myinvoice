<script setup lang="ts">
import { onMounted, onBeforeUnmount, ref, watch } from 'vue'
import {
  Chart,
  LineController,
  LineElement,
  PointElement,
  CategoryScale,
  LinearScale,
  Tooltip,
  Filler,
} from 'chart.js'
import { useChartColors } from '@/composables/useTheme'
import { formatMoney, formatMonth } from '@/composables/useFormat'

Chart.register(LineController, LineElement, PointElement, CategoryScale, LinearScale, Tooltip, Filler)

/**
 * Liniový graf vývoje zůstatku v čase. Sdílený pro malé grafy jednotlivých účtů
 * (nativní měna) i pro celkový CZK graf. Osa X = 'YYYY-MM' popisky, hodnoty mohou
 * obsahovat null (mezera před založením účtu / bez kurzu) — kreslí se s `spanGaps`.
 */
const props = defineProps<{
  /** Popisky 'YYYY-MM'. */
  labels: string[]
  /** Hodnoty zůstatku ve stejné délce jako labels; null = bez bodu. */
  values: Array<number | null>
  /** Měna pro formátování tooltipu/os (např. 'CZK', 'EUR'). */
  currency: string
  /** Barva křivky; default primární. */
  color?: string
  /** Vyplnit plochu pod křivkou. */
  fill?: boolean
}>()

const canvas = ref<HTMLCanvasElement | null>(null)
let chart: Chart | null = null
const colors = useChartColors()

function formatTick(n: number): string {
  const abs = Math.abs(n)
  if (abs >= 1_000_000) return (n / 1_000_000).toFixed(1) + 'M'
  if (abs >= 1_000) return (n / 1_000).toFixed(0) + 'k'
  return String(Math.round(n))
}

function build() {
  if (!canvas.value) return
  if (chart) chart.destroy()

  const line = props.color ?? colors.value.primary
  chart = new Chart(canvas.value, {
    type: 'line',
    data: {
      labels: props.labels,
      datasets: [
        {
          data: props.values as number[],
          borderColor: line,
          backgroundColor: props.fill ? line + '26' : 'transparent', // 26 = ~15% alpha
          borderWidth: 2,
          tension: 0.3,
          pointRadius: props.labels.length > 24 ? 0 : 2,
          pointHoverRadius: 4,
          pointBackgroundColor: line,
          fill: !!props.fill,
          spanGaps: true,
        },
      ],
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      interaction: { mode: 'index', intersect: false },
      plugins: {
        legend: { display: false },
        tooltip: {
          backgroundColor: colors.value.tooltipBg,
          callbacks: {
            title: (items) => (items.length ? formatMonth(String(items[0].label)) : ''),
            label: (ctx) => formatMoney(Number(ctx.parsed.y ?? 0), props.currency),
          },
        },
      },
      scales: {
        y: {
          ticks: { color: colors.value.tick, font: { size: 11 }, callback: (v) => formatTick(Number(v)) },
          grid: { color: colors.value.grid },
        },
        x: {
          ticks: {
            color: colors.value.tick,
            font: { size: 11 },
            maxRotation: 0,
            autoSkip: true,
            callback(_v, index) {
              // Zkrácený popisek 'MM/RR' ať se osa nepřeplní.
              const raw = String(this.getLabelForValue(index as number))
              const [y, m] = raw.split('-')
              return m && y ? `${m}/${y.slice(2)}` : raw
            },
          },
          grid: { display: false },
        },
      },
    },
  })
}

onMounted(build)
onBeforeUnmount(() => chart?.destroy())
watch(() => [props.labels, props.values, props.currency, props.color, props.fill], build, { deep: true })
watch(colors, build)
</script>

<template>
  <div class="relative h-full w-full"><canvas ref="canvas"></canvas></div>
</template>
