"use client";

import Link from "next/link";
import type { CartResponse, MatchPrice } from "@/lib/api";
import HoldCountdown from "@/components/HoldCountdown";

const yen = (n: number) => `¥${n.toLocaleString("ja-JP")}`;

export default function CartSummary({
  matchId,
  cart,
  prices,
  onRelease,
  onChangeType,
}: {
  matchId: number;
  cart: CartResponse;
  prices: MatchPrice[];
  onRelease: (itemId: number) => void;
  onChangeType: (itemId: number, ticketTypeId: number, price: number) => void;
}) {
  const order = cart.cart;
  const items = order?.items ?? [];
  return (
    <section className="rounded-lg border border-gray-200 p-4 dark:border-gray-700">
      <div className="flex items-baseline justify-between">
        <h2 className="text-lg font-bold">カート</h2>
        <span className="text-sm text-gray-500">
          {items.length} / {cart.max_items} 枚
        </span>
      </div>
      {order?.expires_at && items.length > 0 && (
        <p className="mt-1 text-sm">
          <HoldCountdown expiresAt={order.expires_at} serverTime={cart.server_time} />
        </p>
      )}
      {items.length === 0 ? (
        <p className="mt-3 text-sm text-gray-500">席を選ぶとここに表示されます。</p>
      ) : (
        <ul className="mt-3 divide-y divide-gray-200 text-sm dark:divide-gray-700">
          {items.map((it) => {
            const options = prices.filter(
              (p) => p.seat_category === it.seat_category && p.ticket_type.code !== "season_pass",
            );
            const pending = it.id <= 0;
            return (
              <li key={it.id} className="flex flex-wrap items-center gap-2 py-2">
                <span className="min-w-0 flex-1">
                  {it.block.stand} {it.block.code}{" "}
                  {it.seat ? `${it.seat.row}列 ${it.seat.number}番` : "自由席"}
                </span>
                <select
                  value={it.ticket_type.id}
                  disabled={pending}
                  onChange={(e) => {
                    const p = options.find((o) => o.ticket_type.id === Number(e.target.value));
                    if (p) onChangeType(it.id, p.ticket_type.id, p.price);
                  }}
                  className="rounded border border-gray-300 bg-transparent px-1 py-0.5 dark:border-gray-600"
                >
                  {!options.some((o) => o.ticket_type.id === it.ticket_type.id) && (
                    <option value={it.ticket_type.id}>{it.ticket_type.name}</option>
                  )}
                  {options.map((o) => (
                    <option key={o.ticket_type.id} value={o.ticket_type.id}>
                      {o.ticket_type.name} {yen(o.price)}
                    </option>
                  ))}
                </select>
                <span className="w-20 text-right">{yen(it.price)}</span>
                {pending ? (
                  <span className="text-gray-500">確保中…</span>
                ) : (
                  <button
                    type="button"
                    onClick={() => onRelease(it.id)}
                    className="text-red-600 underline"
                  >
                    外す
                  </button>
                )}
              </li>
            );
          })}
        </ul>
      )}
      <div className="mt-3 flex items-center justify-between">
        <span className="font-bold">合計 {yen(items.reduce((s, i) => s + i.price, 0))}</span>
        {items.length > 0 && (
          <Link
            href={`/matches/${matchId}/checkout`}
            className="rounded bg-blue-600 px-3 py-1.5 text-sm text-white"
          >
            購入手続きへ
          </Link>
        )}
      </div>
    </section>
  );
}
