"use client";

import { BlockInfo, SeatMap } from "@/lib/api";
import StadiumCanvas from "@/components/StadiumCanvas";
import { ALLOCATION_COLORS, ALLOCATION_LABELS, ALLOCATIONS, isSellable } from "@/lib/allocation";

function blockOpacity(b: BlockInfo): number {
  if (b.allocation === "buffer" || b.allocation === "closed") return 0.5;
  if (b.capacity === 0) return 0.3;
  if (b.available === null) return 0.65; // 残数を伏せるときは割当の色だけで塗る
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
        <StadiumCanvas viewbox={seatmap.viewbox}>
          {seatmap.blocks.map((b) => (
            <g
              key={b.id}
              data-testid={`block-${b.id}`}
              data-allocation={b.allocation}
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
        </StadiumCanvas>
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
          {seatmap.blocks.every((b) => b.available !== null) && (
            <span className="text-gray-500">※ 色が薄いほど残席わずか</span>
          )}
        </div>
      </div>

    </div>
  );
}
