"use client";

import { useState } from "react";
import {
  Allocation,
  BlockInfo,
  BlockSeats,
  SeatMap,
  fetchJson,
} from "@/lib/api";

const ALLOCATION_COLORS: Record<Allocation, string> = {
  home: "#2563eb",
  away: "#dc2626",
  buffer: "#9ca3af",
  closed: "#374151",
};

const ALLOCATION_LABELS: Record<Allocation, string> = {
  home: "ホーム",
  away: "アウェー",
  buffer: "緩衝帯",
  closed: "閉鎖",
};

function blockOpacity(b: BlockInfo): number {
  if (b.allocation === "buffer" || b.allocation === "closed") return 0.5;
  if (b.capacity === 0) return 0.3;
  const ratio = b.available / b.capacity;
  return 0.35 + 0.65 * ratio; // 完売に近いほど薄く
}

export default function SeatMapView({
  matchId,
  seatmap,
}: {
  matchId: number;
  seatmap: SeatMap;
}) {
  const [selected, setSelected] = useState<BlockInfo | null>(null);
  const [seats, setSeats] = useState<BlockSeats | null>(null);
  const [loading, setLoading] = useState(false);

  async function selectBlock(b: BlockInfo) {
    setSelected(b);
    setSeats(null);
    if (b.seating_type === "reserved" && b.allocation !== "closed") {
      setLoading(true);
      try {
        const data = await fetchJson<BlockSeats>(
          `/api/matches/${matchId}/blocks/${b.id}/seats`,
        );
        setSeats(data);
      } finally {
        setLoading(false);
      }
    }
  }

  return (
    <div className="grid gap-6 lg:grid-cols-[1fr_320px]">
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
              onClick={() => selectBlock(b)}
              className="cursor-pointer"
            >
              <rect
                x={b.geometry.x}
                y={b.geometry.y}
                width={b.geometry.w}
                height={b.geometry.h}
                rx={4}
                fill={ALLOCATION_COLORS[b.allocation]}
                fillOpacity={blockOpacity(b)}
                stroke={selected?.id === b.id ? "#111827" : "#ffffff"}
                strokeWidth={selected?.id === b.id ? 3 : 1}
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
          {(Object.keys(ALLOCATION_LABELS) as Allocation[]).map((a) => (
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

      <aside className="rounded-lg border border-gray-200 p-4 dark:border-gray-700">
        {!selected ? (
          <p className="text-sm text-gray-500">
            ブロックをクリックすると詳細を表示します。
          </p>
        ) : (
          <div>
            <h2 className="text-lg font-bold">
              {selected.stand} {selected.code}
            </h2>
            <dl className="mt-2 space-y-1 text-sm">
              <div className="flex justify-between">
                <dt className="text-gray-500">割当</dt>
                <dd>{ALLOCATION_LABELS[selected.allocation]}</dd>
              </div>
              <div className="flex justify-between">
                <dt className="text-gray-500">席種</dt>
                <dd>
                  {selected.seat_category}（
                  {selected.seating_type === "reserved" ? "指定席" : "自由席"}）
                </dd>
              </div>
              <div className="flex justify-between">
                <dt className="text-gray-500">残席</dt>
                <dd>
                  {selected.allocation === "closed"
                    ? "-"
                    : `${selected.available} / ${selected.capacity}`}
                </dd>
              </div>
            </dl>

            {loading && (
              <p className="mt-4 text-sm text-gray-500">座席を読み込み中…</p>
            )}
            {seats && (
              <div className="mt-4 overflow-x-auto">
                <div className="space-y-1">
                  {seats.rows.map((row) => (
                    <div key={row.row} className="flex items-center gap-1">
                      <span className="w-6 shrink-0 text-right text-[10px] text-gray-500">
                        {row.row}
                      </span>
                      {row.seats.map((s) => (
                        <span
                          key={s.id}
                          title={`${row.row}列 ${s.number}番 (${s.status})`}
                          className="inline-block h-3 w-3 shrink-0 rounded-[2px]"
                          style={{
                            backgroundColor:
                              s.status === "available" ? "#22c55e" : "#d1d5db",
                          }}
                        />
                      ))}
                    </div>
                  ))}
                </div>
                <p className="mt-2 text-xs text-gray-500">
                  <span className="mr-3">
                    <span className="mr-1 inline-block h-3 w-3 rounded-[2px] bg-green-500 align-middle" />
                    空席
                  </span>
                  <span>
                    <span className="mr-1 inline-block h-3 w-3 rounded-[2px] bg-gray-300 align-middle" />
                    販売済み
                  </span>
                </p>
              </div>
            )}
            {selected.seating_type === "unreserved" &&
              selected.allocation !== "closed" && (
                <p className="mt-4 text-sm text-gray-500">
                  自由席のため座席指定はありません。
                </p>
              )}
          </div>
        )}
      </aside>
    </div>
  );
}
