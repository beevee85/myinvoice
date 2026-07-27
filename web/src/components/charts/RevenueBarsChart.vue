<script setup lang="ts">
import { onMounted, onBeforeUnmount, ref, watch } from 'vue'
import {
  Chart, BarController, BarElement, CategoryScale, LinearScale, Tooltip,
} from 'chart.js'
import { useChartColors } from '@/composables/useTheme'

Chart.register(BarController, BarElement, CategoryScale, LinearScale, Tooltip)

/**
 * Plnohodnotný graf obratu pro Přehled: osy, gridlines, tooltip, sloupce
 * zaoblené nahoře (radius 6) s vertikálním gradientem primary barvy.
 * Data dodává rodič (agregace Měsíce/Kvartály/Roky se dělá client-side
 * z 12M datasetu — API se nemění).
 */
const props = defineProps<{
  labels: string[]
  values: number[]
  /** Formátovač hodnoty v tooltipu (např. peníze) */
  format?: (v: number) => string
  height?: number
}>()

const canvas = ref<HTMLCanvasElement | null>(null)
let chart: Chart | null = null
const colors = useChartColors()

/** Kompaktní popisky osy Y: 1 200 000 → 1,2 M; 45 000 → 45 k. */
function compact(v: number): string {
  const abs = Math.abs(v)
  if (abs >= 1_000_000) return (Math.round(v / 100_000) / 10).toLocaleString('cs-CZ') + ' M'
  if (abs >= 1_000) return Math.round(v / 1_000).toLocaleString('cs-CZ') + ' k'
  return String(v)
}

function build() {
  if (!canvas.value) return
  if (chart) chart.destroy()
  const formatter = props.format ?? ((v: number) => String(v))

  chart = new Chart(canvas.value, {
    type: 'bar',
    data: {
      labels: props.labels,
      datasets: [{
        data: props.values,
        // Gradient se počítá až po layoutu (chartArea) — do té doby plná barva.
        backgroundColor: (ctx) => {
          const { ctx: c, chartArea } = ctx.chart
          if (!chartArea) return colors.value.primary
          const g = c.createLinearGradient(0, chartArea.top, 0, chartArea.bottom)
          g.addColorStop(0, colors.value.primary)
          g.addColorStop(1, colors.value.primary + '26')
          return g
        },
        borderRadius: { topLeft: 6, topRight: 6, bottomLeft: 0, bottomRight: 0 },
        borderSkipped: false,
        barPercentage: 0.7,
        categoryPercentage: 0.85,
      }],
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: { display: false },
        tooltip: {
          backgroundColor: colors.value.tooltipBg,
          displayColors: false,
          callbacks: { label: (ctx) => ` ${ctx.label}: ${formatter(Number(ctx.parsed.y || 0))}` },
        },
      },
      scales: {
        x: {
          grid: { display: false },
          ticks: { color: colors.value.tick, font: { size: 11 } },
        },
        y: {
          beginAtZero: true,
          grid: { color: colors.value.grid },
          border: { display: false },
          ticks: {
            color: colors.value.tick,
            font: { size: 11 },
            callback: (v) => compact(Number(v)),
            maxTicksLimit: 6,
          },
        },
      },
    },
  })
}

onMounted(build)
onBeforeUnmount(() => chart?.destroy())
watch(() => [props.labels, props.values], build, { deep: true })
watch(colors, build)
</script>

<template>
  <div class="relative" :style="{ height: (height ?? 240) + 'px' }">
    <canvas ref="canvas"></canvas>
  </div>
</template>
