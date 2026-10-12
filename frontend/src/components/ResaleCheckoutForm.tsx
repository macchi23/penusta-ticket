"use client";

import { useActionState, useState, useTransition } from "react";
import Link from "next/link";
import type { ActionResult } from "@/lib/api";
import { checkoutHold, releaseHold } from "@/lib/actions/resale";
import { resaleErrorMessage } from "@/lib/resale-messages";

export default function ResaleCheckoutForm({ matchId, holdId }: { matchId: number; holdId: number }) {
  const [releaseError, setReleaseError] = useState<string | null>(null);
  const [state, action, paying] = useActionState<ActionResult<never> | null, FormData>(
    async () => {
      setReleaseError(null);
      return checkoutHold(holdId);
    },
    null,
  );
  const [releasing, startTransition] = useTransition();

  const cancel = () => {
    setReleaseError(null);
    startTransition(async () => {
      const res = await releaseHold(matchId, holdId);
      // 成功時は redirect されるのでここには来ない
      if (res && !res.ok) setReleaseError(resaleErrorMessage(res.error, "purchase"));
    });
  };

  const message = releaseError ?? (state && !state.ok ? resaleErrorMessage(state.error, "purchase") : null);
  const busy = paying || releasing;

  return (
    <form action={action} className="mt-4">
      {message && (
        <p role="alert" className="mb-3 text-sm text-red-600">
          {message}
          <Link href={`/matches/${matchId}`} className="ml-2 text-blue-600 underline">
            試合ページへ戻る
          </Link>
        </p>
      )}
      <div className="flex items-center gap-3">
        <button
          type="submit"
          disabled={busy}
          className="rounded bg-blue-600 px-4 py-2 text-white disabled:opacity-50"
        >
          購入を確定する（モック）
        </button>
        <button
          type="button"
          onClick={cancel}
          disabled={busy}
          className="rounded border border-red-600 px-4 py-2 text-red-600 disabled:opacity-50"
        >
          取り消す
        </button>
      </div>
    </form>
  );
}
