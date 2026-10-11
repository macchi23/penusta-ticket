"use client";

import { useState } from "react";
import type { BlockInfo, MatchPrice, OrderItem, UnreservedQuantity } from "@/lib/api";

const yen = (n: number) => `¥${n.toLocaleString("ja-JP")}`;

export default function UnreservedPicker({
  block,
  prices,
  items,
  maxItems,
  disabled,
  reduceOnly,
  onApply,
}: {
  block: BlockInfo;
  prices: MatchPrice[];
  items: OrderItem[];
  maxItems: number;
  disabled: boolean;
  // 販売期間外 / 非対象のとき: 枚数を減らすことだけできる
  reduceOnly: boolean;
  onApply: (quantities: UnreservedQuantity[]) => void;
}) {
  const rows = prices.filter(
    (p) => p.seat_category === block.seat_category && p.ticket_type.code !== "season_pass",
  );
  const inBlock = items.filter((i) => i.block.id === block.id);
  const otherBlocks = items.length - inBlock.length;
  const current = (typeId: number) => inBlock.filter((i) => i.ticket_type.id === typeId).length;

  // props の明細が変わったら（楽観 → 確定を含む）選択値をカートに同期する
  const syncKey = `${block.id}:${rows.map((r) => `${r.ticket_type.id}=${current(r.ticket_type.id)}`).join(",")}`;
  const [prevKey, setPrevKey] = useState(syncKey);
  const [selected, setSelected] = useState<Record<number, number>>({});
  if (prevKey !== syncKey) {
    setPrevKey(syncKey);
    setSelected({});
  }
  const value = (typeId: number) => selected[typeId] ?? current(typeId);

  const changed = rows.some((r) => value(r.ticket_type.id) !== current(r.ticket_type.id));
  const total = rows.reduce((s, r) => s + value(r.ticket_type.id), 0);

  return (
    <div className="space-y-2 text-sm">
      <p>
        {block.stand} {block.code}（自由席）
      </p>
      <p>
        残席 {block.available} / {block.capacity}
      </p>
      <p>このブロックで確保中: {inBlock.length} 枚</p>
      <ul className="space-y-1">
        {rows.map((r) => {
          const id = r.ticket_type.id;
          const max = Math.max(
            0,
            Math.min(
              maxItems - otherBlocks - (total - value(id)),
              inBlock.length + block.available - (total - value(id)),
            ),
          );
          const top = reduceOnly ? current(id) : Math.max(max, value(id));
          return (
            <li key={id} className="flex items-center gap-2">
              <span className="min-w-0 flex-1">
                {r.ticket_type.name} {yen(r.price)}
              </span>
              <select
                aria-label={r.ticket_type.name}
                value={value(id)}
                disabled={disabled}
                onChange={(e) => setSelected({ ...selected, [id]: Number(e.target.value) })}
                className="rounded border border-gray-300 bg-transparent px-1 py-0.5 dark:border-gray-600"
              >
                {Array.from({ length: top + 1 }, (_, n) => (
                  <option key={n} value={n}>
                    {n}
                  </option>
                ))}
              </select>
            </li>
          );
        })}
      </ul>
      <button
        type="button"
        disabled={disabled || !changed}
        onClick={() =>
          onApply(rows.map((r) => ({ ticket_type_id: r.ticket_type.id, quantity: value(r.ticket_type.id) })))
        }
        className="rounded bg-blue-600 px-3 py-1.5 text-white disabled:cursor-not-allowed disabled:bg-gray-300"
      >
        カートに反映
      </button>
    </div>
  );
}
