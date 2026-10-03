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
} from "@/lib/api";
import {
  changeTicketType,
  holdSeat,
  holdUnreserved,
  releaseItem,
} from "@/lib/actions/cart";
import SeatMapView from "@/components/SeatMapView";
import SeatGrid from "@/components/SeatGrid";
import UnreservedPicker from "@/components/UnreservedPicker";
import CartSummary from "@/components/CartSummary";

type OptimisticAction =
  | { type: "add"; item: OrderItem }
  | { type: "remove"; itemId: number }
  | { type: "retype"; itemId: number; ticketType: OrderItem["ticket_type"]; price: number };

function reducer(state: CartResponse | null, action: OptimisticAction): CartResponse | null {
  if (!state) return state;
  const base = state.cart ?? {
    id: 0,
    match_id: 0,
    status: "pending" as const,
    expires_at: null,
    ordered_at: null,
    total: 0,
    items: [],
  };
  let items = base.items;
  if (action.type === "add") items = [...items, action.item];
  else if (action.type === "remove") items = items.filter((i) => i.id !== action.itemId);
  else
    items = items.map((i) =>
      i.id === action.itemId ? { ...i, ticket_type: action.ticketType, price: action.price } : i,
    );
  return {
    ...state,
    cart: { ...base, items, total: items.reduce((s, i) => s + i.price, 0) },
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
    case "not_on_sale":
      return "この席は現在販売していません";
    case "user_required":
      return "ユーザーを選択してください";
    default:
      return "エラーが発生しました";
  }
}

let tempId = 0;

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
                {selectedBlock.seating_type === "unreserved" ? (
                  <UnreservedPicker
                    block={selectedBlock}
                    mineCount={items.filter((i) => i.block.id === selectedBlock.id).length}
                    atLimit={atLimit}
                    disabled={locked}
                    onAdd={() =>
                      run({ type: "add", item: tempItem(selectedBlock, null) }, () =>
                        holdUnreserved(match.id, selectedBlock.id),
                      )
                    }
                  />
                ) : seatsError ? (
                  <p className="text-sm text-red-700">席一覧を取得できません</p>
                ) : blockSeats ? (
                  <SeatGrid
                    blockSeats={blockSeats}
                    mineSeatIds={mineSeatIds}
                    pendingSeatIds={pendingSeatIds}
                    disabled={locked}
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
