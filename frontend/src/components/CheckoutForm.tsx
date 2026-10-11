"use client";

import { useActionState } from "react";
import Link from "next/link";
import type { ActionResult, Order } from "@/lib/api";
import { checkout } from "@/lib/actions/checkout";

export default function CheckoutForm({
  matchId,
  orderId,
}: {
  matchId: number;
  orderId: number;
}) {
  const [state, action, pending] = useActionState<ActionResult<Order> | null, FormData>(
    checkout.bind(null, matchId, orderId),
    null,
  );
  const code = state && !state.ok ? state.error.code : null;
  const back = (
    <Link href={`/matches/${matchId}`} className="ml-2 text-blue-600 underline">
      席の選択に戻る
    </Link>
  );

  let message: React.ReactNode = null;
  switch (code) {
    case null:
      break;
    case "cart_expired":
    case "cart_empty":
      message = <>仮押さえの期限が切れました。席を選び直してください。{back}</>;
      break;
    case "not_on_sale":
    case "price_not_found":
      message = <>販売条件が変わりました。カートを確認してください。{back}</>;
      break;
    case "not_eligible":
      message = <>この販売期間は対象の会員のみ購入できます。{back}</>;
      break;
    case "payment_declined":
      message = <>決済が承認されませんでした。もう一度お試しください。</>;
      break;
    case "user_required":
      message = <>ユーザーを選択してください。</>;
      break;
    default:
      message = <>エラーが発生しました。</>;
  }

  return (
    <form action={action} className="mt-4">
      {message && (
        <p role="alert" className="mb-3 text-sm text-red-600">
          {message}
        </p>
      )}
      <button
        type="submit"
        disabled={pending}
        className="rounded bg-blue-600 px-4 py-2 text-white disabled:opacity-50"
      >
        決済する（モック）
      </button>
    </form>
  );
}
