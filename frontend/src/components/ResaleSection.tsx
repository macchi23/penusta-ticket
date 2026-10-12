"use client";

import { useState, useTransition } from "react";
import type { ResaleBoard, ResaleOffer } from "@/lib/api";
import { purchaseListing } from "@/lib/actions/resale";
import { formatJst, yen } from "@/lib/format";
import { resaleErrorMessage } from "@/lib/resale-messages";
import ResaleViewTracker from "@/components/ResaleViewTracker";

// board が null のときは取得失敗
export default function ResaleSection({ matchId, board }: { matchId: number; board: ResaleBoard | null }) {
  const [open, setOpen] = useState<Set<number>>(new Set());
  const [pending, startTransition] = useTransition();
  const [error, setError] = useState<string | null>(null);

  const toggle = (id: number) =>
    setOpen((prev) => {
      const next = new Set(prev);
      if (!next.delete(id)) next.add(id);
      return next;
    });

  const buy = (offer: ResaleOffer, label: string) => {
    if (!window.confirm(`${label} ${yen(offer.price)} + 手数料 ${yen(offer.buyer_fee)} = ${yen(offer.total)} で購入しますか？`)) return;
    setError(null);
    startTransition(async () => {
      const res = await purchaseListing(offer.id);
      // 成功時は redirect されるのでここには来ない
      if (res && !res.ok) setError(resaleErrorMessage(res.error, "purchase"));
    });
  };

  let body: React.ReactNode;
  if (board === null) {
    body = <p className="text-sm text-gray-500">リセール情報を取得できませんでした。</p>;
  } else if (board.status === "login_required") {
    body = <p className="text-sm text-gray-500">ログインするとリセールのチケットを見られます</p>;
  } else if (board.status === "not_started") {
    body = (
      <p className="text-sm text-gray-500">
        {board.opens_at ? `リセールは ${formatJst(board.opens_at)} から` : "リセールは一般販売の開始後に始まります"}
      </p>
    );
  } else if (board.status === "closed") {
    body = <p className="text-sm text-gray-500">リセールの受付は終了しました</p>;
  } else if (board.blocks.length === 0) {
    body = <p className="text-sm text-gray-500">現在出品はありません</p>;
  } else {
    body = (
      <ul className="divide-y divide-gray-200 dark:divide-gray-700">
        {board.blocks.map((b) => {
          const expanded = open.has(b.block.id);
          const label = `${b.block.stand} ${b.block.code}`;
          return (
            <li key={b.block.id} data-testid={`resale-block-${b.block.code}`} className="py-2 text-sm">
              <button
                type="button"
                onClick={() => toggle(b.block.id)}
                aria-expanded={expanded}
                className="flex w-full items-center gap-3 text-left"
              >
                <span className="flex-1">
                  {label}（{b.seating_type === "unreserved" ? "自由席" : "指定席"}）
                </span>
                <span>{b.count} 枚</span>
                <span>最安 {yen(b.min_total)}</span>
                <span aria-hidden>{expanded ? "▲" : "▼"}</span>
              </button>
              {expanded && (
                <ul className="mt-2 space-y-1 pl-4">
                  {b.listings.map((o) => (
                    <li key={o.id} className="flex flex-wrap items-center gap-3">
                      <span className="min-w-0 flex-1">
                        {o.seat ? `${o.seat.row}列 ${o.seat.number}番` : "自由席"} ・ {o.ticket_type.name}
                      </span>
                      <span>
                        {yen(o.price)} + 手数料 {yen(o.buyer_fee)} = <strong>{yen(o.total)}</strong>
                      </span>
                      <button
                        type="button"
                        onClick={() => buy(o, label)}
                        disabled={pending}
                        className="rounded bg-blue-600 px-3 py-1 text-white disabled:opacity-50"
                      >
                        購入
                      </button>
                    </li>
                  ))}
                </ul>
              )}
            </li>
          );
        })}
      </ul>
    );
  }

  return (
    <section data-testid="resale-section" className="mt-8 rounded-lg border border-gray-200 p-4 dark:border-gray-700">
      <h2 className="mb-2 text-lg font-semibold">リセール</h2>
      {board?.status === "open" && board.closes_at && (
        <p className="mb-2 text-xs text-gray-500">受付締切 {formatJst(board.closes_at)}</p>
      )}
      {error && (
        <p role="alert" className="mb-2 text-sm text-red-600">
          {error}
        </p>
      )}
      {body}
      {board?.status === "open" && <ResaleViewTracker matchId={matchId} />}
    </section>
  );
}
