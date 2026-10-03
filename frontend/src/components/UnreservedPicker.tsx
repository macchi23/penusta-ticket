"use client";

import type { BlockInfo } from "@/lib/api";

export default function UnreservedPicker({
  block,
  mineCount,
  atLimit,
  disabled,
  onAdd,
}: {
  block: BlockInfo;
  mineCount: number;
  atLimit: boolean;
  disabled: boolean;
  onAdd: () => void;
}) {
  return (
    <div className="space-y-2 text-sm">
      <p>
        {block.stand} {block.code}（自由席）
      </p>
      <p>
        残席 {block.available} / {block.capacity}
      </p>
      <p>このブロックで確保中: {mineCount} 枚</p>
      <button
        type="button"
        disabled={disabled || atLimit || block.available <= 0}
        onClick={onAdd}
        className="rounded bg-blue-600 px-3 py-1.5 text-white disabled:cursor-not-allowed disabled:bg-gray-300"
      >
        1 枚追加
      </button>
    </div>
  );
}
