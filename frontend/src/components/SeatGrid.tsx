"use client";

import type { BlockSeats, SeatDisplayStatus } from "@/lib/api";

const COLORS: Record<SeatDisplayStatus, string> = {
  available: "#22c55e",
  mine: "#2563eb",
  held: "#f59e0b",
  sold: "#d1d5db",
};

export default function SeatGrid({
  blockSeats,
  mineSeatIds,
  pendingSeatIds,
  atLimit,
  disabled,
  onHold,
  onRelease,
}: {
  blockSeats: BlockSeats;
  mineSeatIds: Set<number>;
  pendingSeatIds: Set<number>;
  atLimit: boolean;
  disabled: boolean;
  onHold: (seatId: number, row: string, number: number) => void;
  onRelease: (seatId: number) => void;
}) {
  return (
    <div className="overflow-x-auto">
      <div className="space-y-1">
        {blockSeats.rows.map((row) => (
          <div key={row.row} className="flex items-center gap-1">
            <span className="w-6 shrink-0 text-right text-[10px] text-gray-500">{row.row}</span>
            {row.seats.map((s) => {
              const status: SeatDisplayStatus =
                (s.status === "held" || s.status === "available") && mineSeatIds.has(s.id)
                  ? "mine"
                  : s.status;
              const pending = pendingSeatIds.has(s.id);
              const clickable =
                !disabled &&
                !pending &&
                (status === "mine" || (status === "available" && !atLimit));
              return (
                <button
                  key={s.id}
                  type="button"
                  disabled={!clickable}
                  data-testid={`seat-${s.id}`}
                  data-status={status}
                  title={`${row.row}列 ${s.number}番 (${status})${status === "available" && atLimit ? "（上限）" : ""}`}
                  onClick={() =>
                    status === "mine" ? onRelease(s.id) : onHold(s.id, row.row, s.number)
                  }
                  className={`inline-block h-4 w-4 shrink-0 rounded-[2px] ${
                    clickable ? "cursor-pointer" : "cursor-not-allowed"
                  }`}
                  style={{ backgroundColor: COLORS[status], opacity: pending ? 0.5 : 1 }}
                />
              );
            })}
          </div>
        ))}
      </div>
      <p className="mt-2 flex flex-wrap gap-3 text-xs text-gray-500">
        {(
          [
            ["available", "空席"],
            ["mine", "自分の席"],
            ["held", "他の方が確保中"],
            ["sold", "販売済み"],
          ] as [SeatDisplayStatus, string][]
        ).map(([k, label]) => (
          <span key={k}>
            <span
              className="mr-1 inline-block h-3 w-3 rounded-[2px] align-middle"
              style={{ backgroundColor: COLORS[k] }}
            />
            {label}
          </span>
        ))}
      </p>
    </div>
  );
}
