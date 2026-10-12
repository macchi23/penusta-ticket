"use client";

import { useActionState, useState } from "react";
import type { ActionResult, ListingTerms, MyListing } from "@/lib/api";
import { listTicket } from "@/lib/actions/resale";
import { sellerFee, yen } from "@/lib/format";
import { resaleErrorMessage } from "@/lib/resale-messages";

type State = ActionResult<{ listing: MyListing }> | null;

export default function ListingForm({ ticketId, terms }: { ticketId: number; terms: ListingTerms }) {
  const [input, setInput] = useState(String(terms.face_price));
  const [state, action, pending] = useActionState<State, FormData>(
    async (_prev, fd) => listTicket(ticketId, Number(fd.get("price"))),
    null,
  );

  const price = Number(input);
  const valid =
    input.trim() !== "" && Number.isInteger(price) && price >= terms.min_price && price <= terms.face_price;
  const fee = valid ? sellerFee(price, terms.seller_fee_rate) : 0;

  return (
    <form action={action} className="mt-2 text-sm">
      <label className="block">
        出品価格（{yen(terms.min_price)}〜{yen(terms.face_price)}、整数）
        <input
          name="price"
          type="number"
          inputMode="numeric"
          step={1}
          min={terms.min_price}
          max={terms.face_price}
          value={input}
          onChange={(e) => setInput(e.target.value)}
          className="ml-2 w-32 rounded border border-gray-300 px-2 py-1 text-gray-900"
        />
      </label>
      {valid ? (
        <dl data-testid="listing-preview" className="mt-3 space-y-1">
          <div className="flex gap-2">
            <dt className="w-48">出品者手数料（{Math.round(terms.seller_fee_rate * 100)}%）</dt>
            <dd>-{yen(fee)}</dd>
          </div>
          <div className="flex gap-2">
            <dt className="w-48">送金手数料</dt>
            <dd>-{yen(terms.transfer_fee)}</dd>
          </div>
          <div className="flex gap-2 font-semibold">
            <dt className="w-48">受取額</dt>
            <dd data-testid="preview-payout">{yen(price - fee - terms.transfer_fee)}</dd>
          </div>
          <div className="flex gap-2 text-gray-500">
            <dt className="w-48">購入者の支払額（手数料 {yen(terms.buyer_fee)} 込み）</dt>
            <dd>{yen(price + terms.buyer_fee)}</dd>
          </div>
        </dl>
      ) : (
        <p className="mt-3 text-red-600">
          {yen(terms.min_price)}以上 {yen(terms.face_price)}以下の整数で入力してください。
        </p>
      )}
      {state && !state.ok && (
        <p role="alert" className="mt-3 text-red-600">
          {resaleErrorMessage(state.error, "list")}
        </p>
      )}
      <button
        type="submit"
        disabled={pending || !valid}
        className="mt-3 rounded bg-blue-600 px-4 py-2 text-white disabled:opacity-50"
      >
        出品する
      </button>
    </form>
  );
}
