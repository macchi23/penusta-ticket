"use client";

import { useState, useTransition } from "react";
import { cancelListing } from "@/lib/actions/resale";
import { resaleErrorMessage } from "@/lib/resale-messages";

export default function CancelListingButton({ ticketId }: { ticketId: number }) {
  const [pending, startTransition] = useTransition();
  const [error, setError] = useState<string | null>(null);

  const onClick = () => {
    if (!window.confirm("出品を取り下げますか？")) return;
    setError(null);
    startTransition(async () => {
      const res = await cancelListing(ticketId);
      if (!res.ok) setError(resaleErrorMessage(res.error, "cancel"));
    });
  };

  return (
    <div className="mt-2">
      {error && (
        <p role="alert" className="mb-2 text-red-600">
          {error}
        </p>
      )}
      <button
        type="button"
        onClick={onClick}
        disabled={pending}
        className="rounded border border-red-600 px-3 py-1 text-red-600 disabled:opacity-50"
      >
        取り下げ
      </button>
    </div>
  );
}
