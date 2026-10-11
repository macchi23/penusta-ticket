"use client";

import { useOptimistic, useState, startTransition } from "react";
import { useRouter } from "next/navigation";
import type {
  ActionResult,
  ApiError,
  BlockInfo,
  BlockSeats,
  CartResponse,
  Match,
  MatchPrice,
  OrderItem,
  SeatMap,
  UnreservedQuantity,
} from "@/lib/api";
import {
  changeTicketType,
  holdSeat,
  releaseItem,
  setUnreservedQuantities,
} from "@/lib/actions/cart";
import SeatMapView from "@/components/SeatMapView";
import SeatGrid from "@/components/SeatGrid";
import UnreservedPicker from "@/components/UnreservedPicker";
import CartSummary from "@/components/CartSummary";
import SaleBanner, { seatsHiddenReason } from "@/components/SaleBanner";

let tempId = 0;

type OptimisticAction =
  | { type: "add"; item: OrderItem }
  | { type: "setBlock"; block: BlockInfo; quantities: UnreservedQuantity[]; prices: MatchPrice[] }
  | { type: "remove"; itemId: number }
  | { type: "retype"; itemId: number; ticketType: OrderItem["ticket_type"]; price: number };

function reducer(state: CartResponse | null, action: OptimisticAction): CartResponse | null {
  if (!state) return state;
  const base = state.cart ?? {
    id: 0,
    match_id: 0,
    status: "pending" as const,
    kind: "purchase" as const,
    expires_at: null,
    ordered_at: null,
    total: 0,
    items: [],
  };
  let items = base.items;
  let expiresAt = base.expires_at;
  if (action.type === "add") {
    // 有効な明細が 0 件のカートに足すと backend が expires_at を付け直す
    if (items.length === 0) expiresAt = null;
    items = [...items, action.item];
  } else if (action.type === "setBlock") {
    const { block } = action;
    let next = items;
    let added = 0;
    for (const q of action.quantities) {
      const own = next.filter((i) => i.block.id === block.id && !i.seat && i.ticket_type.id === q.ticket_type_id);
      if (q.quantity < own.length) {
        const drop = new Set(own.slice(q.quantity).map((i) => i.id));
        next = next.filter((i) => !drop.has(i.id));
      } else if (q.quantity > own.length) {
        const p = action.prices.find(
          (x) => x.seat_category === block.seat_category && x.ticket_type.id === q.ticket_type_id,
        );
        const extra = Array.from({ length: q.quantity - own.length }, (): OrderItem => ({
          id: --tempId,
          ticket_id: 0,
          block: { id: block.id, code: block.code, stand: block.stand },
          seating_type: block.seating_type,
          seat: null,
          seat_category: block.seat_category,
          ticket_type: p?.ticket_type ?? { id: q.ticket_type_id, code: "", name: "" },
          price: p?.price ?? 0,
        }));
        added += extra.length;
        next = [...next, ...extra];
      }
    }
    if (items.length === 0 && added > 0) expiresAt = null;
    items = next;
  }
  else if (action.type === "remove") items = items.filter((i) => i.id !== action.itemId);
  else
    items = items.map((i) =>
      i.id === action.itemId ? { ...i, ticket_type: action.ticketType, price: action.price } : i,
    );
  return {
    ...state,
    cart: { ...base, expires_at: expiresAt, items, total: items.reduce((s, i) => s + i.price, 0) },
  };
}

function errorMessage(e: ApiError, maxItems: number): string {
  switch (e.code) {
    case "seat_unavailable":
      return "他の方が先に確保しました";
    case "sold_out":
      return "このブロックは残席がありません";
    case "cart_limit_reached":
      return `1 回の注文は ${maxItems} 枚までです`;
    case "cart_expired":
      return "仮押さえの期限が切れました。選び直してください";
    case "price_not_found":
      return "この券種は販売していません";
    case "not_on_sale":
      return "この席は現在販売していません";
    case "not_eligible":
      return "この販売期間は対象の会員のみ購入できます";
    case "user_required":
      return "ユーザーを選択してください";
    default:
      return "エラーが発生しました";
  }
}

export default function MatchPurchase({
  match,
  seatmap,
  cart,
  prices,
  selectedBlock,
  blockSeats,
  cartError,
  pricesError,
  seatsError,
  seatsHidden,
}: {
  match: Match;
  seatmap: SeatMap;
  cart: CartResponse | null;
  prices: MatchPrice[];
  selectedBlock: BlockInfo | null;
  blockSeats: BlockSeats | null;
  cartError: boolean;
  pricesError: boolean;
  seatsError: boolean;
  seatsHidden: boolean;
}) {
  const router = useRouter();
  const [optimistic, applyOptimistic] = useOptimistic(cart, reducer);
  const [error, setError] = useState<string | null>(null);

  const maxItems = optimistic?.max_items ?? cart?.max_items ?? 0;
  const items = optimistic?.cart?.items ?? [];
  const mineSeatIds = new Set(
    items.flatMap((i) => (i.seat ? [i.seat.id] : [])),
  );
  const pendingSeatIds = new Set(
    items.flatMap((i) => (i.seat && i.id < 0 ? [i.seat.id] : [])),
  );
  const atLimit = optimistic !== null && items.length >= maxItems;
  const noUser = cart === null;
  const locked = noUser || cartError;
  // 販売中かつ対象のときだけ席を足せる。解放・枚数を減らす操作は常に可能
  const sale = optimistic?.sale ?? null;
  const addBlocked = sale !== null && (sale.current_phase_id === null || !sale.eligible);

  function run(
    optimisticAction: OptimisticAction | null,
    call: () => Promise<ActionResult<CartResponse>>,
  ) {
    setError(null);
    startTransition(async () => {
      if (optimisticAction) applyOptimistic(optimisticAction);
      const result = await call();
      if (!result.ok) setError(errorMessage(result.error, maxItems));
    });
  }

  function defaultPrice(category: string) {
    return prices.find(
      (p) => p.seat_category === category && p.ticket_type.code !== "season_pass",
    );
  }

  function tempItem(block: BlockInfo, seat: OrderItem["seat"]): OrderItem {
    const p = defaultPrice(block.seat_category);
    return {
      id: --tempId,
      ticket_id: 0,
      block: { id: block.id, code: block.code, stand: block.stand },
      seating_type: block.seating_type,
      seat,
      seat_category: block.seat_category,
      ticket_type: p?.ticket_type ?? { id: 0, code: "", name: "" },
      price: p?.price ?? 0,
    };
  }

  function release(itemId: number) {
    if (itemId <= 0) return;
    run({ type: "remove", itemId }, () => releaseItem(match.id, itemId));
  }

  function selectBlock(b: BlockInfo) {
    setError(null);
    router.replace(`?block=${b.id}`, { scroll: false });
  }

  return (
    <div className="space-y-4">
      {cartError && (
        <p className="rounded border border-red-300 bg-red-50 p-3 text-sm text-red-800">
          カートを取得できません。しばらくしてから再読み込みしてください
        </p>
      )}
      {pricesError && (
        <p className="rounded border border-red-300 bg-red-50 p-3 text-sm text-red-800">
          価格を取得できません
        </p>
      )}
      {optimistic && !cartError && (
        <SaleBanner phases={match.sale_phases} sale={optimistic.sale} serverTime={optimistic.server_time} />
      )}
      {noUser && !cartError && (
        <p className="rounded border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900">
          上のバーでユーザーを選んでください
        </p>
      )}
      {error && (
        <p role="alert" className="rounded border border-red-300 bg-red-50 p-3 text-sm text-red-800">
          {error}
        </p>
      )}
      <div className="grid gap-6 lg:grid-cols-[1fr_360px]">
        <SeatMapView
          seatmap={seatmap}
          selectedBlockId={selectedBlock?.id ?? null}
          onSelectBlock={selectBlock}
        />
        <div className="space-y-4">
          {optimistic && !cartError ? (
            <CartSummary
              matchId={match.id}
              cart={optimistic}
              prices={prices}
              onRelease={release}
              addBlocked={addBlocked}
              onChangeType={(itemId, ticketTypeId, price) => {
                if (itemId <= 0) return;
                const p = prices.find((x) => x.ticket_type.id === ticketTypeId);
                run(
                  p ? { type: "retype", itemId, ticketType: p.ticket_type, price } : null,
                  () => changeTicketType(match.id, itemId, ticketTypeId),
                );
              }}
            />
          ) : (
            <section className="rounded-lg border border-gray-200 p-4 dark:border-gray-700">
              <h2 className="text-lg font-bold">カート</h2>
              <p className="mt-2 text-sm text-gray-500">{cartError ? "取得できません。" : "ユーザー未選択です。"}</p>
            </section>
          )}
          <section className="rounded-lg border border-gray-200 p-4 dark:border-gray-700">
            {!selectedBlock ? (
              <p className="text-sm text-gray-500">ブロックをクリックすると席を選べます。</p>
            ) : (
              <div>
                <h2 className="mb-2 text-lg font-bold">
                  {selectedBlock.stand} {selectedBlock.code}
                </h2>
                {seatsHidden && (
                  <p data-testid="seats-hidden" className="mb-2 text-sm text-gray-600 dark:text-gray-300">
                    {seatsHiddenReason(match.sale_phases, optimistic?.sale ?? null, optimistic?.server_time ?? null, { noUser, cartError })}
                  </p>
                )}
                {selectedBlock.seating_type === "unreserved" ? (
                  <UnreservedPicker
                    block={selectedBlock}
                    prices={prices}
                    items={items}
                    maxItems={maxItems}
                    disabled={locked}
                    reduceOnly={addBlocked || seatsHidden}
                    onApply={(quantities) =>
                      run({ type: "setBlock", block: selectedBlock, quantities, prices }, () =>
                        setUnreservedQuantities(match.id, selectedBlock.id, quantities),
                      )
                    }
                  />
                ) : seatsHidden ? null : seatsError ? (
                  <p className="text-sm text-red-700">席一覧を取得できません</p>
                ) : blockSeats ? (
                  <SeatGrid
                    blockSeats={blockSeats}
                    mineSeatIds={mineSeatIds}
                    pendingSeatIds={pendingSeatIds}
                    atLimit={atLimit}
                    disabled={locked}
                    holdDisabled={addBlocked}
                    onHold={(seatId, row, number) =>
                      run(
                        {
                          type: "add",
                          item: tempItem(selectedBlock, { id: seatId, row, number }),
                        },
                        () => holdSeat(match.id, seatId),
                      )
                    }
                    onRelease={(seatId) => {
                      const it = items.find((i) => i.seat?.id === seatId);
                      if (it) release(it.id);
                    }}
                  />
                ) : (
                  <p className="text-sm text-gray-500">このブロックは選択できません。</p>
                )}
              </div>
            )}
          </section>
        </div>
      </div>
    </div>
  );
}
