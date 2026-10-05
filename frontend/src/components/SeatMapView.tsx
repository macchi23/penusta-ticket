"use client";

import { BlockInfo, SeatMap } from "@/lib/api";
import { ALLOCATION_COLORS, ALLOCATION_LABELS, ALLOCATIONS, isSellable } from "@/lib/allocation";

function blockOpacity(b: BlockInfo): number {
  if (b.allocation === "buffer" || b.allocation === "closed") return 0.5;
  if (b.capacity === 0) return 0.3;
  const ratio = b.available / b.capacity;
  return 0.35 + 0.65 * ratio; // 完売に近いほど薄く
}

export default function SeatMapView({
  seatmap,
  selectedBlockId,
  onSelectBlock,
}: {
  seatmap: SeatMap;
  selectedBlockId: number | null;
  onSelectBlock: (block: BlockInfo) => void;
}) {
  return (
    <div>
      <div>
        <svg
          viewBox={`0 0 ${seatmap.viewbox.width} ${seatmap.viewbox.height}`}
          className="w-full rounded-lg border border-gray-200 bg-emerald-50 dark:border-gray-700 dark:bg-gray-900"
        >
          {/* ピッチ */}
          <rect
            x={seatmap.viewbox.width * 0.25}
            y={seatmap.viewbox.height * 0.3}
            width={seatmap.viewbox.width * 0.5}
            height={seatmap.viewbox.height * 0.4}
            rx={8}
            fill="#16a34a"
          />
          <text
            x={seatmap.viewbox.width / 2}
            y={seatmap.viewbox.height / 2}
            textAnchor="middle"
            dominantBaseline="middle"
            fill="#ffffff"
            fontSize={20}
          >
            PITCH
          </text>
          {seatmap.blocks.map((b) => (
            <g
              key={b.id}
              data-testid={`block-${b.id}`}
              onClick={() => isSellable(b.allocation) && onSelectBlock(b)}
              className={isSellable(b.allocation) ? "cursor-pointer" : "cursor-not-allowed"}
            >
              <rect
                x={b.geometry.x}
                y={b.geometry.y}
                width={b.geometry.w}
                height={b.geometry.h}
                rx={4}
                fill={ALLOCATION_COLORS[b.allocation]}
                fillOpacity={blockOpacity(b)}
                stroke={selectedBlockId === b.id ? "#111827" : "#ffffff"}
                strokeWidth={selectedBlockId === b.id ? 3 : 1}
              />
              <text
                x={b.geometry.x + b.geometry.w / 2}
                y={b.geometry.y + b.geometry.h / 2}
                textAnchor="middle"
                dominantBaseline="middle"
                fontSize={11}
                fill="#ffffff"
                pointerEvents="none"
              >
                {b.code}
              </text>
            </g>
          ))}
        </svg>
        <div className="mt-3 flex flex-wrap gap-4 text-sm">
          {ALLOCATIONS.map((a) => (
            <span key={a} className="flex items-center gap-1.5">
              <span
                className="inline-block h-3 w-3 rounded-sm"
                style={{ backgroundColor: ALLOCATION_COLORS[a] }}
              />
              {ALLOCATION_LABELS[a]}
            </span>
          ))}
          <span className="text-gray-500">※ 色が薄いほど残席わずか</span>
        </div>
      </div>

    </div>
  );
}
